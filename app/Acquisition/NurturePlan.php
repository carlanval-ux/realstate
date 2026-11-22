<?php

declare(strict_types=1);

namespace App\Acquisition;

use App\Communications\Communications;
use App\Models\Communication;
use App\Models\WhatsAppLine;
use App\Modules\ClartoModules;
use App\Valuations\ValuationRequest;
use App\WhatsApp\Phone;
use App\WhatsApp\WhatsApp;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Liberu\RealEstate\Parties\Models\Party;
use Throwable;

/**
 * WhatsApp nurture scheduled and sent by Liberu through WAHA (GHL strategy annex B.3 v2 §5, L-18…L-21). GHL keeps the
 * e-mails and the common clock: its WF-03 v2 calls POST /api/v1/ghl/nutricion with «inicio» (plan), «correo» after each
 * e-mail (the WhatsApp that follows is planned 2 or 3 working days later, 17:30–18:30 Europe/Madrid, random minute) and
 * «parada» (WF-13). Before EVERY send, ten checks (§5.2): line, working day and window, idempotency, no other message
 * today, consent and no opt-out, no WhatsApp received, not a CLARTO client, and the GHL contact (tags, DND, consent).
 * A missed window is skipped, never sent late. Stops Liberu sees itself (a WhatsApp received, «BAJA», the phone becoming
 * a client) cancel the plan at once and are told to GHL.
 */
final class NurturePlan extends Model
{
    /** rama => e-mail code => [WhatsApp code, working days after the e-mail] */
    public const SLOTS = [
        'activa' => ['c2' => ['wa1', 2], 'c3' => ['wa2', 2], 'c4' => ['wa3', 2], 'c5' => ['wa4', 2]],
        'templada' => ['c2' => ['wa_t1', 3], 'c4' => ['wa_t2', 2]],
        'fria' => ['c3' => ['wa_f1', 2]],
    ];

    /** GHL tags that stop the sequence (B.3 v2 §5.2 rule 9). */
    public const STOP_TAGS = ['respondio', 'cita-agendada', 'visita-realizada', 'propietario-cliente', 'cliente-clarto', 'seguimiento-largo', 'baja-whatsapp', 'baja-email', 'no-contactar'];

    private const BAJA = "\nSi no desea recibir más mensajes, responda BAJA.";

    /**
     * Texts of annex B.3 v2 §4, approved by the owner on 21/11/2026: automated, so «Le escribimos desde CLARTO», first
     * person plural, no advisor name (D7: no visit duration; D3: the visit is free). Config
     * valoraciones.nutricion_whatsapp.textos overrides by code.
     */
    public const TEXTS = [
        'wa1' => "Hola, {nombre}. Le escribimos desde CLARTO. Un apunte sobre la valoración de su vivienda en {zona}: el intervalo es de precio de cierre, lo que se suele firmar en la notaría, no el precio de los anuncios, que suele ser más alto.\n¿Qué le ha parecido la cifra: esperaba más, menos o algo parecido? Con su respuesta le indicamos qué podría moverla en su caso.".self::BAJA,
        'wa2' => "Hola, {nombre}. Le escribimos desde CLARTO. Para decidir el precio, piense en lo que le quedará neto. Al vender suelen aparecer la plusvalía municipal (la paga quien vende y, si vende por menos de lo que pagó, puede no haber), el IRPF si hay ganancia y, si tiene hipoteca, su cancelación en la misma firma.\n¿Quiere que nuestro equipo de abogados y gestores le diga qué gastos tendría en su caso? Responda SÍ y le escribimos.".self::BAJA,
        'wa3' => "Hola, {nombre}. Le escribimos desde CLARTO. Así vendemos una vivienda: vídeos profesionales, una estrategia de precio y difusión pensada para ella, y abogados, asesores y gestores en cada paso, de los papeles a la firma. Todo empieza con una visita gratuita para fijar el precio de salida.\nSi le encaja, elija día aquí: {enlace_cita}".self::BAJA,
        'wa4' => "Hola, {nombre}. Le escribimos desde CLARTO. No queremos insistirle: si ahora no es el momento de vender su vivienda en {zona}, lo entendemos. Los precios se mueven, así que podemos guardar su valoración y escribirle más adelante para actualizarla.\n¿Qué prefiere? Responda VISITA si quiere que la veamos, o MÁS ADELANTE.".self::BAJA,
        'wa_t1' => "Hola, {nombre}. Le escribimos desde CLARTO. Como la venta de su vivienda en {zona} no es inmediata, tiene una ventaja: tiempo para saber cuánto le quedará neto. La plusvalía municipal (la paga quien vende), el IRPF si hay ganancia o la cancelación de una hipoteca se pueden calcular antes, y en algunos casos la ganancia queda exenta.\n¿Quiere que nuestro equipo de abogados y gestores le diga qué le aplicaría? Responda SÍ.".self::BAJA,
        'wa_t2' => "Hola, {nombre}. Le escribimos desde CLARTO. Para no escribirle antes de tiempo: ¿en qué mes cree que podría plantearse vender su vivienda en {zona}? Le avisaremos unas semanas antes para actualizar la valoración y preparar los papeles con calma.\nResponda con el mes (por ejemplo, «marzo») o «no lo sé».".self::BAJA,
        'wa_f1' => "Hola, {nombre}. Le escribimos desde CLARTO. Una nota sobre la valoración de su vivienda en {zona}: es una foto de hoy. Los precios se mueven, así que si algún día piensa vender, conviene actualizarla; puede pedirla de nuevo en clarto.es cuando quiera.\n¿Le surgió alguna duda al leer el informe? Escríbanos y se la resolvemos.".self::BAJA,
    ];

