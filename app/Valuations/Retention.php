<?php

declare(strict_types=1);

namespace App\Valuations;

use App\Acquisition\GhlHandoff;
use App\Acquisition\NurturePlan;
use App\Acquisition\NurtureSlot;
use App\Models\Communication;
use App\WhatsApp\Phone;
use App\WhatsApp\WhatsApp;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Liberu\RealEstate\Parties\Models\Party;

/**
 * Retention of valuation requests, as the privacy policy on clarto.es promises (decisions and reasons:
 * docs/clarto/conservacion-valoraciones.md). Run daily by clarto:valoraciones-conservacion.
 *
 *  - 24 months after the person's LAST CONTACT (their latest request, report opening or message received from their
 *    phone or e-mail) the request is anonymised: personal data, PDFs, /r/{codigo} links, WhatsApp nurture plans, the
 *    valuation messages in the communications log and the WhatsApp orders from GHL go; municipality, type, area,
 *    estimate and dates stay for statistics.
 *  - 12 months after the person's latest commercial consent (a new request with consent renews it) the consent
 *    expires: both channels off, active nurture plans of that request cancelled.
 *  - Never anonymised: the contact became a CLARTO client (same rule as the nurture stop, L-20: party in Liberu, won
 *    opportunity handed off, draft property) or the reference is on legal hold (VALORACIONES_CONSERVACION_BLOQUEO).
 *
 * Returns counts only: never a name, e-mail, phone or reference.
 */
final class Retention
{
    public const DATA_MONTHS = 24;

    public const CONSENT_MONTHS = 12;

    public const REDACTED = '[Eliminado: plazo de conservación cumplido]';

    /** What statistics keep of the dwelling and of each calculation. */
    public const PROPERTY_KEEP = ['municipio', 'provincia', 'tipo', 'superficie_construida_m2', 'superficie_util_m2'];

    public const RESULT_KEEP = ['estado', 'codigo', 'precio_central', 'intervalo', 'eur_m2', 'tipo_precio', 'moneda', 'suficiencia', 'fecha', 'version_calculo', 'version_datos'];

    /** @return array<string, int> */
    public static function run(bool $dryRun = false): array
    {
        $n = array_fill_keys(['revisadas', 'anonimizadas', 'excluidas_cliente', 'excluidas_bloqueo_legal', 'pdf_borrados', 'enlaces_borrados',
            'planes_nutricion_borrados', 'comunicaciones_anonimizadas', 'ordenes_whatsapp_borradas', 'consentimientos_caducados', 'planes_nutricion_cancelados'], 0);
        $dataCutoff = now()->subMonths(self::DATA_MONTHS);
        $consentCutoff = now()->subMonths(self::CONSENT_MONTHS);
        $hold = array_map(fn (string $ref): string => Str::upper(trim($ref)), (array) config('valoraciones.conservacion.bloqueo'));

        // ponytail: every live request in memory to group them by person (phone or e-mail); fine for thousands, a
        // normalised, indexed phone/e-mail column if it reaches the hundreds of thousands.
        $requests = ValuationRequest::query()->whereNull('anonymized_at')->orderBy('id')->get();
        $byKey = [];
        foreach ($requests as $r) {
            foreach (self::keys($r) as $key) {
                $byKey[$key][] = $r;
            }
        }

        foreach ($requests as $r) {
            if ($r->created_at->gte($consentCutoff)) {   // both periods are longer than this
                continue;
            }
            $n['revisadas']++;
            /** @var Collection<int, ValuationRequest> $person */
            $person = collect(self::keys($r))->flatMap(fn (string $key): array => $byKey[$key])->push($r)->unique('id')->values();

            if (self::lastContact($person)->lt($dataCutoff)) {
                if (in_array(Str::upper((string) $r->reference), $hold, true)) {
                    $n['excluidas_bloqueo_legal']++;
                } elseif (self::isClient($r)) {
                    $n['excluidas_cliente']++;
                } else {
                    self::anonymise($r, $dryRun, $n);

                    continue;
                }
            }
            if (self::consentExpired($r, $person, $consentCutoff)) {
                self::expireConsent($r, $dryRun, $n);
            }
        }

        return $n;
    }

    /** @return list<string> «p:+34…» / «e:…» */
    public static function keys(ValuationRequest $r): array
    {
        [$phone, $email] = self::address($r);

        return array_values(array_filter([$phone !== null ? 'p:'.$phone : null, $email !== null ? 'e:'.$email : null]));
    }

