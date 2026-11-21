<?php

declare(strict_types=1);

namespace App\Acquisition;

use App\Communications\Communications;
use App\Models\Communication;
use App\Valuations\ValuationRequest;
use App\WhatsApp\Phone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\RealEstate\Parties\Models\Party;
use Throwable;

/**
 * WhatsApp between GoHighLevel and the company line (WAHA), GHL strategy §9 (L-4, L-5):
 *
 * - order(): a GHL workflow asks Liberu to send an appointment WhatsApp (A3–A5; the nurture ones are NurturePlan). GHL writes the
 *   text; Liberu re-reads the contact from GHL (the webhook body is not trusted), refuses a DND/«baja-whatsapp»/local
 *   opt-out, sends once per «clave», keeps it queued while the line is not
 *   linked until «caduca», and tags «whatsapp-error» in GHL if it could not go out.
 * - inbound(): a WhatsApp received from a valuation contact becomes a note in GHL plus the tag «respondio-whatsapp»
 *   (removed and added again so the workflow fires), grouped every 10 minutes; «BAJA»/«STOP»/«no me escriban» is an
 *   opt-out instead (DND WhatsApp, «baja-whatsapp», consent «Revocado», local block, one confirmation).
 */
final class GhlWhatsApp extends Model
{
    public const TYPES = ['a3', 'a4', 'a5'];   // W2 withdrawn (B.3 v2): nurture WhatsApp are planned by Liberu

    public const OPT_OUT_REPLY = 'Hecho. No le enviaremos más mensajes por WhatsApp. CLARTO';