    protected $table = 'clarto_whatsapp_nurture_plans';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['eventos' => 'array', 'started_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    /** @return HasMany<NurtureSlot, $this> */
    public function slots(): HasMany
    {
        return $this->hasMany(NurtureSlot::class, 'plan_id')->orderBy('send_after');
    }

    /** @return BelongsTo<ValuationRequest, $this> */
    public function valuationRequest(): BelongsTo
    {
        return $this->belongsTo(ValuationRequest::class);
    }

    /**
     * Webhook from GHL (L-18): inicio | correo | parada.
     *
     * @param  array{contact_id: string, evento: string, codigo?: ?string, estado?: ?string, rama?: ?string, asesor_nombre?: ?string}  $o
     * @return array{estado: string, motivo: ?string, http: int, fecha?: string}
     */
    public static function event(array $o): array
    {
        $contactId = $o['contact_id'];

        return Cache::lock('nutricion-wa:'.$contactId, 30)->block(10, function () use ($o, $contactId): array {
            $plan = self::query()->where('contact_id', $contactId)->where('estado', 'activo')->latest('id')->first();
            if ($o['evento'] === 'parada') {
                $n = self::cancelFor($contactId, null, 'Parada en GHL');

                return ['estado' => $n > 0 ? 'cancelado' : 'ignorado', 'motivo' => $n > 0 ? null : 'Sin plan activo', 'http' => 200];
            }
            if ($o['evento'] === 'inicio') {
                if ($plan !== null) {
                    return ['estado' => 'ignorado', 'motivo' => 'Ya hay un plan activo (segunda vivienda: no se reinicia)', 'http' => 200];
                }
                $request = ValuationRequest::query()->where('ghl_contact_id', $contactId)->latest('id')->first();
                $phone = Phone::e164((string) ($request?->contact['telefono'] ?? ''));
                if ($request === null || $phone === null) {
                    return ['estado' => 'rechazado', 'motivo' => 'El contacto no tiene una solicitud de valoración con móvil en Liberu', 'http' => 422];
                }
                self::query()->create([
                    'team_id' => $request->team_id, 'contact_id' => $contactId, 'valuation_request_id' => $request->getKey(), 'phone' => $phone,
                    'rama' => $o['rama'], 'asesor_nombre' => filled($o['asesor_nombre'] ?? null) ? mb_substr(trim((string) $o['asesor_nombre']), 0, 80) : null,
                    'estado' => 'activo', 'started_at' => now(), 'eventos' => [['evento' => 'inicio', 'rama' => $o['rama'], 'fecha' => now()->toIso8601String()]],
                ]);

                return ['estado' => 'programado', 'motivo' => null, 'http' => 200];
            }
            // correo
            if ($plan === null) {
                return ['estado' => 'ignorado', 'motivo' => 'Sin plan activo', 'http' => 200];
            }
            $codigo = (string) $o['codigo'];
            if (! collect((array) $plan->eventos)->contains(fn (array $e): bool => ($e['codigo'] ?? null) === $codigo)) {
                $plan->eventos = [...(array) $plan->eventos, ['evento' => 'correo', 'codigo' => $codigo, 'estado' => $o['estado'] ?? 'enviado', 'fecha' => now()->toIso8601String()]];
                $plan->save();
            }
            [$wa, $days] = self::SLOTS[$plan->rama][$codigo] ?? [null, 0];
            if ($wa === null) {
                return ['estado' => 'ignorado', 'motivo' => "Sin WhatsApp tras {$codigo} en la rama {$plan->rama}", 'http' => 200];
            }
            $when = self::isTestPhone($plan->phone)   // test mode: one «working day» = N minutes, no window
                ? ['send_after' => now()->addMinutes($days * max(1, (int) config('valoraciones.nutricion_whatsapp.prueba_minutos', 2))), 'window_end' => now()->addMinutes($days * max(1, (int) config('valoraciones.nutricion_whatsapp.prueba_minutos', 2)) + 30)]
                : self::window(self::workingDaysAfter(self::today(), $days));
            $slot = $plan->slots()->where('codigo', $wa)->first() ?? $plan->slots()->create(['codigo' => $wa, 'evento' => $codigo, 'estado' => 'programado'] + $when);

            return ['estado' => 'programado', 'motivo' => null, 'http' => 200, 'fecha' => $slot->send_after->timezone(self::tz())->format('Y-m-d H:i')];
        });
    }

    /** Cancels the active plans of a GHL contact and/or phone. Returns how many. Tags «cliente-clarto» when asked (L-20). */
    public static function cancelFor(?string $contactId, ?string $phone, string $reason, bool $tagClient = false): int
    {
        if ($contactId === null && $phone === null) {
            return 0;
        }
        $plans = self::query()->where('estado', 'activo')->where(fn ($q) => $q
            ->when($contactId !== null, fn ($q) => $q->orWhere('contact_id', $contactId))
            ->when($phone !== null, fn ($q) => $q->orWhere('phone', $phone)))->get();
        foreach ($plans as $plan) {
            $plan->fill(['estado' => 'cancelado', 'motivo' => $reason, 'ended_at' => now()])->save();
            $plan->slots()->where('estado', 'programado')->update(['estado' => 'cancelado', 'motivo' => $reason, 'updated_at' => now()]);
            if ($tagClient) {
                self::tagClient($plan->contact_id);
            }
        }

        return $plans->count();
    }

    /**
     * L-20 (B.3 v2 §5.3): a party (client, owner…) saved in Liberu, or a won opportunity handed off, whose phone, e-mail or
     * GHL contact belongs to a valuation contact: WhatsApp nurture cancelled and «cliente-clarto» in GHL, which stops the
     * e-mails too (WF-13) — also when there was no WhatsApp plan (e-mail-only nurture).
     */
    public static function clientLinked(?string $contactId, ?string $phone, ?string $email): void
    {
        $phone = Phone::e164((string) $phone);
        $email = filled($email) ? Str::lower(trim((string) $email)) : null;
        if ($phone === null && $email === null && blank($contactId)) {
            return;
        }
        $ids = ValuationRequest::query()->whereNotNull('ghl_contact_id')->where('created_at', '>=', now()->subMonths(24))
            ->where(fn ($q) => $q->when($phone !== null, fn ($q) => $q->orWhere('contact->telefono', $phone))->when($email !== null, fn ($q) => $q->orWhere('contact->email', $email))
                ->when(filled($contactId), fn ($q) => $q->orWhere('ghl_contact_id', $contactId)))
            ->distinct()->pluck('ghl_contact_id')->all();
        foreach (array_unique(array_filter([$contactId, ...$ids])) as $id) {
            self::cancelFor((string) $id, null, 'Cliente de CLARTO en Liberu');
            self::tagClient((string) $id);
        }
        if ($phone !== null) {
            self::cancelFor(null, $phone, 'Cliente de CLARTO en Liberu');
        }
    }

    public static function partySaved(Party $party): void
    {
        // Colaboradores never reach GoHighLevel (owner's decision), not even as «cliente-clarto».
        if (\App\Collaborators\Collaborator::ownsAddress($party->team_id, $party->phone, $party->email)) {
            return;
        }
        self::clientLinked(((array) $party->metadata)['ghl_contact_id'] ?? null, $party->phone, $party->email);
    }

    /** «cliente-clarto» + a note in GHL, once per contact (a party is saved often). */
    private static function tagClient(string $contactId): void
    {
        if (blank(config('ghl.token')) || ! Cache::add('cliente-clarto:'.$contactId, true, now()->addDays(30))) {
            return;
        }
        try {
            $ghl = new GhlClient;
            $ghl->addTags($contactId, ['cliente-clarto']);
            $ghl->note($contactId, 'Cliente de CLARTO en Liberu: la nutrición (correo y WhatsApp) se detiene.');
        } catch (Throwable $e) {
            Cache::forget('cliente-clarto:'.$contactId);   // retried on the next save
            report($e);
        }
    }

    /** Scheduled every minute in the window: at most ONE send per run (human pace on WhatsApp Web) and max_por_hora per line. */
    public static function runDue(?GhlClient $ghl = null): array
    {
        $ghl ??= new GhlClient;
        $done = ['enviado' => 0, 'omitido' => 0, 'cancelado' => 0, 'esperando' => 0];
        $sent = false;
        foreach (NurtureSlot::query()->where('estado', 'programado')->where('send_after', '<=', now())->orderBy('send_after')->limit(100)->get() as $slot) {
            Cache::lock('nutricion-wa-slot:'.$slot->getKey(), 120)->block(10, function () use ($slot, $ghl, &$done, &$sent): void {
                $slot->refresh();
                if ($slot->estado !== 'programado') {
                    return;
                }
                if (now()->gt($slot->window_end)) {   // a missed window is skipped, never sent late
                    $slot->fill(['estado' => 'omitido', 'motivo' => $slot->motivo ?: 'Ventana perdida'])->save();
                    $done['omitido']++;
                    if (str_starts_with((string) $slot->motivo, 'Línea')) {
                        rescue(fn () => $ghl->addTags($slot->plan->contact_id, ['whatsapp-error']), report: true);
                    }

                    return;
                }
                $recent = NurtureSlot::query()->where('estado', 'enviado')->where('sent_at', '>=', now()->subHour())->count();
                if ($sent || $recent >= max(1, (int) config('valoraciones.nutricion_whatsapp.max_por_hora', 30))) {
                    $done['esperando']++;

                    return;
                }
                $result = self::attempt($slot, $ghl);
                $done[$result]++;
                $sent = $sent || $result === 'enviado';
            });
        }

        return $done;
    }

    /** The ten checks of §5.2, then the send. Returns enviado | omitido | cancelado | esperando. */
    private static function attempt(NurtureSlot $slot, GhlClient $ghl): string
    {
        $plan = $slot->plan;
        $request = $plan->valuationRequest;
        $wait = function (string $why) use ($slot): string {
            $slot->forceFill(['motivo' => $why])->save();   // retried while the window is open

            return 'esperando';
        };
        $skip = function (string $why) use ($slot): string {
            $slot->fill(['estado' => 'omitido', 'motivo' => $why])->save();

            return 'omitido';
        };
        $cancel = function (string $why, bool $client = false) use ($plan): string {
            self::cancelFor($plan->contact_id, null, $why, $client);

            return 'cancelado';
        };
        if ($plan->estado !== 'activo' || $request === null) {
            $slot->fill(['estado' => 'cancelado', 'motivo' => $plan->motivo ?: 'Plan no activo'])->save();

            return 'cancelado';
        }
        // Texts waiting for CLARTO's legal review (tax content: WA2, WA-T1) are never sent until cleared in config.
        if (in_array($slot->codigo, (array) config('valoraciones.nutricion_whatsapp.revision_legal'), true)) {
            return $skip('Pendiente de revisión legal');
        }
        // 1. Module and line.
        if (ClartoModules::refusal(ClartoModules::WHATSAPP) !== null) {
            return $wait('Línea: módulo WhatsApp desactivado');
        }
        $line = WhatsAppLine::forTeam($plan->team_id);
        if (! $line->connected() && ! rescue(fn () => $line->refreshFromWaha()->connected(), false, false)) {
            return $wait('Línea WhatsApp no conectada');
        }
        $key = 'nutricion:'.$plan->contact_id.':'.$plan->getKey().':'.$slot->codigo;
        if (! self::isTestPhone($plan->phone)) {   // test mode (B.3 v2 §8): calendar and pace rules relaxed, every stop kept
            // 2. Working day and window.
            if (! self::isWorkingDay(self::today())) {
                return $skip('Día no laborable');
            }
            // 4. Nothing else to this number today (delivery, appointment, advisor) and no nurture e-mail today.
            $today = now()->timezone(self::tz())->startOfDay()->timezone(config('app.timezone'));
            if (Communication::query()->where('phone', $plan->phone)->where('direction', Communication::OUT)->where('created_at', '>=', $today)->where('idempotency_key', '!=', $key)->exists()
                || collect((array) $plan->eventos)->contains(fn (array $e): bool => ($e['evento'] ?? '') === 'correo' && ($e['estado'] ?? '') === 'enviado' && Carbon::parse($e['fecha'])->gte($today))) {
                return $skip('Ya hay otro mensaje hoy a este contacto');
            }
            // At most three automatic messages per calendar week (Monday–Sunday), both channels together (B.3 v2 §1).
            $week = now()->timezone(self::tz())->startOfWeek()->timezone(config('app.timezone'));
            $thisWeek = Communication::query()->where('phone', $plan->phone)->where('direction', Communication::OUT)->whereIn('source', ['nutricion', 'valoraciones'])->where('created_at', '>=', $week)->count()
                + collect((array) $plan->eventos)->filter(fn (array $e): bool => ($e['evento'] ?? '') === 'correo' && ($e['estado'] ?? '') === 'enviado' && Carbon::parse($e['fecha'])->gte($week))->count();
            if ($thisWeek >= 3) {
                return $skip('Ya hay tres mensajes esta semana');
            }
        }
        // 5. Consent and opt-out.
        if (($request->consent['comercial']['whatsapp'] ?? false) !== true) {
            return $cancel('Sin consentimiento comercial por WhatsApp');
        }
        $given = $request->consent['comercial']['fecha'] ?? $request->consent['fecha'] ?? null;
        if ($given !== null && Carbon::parse((string) $given)->lt(now()->subMonths(12))) {   // commercial consent lasts 12 months (privacy policy)
            return $cancel('Consentimiento comercial caducado (12 meses)');
        }
        if (GhlWhatsApp::optedOut($plan->team_id, $plan->phone)) {
            return $cancel('Baja de WhatsApp');
        }
        // 6. Any WhatsApp received since the plan started.
        if (Communication::query()->where('team_id', $plan->team_id)->where('channel', 'whatsapp')->where('direction', Communication::IN)
            ->where('phone', $plan->phone)->where('created_at', '>=', $plan->started_at)->exists()) {
            return $cancel('Ha respondido por WhatsApp');
        }
        // 7. Already a CLARTO client in Liberu.
        if (self::isClient($plan, $request)) {
            return $cancel('Cliente de CLARTO en Liberu', client: true);
        }
        // 8–10. The GHL contact: still in the sequence, no stop tag, no DND, consent «Sí».
        try {
            $contact = $ghl->contact($plan->contact_id);
        } catch (Throwable $e) {
            report($e);

            return $wait('GHL no responde');
        }
        $tags = array_map('strval', (array) ($contact['tags'] ?? []));
        $stop = match (true) {
            ! in_array('nutricion-activa', $tags, true) || ! in_array('valoracion-gratuita', $tags, true) => 'Ya no está en la nutrición (GHL)',
            array_intersect(self::STOP_TAGS, $tags) !== [] => 'Parada en GHL: '.implode(', ', array_intersect(self::STOP_TAGS, $tags)),
            ($contact['dnd'] ?? false) === true || ($contact['dndSettings']['WhatsApp']['status'] ?? null) === 'active' => 'No molestar en GHL',
            $ghl->value($contact, 'contact', 'consentimiento_comercial_whatsapp') !== 'Sí' => 'Sin consentimiento comercial por WhatsApp en GHL',
            default => null,
        };
        if ($stop !== null) {
            return $cancel($stop);
        }
        // Send (3. idempotent per contact, plan and code).
        try {
            $c = Communications::send($plan->team_id, null, 'whatsapp', $plan->phone, self::text($slot->codigo, $plan, $request), [
                'key' => $key, 'source' => 'nutricion', 'purpose' => 'marketing', 'consent_verified' => true, 'related' => $request,
            ]);
        } catch (Throwable $e) {
            return $skip(mb_strimwidth('No enviado: '.$e->getMessage(), 0, 250, '…'));
        }
        if ($c->status !== 'enviado') {
            return $wait(mb_strimwidth('Línea: '.($c->error ?: 'WAHA no lo aceptó'), 0, 250, '…'));
        }
        $slot->fill(['estado' => 'enviado', 'motivo' => null, 'communication_id' => $c->getKey(), 'sent_at' => now()])->save();
        if ($slot->codigo === last(array_column(array_values(self::SLOTS[$plan->rama]), 0))) {   // the last WhatsApp of its branch
            $plan->fill(['estado' => 'terminado', 'ended_at' => now()])->save();
        }
        rescue(fn () => $ghl->note($plan->contact_id, 'WhatsApp de nutrición '.strtoupper(str_replace('_', '-', $slot->codigo)).' enviado.'), report: true);

        return 'enviado';
    }

    public static function text(string $code, self $plan, ValuationRequest $request): string
    {
        $template = (string) (config('valoraciones.nutricion_whatsapp.textos.'.$code) ?: self::TEXTS[$code]);
        $booking = $request->bookingUrl('whatsapp-nutricion');
        if ($booking === null) {   // no calendars (owner, 21/11/2026): the visit is arranged in this same chat
            $template = str_replace('Si le encaja, elija día aquí: {enlace_cita}', 'Si le encaja, responda VISITA y acordamos el día con usted.', $template);
        }

        return strtr($template, [
            '{nombre}' => strtok(trim((string) ($request->contact['nombre'] ?? '')), ' ') ?: '',
            '{zona}' => mb_convert_case((string) ($request->catastro['municipio'] ?? $request->property['municipio'] ?? ''), MB_CASE_TITLE),
            '{enlace_cita}' => (string) $booking,
        ]);
    }

    /** §5.2 rule 7: the phone, e-mail or GHL contact belongs to a party (client, owner) or to a won opportunity in Liberu. */
    public static function isClient(self $plan, ValuationRequest $request): bool
    {
        $email = Str::lower(trim((string) ($request->contact['email'] ?? '')));

        return WhatsApp::partiesWithPhone($plan->team_id, $plan->phone)->isNotEmpty()
            || ($email !== '' && Party::query()->where('team_id', $plan->team_id)->whereRaw('lower(email) = ?', [$email])->exists())
            || Party::query()->where('team_id', $plan->team_id)->where('metadata->ghl_contact_id', $plan->contact_id)->exists()
            || GhlHandoff::query()->where('contact_id', $plan->contact_id)->whereIn('status', ['procesado', 'pendiente'])->exists();
    }

    /** Phones of the team's own test devices (NUTRICION_WA_PRUEBA_TELEFONOS): minutes instead of working days. */
    public static function isTestPhone(?string $phone): bool
    {
        return $phone !== null && in_array($phone, (array) config('valoraciones.nutricion_whatsapp.prueba_telefonos'), true);
    }

    public static function tz(): string
    {
        return (string) config('valoraciones.nutricion_whatsapp.zona', 'Europe/Madrid');
    }

    public static function today(): Carbon
    {
        return now()->timezone(self::tz())->startOfDay();
    }

    public static function isWorkingDay(Carbon $day): bool
    {
        return ! $day->isWeekend() && ! in_array($day->format('Y-m-d'), (array) config('valoraciones.nutricion_whatsapp.festivos'), true);
    }

    public static function workingDaysAfter(Carbon $day, int $n): Carbon
    {
        $d = $day->copy();
        while ($n > 0) {
            $d->addDay();
            if (self::isWorkingDay($d)) {
                $n--;
            }
        }

        return $d;
    }

    /** @return array{send_after: Carbon, window_end: Carbon} the day's window, random minute, stored in the app timezone */
    private static function window(Carbon $day): array
    {
        [$from, $to] = (array) config('valoraciones.nutricion_whatsapp.ventana', ['17:30', '18:30']);
        $start = Carbon::parse($day->format('Y-m-d').' '.$from, self::tz());
        $end = Carbon::parse($day->format('Y-m-d').' '.$to, self::tz());
        $latest = max(0, (int) $start->diffInMinutes($end) - 10);

        return ['send_after' => $start->addMinutes(random_int(0, $latest))->timezone(config('app.timezone')), 'window_end' => $end->timezone(config('app.timezone'))];
    }
}