    /** @return array{0: ?string, 1: ?string} E.164 phone and lower-case e-mail */
    private static function address(ValuationRequest $r): array
    {
        $email = Str::lower(trim((string) ($r->contact['email'] ?? '')));

        return [Phone::e164((string) ($r->contact['telefono'] ?? '')), $email !== '' ? $email : null];
    }

    /**
     * The person's last contact: their latest request, the latest opening of a report link (people, not previews) and
     * the latest message received from their phone or e-mail. What CLARTO sends never extends it.
     *
     * @param  Collection<int, ValuationRequest>  $person
     */
    public static function lastContact(Collection $person): Carbon
    {
        $phones = $person->map(fn (ValuationRequest $r): ?string => self::address($r)[0])->filter()->unique()->values()->all();
        $emails = $person->map(fn (ValuationRequest $r): ?string => self::address($r)[1])->filter()->unique()->values()->all();
        $dates = [
            ...$person->map(fn (ValuationRequest $r) => $r->created_at)->all(),
            ReportLink::query()->whereIn('valuation_request_id', $person->pluck('id')->all())->max('last_opened_at'),
            $phones === [] && $emails === [] ? null : Communication::query()->where('direction', Communication::IN)
                ->where(fn (Builder $q) => $q->whereIn('phone', $phones)->when($emails !== [], fn (Builder $q) => $q->orWhereIn(DB::raw('lower(email)'), $emails)))
                ->max('created_at'),
        ];

        return collect($dates)->filter()->map(fn ($d): Carbon => Carbon::parse($d))->sortByDesc(fn (Carbon $d): int => $d->getTimestamp())->first();
    }

    /** Same rule as the nurture stop (NurturePlan::isClient, L-20), plus a won opportunity already turned into a property. */
    public static function isClient(ValuationRequest $r): bool
    {
        [$phone, $email] = self::address($r);
        $ghlIds = array_filter(['opportunity_id' => $r->ghl_opportunity_id, 'contact_id' => $r->ghl_contact_id]);

        return $r->property_id !== null
            || ($ghlIds !== [] && GhlHandoff::query()->whereIn('status', ['procesado', 'pendiente'])
                ->where(function (Builder $q) use ($ghlIds): void {
                    foreach ($ghlIds as $column => $id) {
                        $q->orWhere($column, $id);
                    }
                })->exists())
            || ($phone !== null && WhatsApp::partiesWithPhone($r->team_id, $phone)->isNotEmpty())
            || ($email !== null && Party::query()->where('team_id', $r->team_id)->whereRaw('lower(email) = ?', [$email])->exists())
            || (filled($r->ghl_contact_id) && Party::query()->where('team_id', $r->team_id)->where('metadata->ghl_contact_id', $r->ghl_contact_id)->exists());
    }

    /**
     * The person's newest commercial consent (any of their requests with e-mail or WhatsApp «yes») is older than
     * 12 months, and this request still has one on.
     *
     * @param  Collection<int, ValuationRequest>  $person
     */
    private static function consentExpired(ValuationRequest $r, Collection $person, Carbon $cutoff): bool
    {
        $on = fn (ValuationRequest $m): bool => ($m->consent['comercial']['email'] ?? false) === true || ($m->consent['comercial']['whatsapp'] ?? false) === true;
        if (! $on($r)) {
            return false;
        }
        $latest = $person->filter($on)->map(fn (ValuationRequest $m): Carbon => Carbon::parse((string) ($m->consent['comercial']['fecha'] ?? $m->consent['fecha'] ?? $m->created_at)))
            ->sortByDesc(fn (Carbon $d): int => $d->getTimestamp())->first();

        return $latest->lt($cutoff);
    }

    /** @param  array<string, int>  $n */
    private static function expireConsent(ValuationRequest $r, bool $dryRun, array &$n): void
    {
        $plans = NurturePlan::query()->where('valuation_request_id', $r->getKey())->where('estado', 'activo');
        $n['consentimientos_caducados']++;
        $n['planes_nutricion_cancelados'] += (clone $plans)->count();
        if ($dryRun) {
            return;
        }
        $reason = 'Consentimiento comercial caducado (12 meses)';
        DB::transaction(function () use ($r, $plans, $reason): void {
            foreach ($plans->get() as $plan) {
                $plan->fill(['estado' => 'cancelado', 'motivo' => $reason, 'ended_at' => now()])->save();
                $plan->slots()->where('estado', 'programado')->update(['estado' => 'cancelado', 'motivo' => $reason, 'updated_at' => now()]);
            }
            $consent = (array) $r->consent;
            $consent['comercial'] = ['email' => false, 'whatsapp' => false, 'caducado' => now()->toIso8601String()] + (array) ($consent['comercial'] ?? []);
            $r->forceFill(['consent' => $consent])->save();
        });
    }