    protected $table = 'clarto_ghl_whatsapp_orders';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['caduca' => 'datetime'];
    }

    /**
     * @param  array{contact_id: string, tipo: string, texto: string, clave: string, caduca?: ?string}  $o
     * @return array{estado: string, motivo: ?string, http: int}
     */
    public static function order(array $o, ?GhlClient $ghl = null): array
    {
        $ghl ??= new GhlClient;

        return Cache::lock('ghl-whatsapp:'.$o['clave'], 30)->block(10, function () use ($o, $ghl): array {
            $order = self::query()->firstWhere('clave', $o['clave']);
            if ($order !== null && $order->estado !== 'en_cola') {
                return ['estado' => $order->estado, 'motivo' => $order->motivo, 'http' => 200];   // retried webhook: same answer
            }
            $contact = $ghl->contact($o['contact_id']);
            if ($contact === [] || (string) ($contact['locationId'] ?? '') !== (string) config('ghl.location_id')) {
                return ['estado' => 'rechazado', 'motivo' => 'El contacto no es de la subcuenta de CLARTO', 'http' => 422];
            }
            $order ??= new self(['clave' => $o['clave'], 'contact_id' => $o['contact_id'], 'tipo' => $o['tipo'], 'texto' => $o['texto'],
                'caduca' => filled($o['caduca'] ?? null) ? $o['caduca'] : now()->addDay()]);
            $order->phone = Phone::e164((string) ($contact['phone'] ?? ''));
            $refusal = self::refusal($order, $contact, $ghl);
            if ($refusal !== null) {
                $order->fill(['estado' => 'rechazado', 'motivo' => $refusal])->save();

                return ['estado' => 'rechazado', 'motivo' => $refusal, 'http' => 200];
            }
            $order->send($contact, $ghl);

            return ['estado' => $order->estado, 'motivo' => $order->motivo, 'http' => 200];
        });
    }

    /** Scheduled (clarto:valoraciones-reenviar): queued orders are retried until they expire. */
    public static function retryQueued(?GhlClient $ghl = null): int
    {
        $ghl ??= new GhlClient;
        $n = 0;
        foreach (self::query()->where('estado', 'en_cola')->orderBy('id')->limit(200)->get() as $order) {
            try {
                Cache::lock('ghl-whatsapp:'.$order->clave, 30)->block(10, function () use ($order, $ghl): void {
                    $order->refresh();
                    if ($order->estado !== 'en_cola') {
                        return;
                    }
                    if ($order->caduca !== null && $order->caduca->isPast()) {
                        $order->fill(['estado' => 'error', 'motivo' => 'Caducado sin poder enviarse ('.$order->motivo.')'])->save();
                        $ghl->addTags($order->contact_id, ['whatsapp-error']);
                        $ghl->note($order->contact_id, 'WhatsApp '.strtoupper($order->tipo).' no enviado: '.$order->motivo);

                        return;
                    }
                    $contact = $ghl->contact($order->contact_id);
                    $refusal = self::refusal($order, $contact, $ghl);
                    $refusal === null ? $order->send($contact, $ghl) : $order->fill(['estado' => 'rechazado', 'motivo' => $refusal])->save();
                });
                $n++;
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $n;
    }

    /** @param array<string, mixed> $contact */
    private static function refusal(self $order, array $contact, GhlClient $ghl): ?string
    {
        $tags = array_map('strval', (array) ($contact['tags'] ?? []));

        return match (true) {
            $order->phone === null => 'El contacto no tiene un móvil válido',
            ($contact['dnd'] ?? false) === true || ($contact['dndSettings']['WhatsApp']['status'] ?? null) === 'active' || in_array('baja-whatsapp', $tags, true)
                || self::optedOut(self::teamId(), $order->phone) => 'baja',
            default => null,
        };
    }

    /** @param array<string, mixed> $contact */
    private function send(array $contact, GhlClient $ghl): void
    {
        $teamId = self::teamId();
        $partyId = Communications::partyFor($teamId, $this->phone, $contact['email'] ?? null);
        $this->intentos++;
        try {
            $c = Communications::send($teamId, $partyId ? Party::query()->find($partyId) : null, 'whatsapp', (string) $this->phone, $this->texto, [
                'key' => 'ghl:'.$this->clave, 'source' => 'ghl', 'purpose' => 'servicio',
            ]);
        } catch (Throwable $e) {
            $this->fill(['estado' => 'rechazado', 'motivo' => mb_strimwidth($e->getMessage(), 0, 250, '…')])->save();   // invalid text: not retried

            return;
        }
        $this->communication_id = $c->getKey();
        if ($c->status === 'enviado') {
            $this->fill(['estado' => 'enviado', 'motivo' => null])->save();
            try {
                $ghl->note($this->contact_id, 'WhatsApp enviado ('.strtoupper($this->tipo)."): {$this->texto}");
            } catch (Throwable $e) {
                report($e);
            }

            return;
        }
        $this->fill(['estado' => 'en_cola', 'motivo' => mb_strimwidth((string) ($c->error ?: 'Línea de WhatsApp no disponible'), 0, 250, '…')])->save();
    }

    public static function optedOut(int|string $teamId, ?string $phone): bool
    {
        return $phone !== null && DB::table('clarto_whatsapp_optouts')->where('team_id', $teamId)->where('phone', $phone)->exists();
    }

    /** Communication created hook (ClartoValuationsServiceProvider): only incoming WhatsApp of valuation contacts. */
    public static function inbound(Communication $c): void
    {
        if ($c->channel !== 'whatsapp' || $c->direction !== Communication::IN || $c->phone === null) {
            return;
        }
        // Colaboradores never reach GoHighLevel (owner's decision): their replies stay in the SaaS.
        if (\App\Collaborators\Collaborator::ownsAddress($c->team_id, $c->phone)) {
            return;
        }
        // L-20: any WhatsApp received stops the WhatsApp nurture of that number at once (GHL is told below).
        NurturePlan::cancelFor(null, (string) $c->phone, self::isOptOut((string) $c->body) ? 'Baja de WhatsApp' : 'Ha respondido por WhatsApp');
        if (blank(config('ghl.token'))) {
            return;
        }
        // Owner's rule: collaborators and their conversations stay in the panel, never in GHL (even if the number is a GHL contact).
        if (class_exists(\App\Collaborators\Collaborator::class)
            && \App\Collaborators\Collaborator::query()->where('team_id', $c->team_id)->where('whatsapp', $c->phone)->exists()) {
            return;
        }
        $contactId = self::ghlContactFor((int) $c->team_id, (string) $c->phone, $c->party_id);
        if ($contactId === null) {
            return;
        }
        $teamId = (int) $c->team_id;
        $phone = (string) $c->phone;
        if (self::isOptOut((string) $c->body)) {
            dispatch(fn () => self::optOut($teamId, $phone, $contactId))->afterCommit();

            return;
        }
        if (self::isLater((string) $c->body)) {   // L-21: «MÁS ADELANTE» → WF-12 (long-term follow-up)
            dispatch(fn () => (new GhlClient)->addTags($contactId, ['retomar-mas-adelante']))->afterCommit();
        }
        // The first message goes at once (it stops the nurture); the next ones within 10 minutes go together.
        if (Cache::add('ghl-wa-ventana:'.$contactId, true, 600)) {
            dispatch(fn () => self::forwardReplies($teamId, $phone, $contactId))->afterCommit();
            dispatch(fn () => self::forwardReplies($teamId, $phone, $contactId))->afterCommit()->delay(now()->addMinutes(10));
        }
    }

    public static function isOptOut(string $text): bool
    {
        $t = trim((string) preg_replace('/[^a-z ]+/', ' ', Str::lower(Str::ascii($text))));

        return in_array(preg_replace('/\s+/', ' ', $t), ['baja', 'stop', 'no me escriban', 'darme de baja', 'de baja'], true);
    }

    public static function isLater(string $text): bool
    {
        return str_contains(trim((string) preg_replace('/[^a-z ]+/', ' ', Str::lower(Str::ascii($text)))), 'mas adelante');
    }

    /** Messages received from this phone since the last forwarded one → one note + tag toggle in GHL. */
    public static function forwardReplies(int $teamId, string $phone, string $contactId, ?GhlClient $ghl = null): void
    {
        $ghl ??= new GhlClient;
        Cache::lock('ghl-wa-reenvio:'.$contactId, 60)->block(20, function () use ($teamId, $phone, $contactId, $ghl): void {
            $markerKey = 'ghl-wa-ultimo:'.$contactId;
            $messages = Communication::query()->where('team_id', $teamId)->where('channel', 'whatsapp')->where('direction', Communication::IN)
                ->where('phone', $phone)->where('id', '>', (int) Cache::get($markerKey, 0))->where('created_at', '>=', now()->subDays(2))->orderBy('id')->get();
            $messages = $messages->reject(fn (Communication $m): bool => self::isOptOut((string) $m->body));
            if ($messages->isEmpty()) {
                return;
            }
            $ghl->note($contactId, "WhatsApp recibido de {$phone}:\n".$messages->map(fn (Communication $m): string => '· '.$m->created_at?->timezone(config('app.timezone'))->format('d/m H:i').' '.$m->body)->implode("\n"));
            $ghl->removeTags($contactId, ['respondio-whatsapp']);
            $ghl->addTags($contactId, ['respondio-whatsapp']);
            Cache::put($markerKey, (int) $messages->last()->getKey(), now()->addDays(30));
        });
    }

    /** «BAJA» by WhatsApp: local block, GHL DND + tag + consent «Revocado» + opt-out fields, one confirmation. */
    public static function optOut(int $teamId, string $phone, string $contactId, ?GhlClient $ghl = null): void
    {
        $ghl ??= new GhlClient;
        DB::table('clarto_whatsapp_optouts')->insertOrIgnore(['team_id' => $teamId, 'phone' => $phone, 'source' => 'whatsapp', 'created_at' => now()]);
        $now = now()->toIso8601String();
        $ghl->call('PUT', '/contacts/'.rawurlencode($contactId), [
            'dndSettings' => ['WhatsApp' => ['status' => 'active', 'message' => 'BAJA por WhatsApp', 'code' => '']],
            'customFields' => $ghl->fieldsPayload('contact', [
                'consentimiento_comercial_whatsapp' => 'Revocado', 'consentimiento_comercial_whatsapp_fecha' => $now,
                'baja_fecha' => $now, 'baja_canal' => 'WhatsApp', 'baja_origen' => 'WhatsApp BAJA',
            ]),
        ]);
        $ghl->addTags($contactId, ['baja-whatsapp']);
        $ghl->note($contactId, "Baja de WhatsApp el {$now} (respondió «BAJA» desde {$phone}).");
        try {
            $partyId = Communications::partyFor($teamId, $phone);
            Communications::send($teamId, $partyId ? Party::query()->find($partyId) : null, 'whatsapp', $phone, self::OPT_OUT_REPLY,
                ['key' => 'baja-whatsapp:'.$teamId.':'.$phone, 'source' => 'ghl']);
        } catch (Throwable $e) {
            report($e);   // the opt-out itself is done
        }
    }

    /** The GHL contact of a phone: a valuation request of the last 24 months, else the owner linked to a GHL contact. */
    public static function ghlContactFor(int $teamId, string $phone, ?int $partyId): ?string
    {
        $fromRequest = ValuationRequest::query()->where('team_id', $teamId)->whereNotNull('ghl_contact_id')->where('contact->telefono', $phone)
            ->where('created_at', '>=', now()->subMonths(24))->latest('id')->value('ghl_contact_id');
        if ($fromRequest !== null) {
            return (string) $fromRequest;
        }
        $metadata = $partyId ? (array) (Party::query()->whereKey($partyId)->value('metadata') ?? []) : [];
        $metadata = is_string($metadata) ? (array) json_decode($metadata, true) : $metadata;

        return filled($metadata['ghl_contact_id'] ?? null) ? (string) $metadata['ghl_contact_id'] : null;
    }

    private static function teamId(): int
    {
        return (int) Team::query()->oldest()->value('id');   // same team as the valuations API and GhlHandoff
    }
}