    /** @param  array<string, int>  $n */
    private static function anonymise(ValuationRequest $r, bool $dryRun, array &$n): void
    {
        [$phone, $email] = self::address($r);
        $disk = Storage::disk(ValuationEngine::DISK);
        $dir = 'valoraciones/'.($r->reference ?: $r->request_id);
        $pdfs = collect($disk->allFiles($dir))->merge($r->runs->pluck('pdf_path')->filter())->unique()->filter(fn (string $p): bool => $disk->exists($p))->values();
        $links = ReportLink::query()->where('valuation_request_id', $r->getKey());
        $plans = NurturePlan::query()->where(fn (Builder $q) => $q->where('valuation_request_id', $r->getKey())
            ->when($phone !== null, fn (Builder $q) => $q->orWhere('phone', $phone))
            ->when(filled($r->ghl_contact_id), fn (Builder $q) => $q->orWhere('contact_id', $r->ghl_contact_id)));
        // Valuation messages (delivery, nurture) and anything else to or from this address that is not a contact's.
        $messages = Communication::query()->where('body', '!=', self::REDACTED)->where(fn (Builder $q) => $q
            ->where(fn (Builder $q) => $q->where('related_type', $r->getMorphClass())->where('related_id', (string) $r->getKey()))
            ->when($phone !== null || $email !== null, fn (Builder $q) => $q->orWhere(fn (Builder $q) => $q->whereNull('party_id')
                ->where(fn (Builder $q) => $q->when($phone !== null, fn (Builder $q) => $q->orWhere('phone', $phone))
                    ->when($email !== null, fn (Builder $q) => $q->orWhereRaw('lower(email) = ?', [$email]))))));
        $orders = DB::table('clarto_ghl_whatsapp_orders')->where(fn ($q) => $q->when($phone !== null, fn ($q) => $q->orWhere('phone', $phone))
            ->when(filled($r->ghl_contact_id), fn ($q) => $q->orWhere('contact_id', $r->ghl_contact_id)));

        $n['anonimizadas']++;
        $n['pdf_borrados'] += $pdfs->count();
        $n['enlaces_borrados'] += (clone $links)->count();
        $n['planes_nutricion_borrados'] += (clone $plans)->count();
        $n['comunicaciones_anonimizadas'] += (clone $messages)->count();
        $n['ordenes_whatsapp_borradas'] += $phone === null && blank($r->ghl_contact_id) ? 0 : (clone $orders)->count();
        if ($dryRun) {
            return;
        }

        DB::transaction(function () use ($r, $links, $plans, $messages, $orders, $phone): void {
            $links->delete();
            NurtureSlot::query()->whereIn('plan_id', (clone $plans)->pluck('id'))->delete();
            $plans->delete();
            $messages->update(['phone' => null, 'email' => null, 'subject' => null, 'body' => self::REDACTED, 'error' => null, 'candidates' => null, 'updated_at' => now()]);
            if ($phone !== null || filled($r->ghl_contact_id)) {
                $orders->delete();
            }
            foreach ($r->runs as $run) {
                $result = $run->result === null ? null
                    : array_intersect_key((array) $run->result, array_flip(self::RESULT_KEEP)) + ['superficie_m2' => $run->result['calculo']['superficie_m2'] ?? null];
                $run->forceFill(['result' => $result, 'message' => null, 'summary' => null, 'provider_errors' => null, 'pdf_path' => null, 'pdf_pages' => null])->save();
            }
            $property = array_intersect_key((array) $r->property, array_flip(self::PROPERTY_KEEP));
            $property['municipio'] = $r->catastro['municipio'] ?? $property['municipio'] ?? null;
            $r->forceFill([
                'property' => $property, 'contact' => [], 'consent' => null, 'attribution' => null, 'catastro' => null, 'client_ip' => null,
                'ghl_contact_id' => null, 'ghl_opportunity_id' => null, 'ghl_error' => null, 'last_error' => null, 'delivery' => null,
                'property_key' => null, 'report_links_revoked_at' => now(), 'anonymized_at' => now(),
            ])->save();
        });
        // Files after the commit: a failed transaction leaves them (and the request) as they were.
        $disk->delete($pdfs->all());
        $disk->deleteDirectory($dir);
    }
}
