<?php

declare(strict_types=1);

namespace App\Valuations;

use App\Communications\Communications;
use App\Models\Communication;
use App\Models\WhatsAppLine;
use App\WhatsApp\Waha;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Liberu\RealEstate\Parties\Models\Party;
use Throwable;

/**
 * Sends the result to the person who asked: e-mail (the panel's mailbox) with the PDF attached and the short report
 * link (ReportLinks), and WhatsApp (company line, App\Communications) with the link. A «revision» or «error» result sends a short
 * notice instead (the team will review it and get in touch). Each channel is sent once per calculation: a retry only
 * sends what is still «pendiente», «error» or «linea_no_vinculada». Both sends go to the communications log (on the
 * client's timeline when the e-mail or phone belongs to one contact) and to the request (`delivery`).
 */
final class ValuationDelivery
{
    public const PENDING = 'pendiente';

    public const SENT = 'enviado';

    public const ERROR = 'error';

    public const NO_LINE = 'linea_no_vinculada';

    public const CHANNELS = ['email', 'whatsapp'];

    /**
     * «revision» because there are no official price data for the area (InHouseEstimator / ValuationEngine `codigo`).
     * Add here any new code of that kind. Since methodology v2 the engine sends `sin_datos_zona` for every municipality
     * without an authorized series (the old two codes stay for earlier runs). Not `catastro_foral`: there the price data
     * may exist (Bilbao, Pamplona) but the dwelling cannot be checked in Catastro → the general review notice.
     */
    public const NO_DATA_CODES = ['sin_datos_zona', 'sin_datos', 'sin_datos_autorizados', 'zona_no_representativa'];

    /** Owner (21/11/2026): no official data → say so; the team can prepare a valuation and will get in touch (no timing). */
    public const NO_DATA_NOTICE = 'Ahora mismo no hay datos oficiales de precios para tu zona, así que no podemos darte una estimación automática. Pero el equipo de CLARTO puede preparar una valoración para ti y te contactaremos.';

    public static function noOfficialData(ValuationRun $run): bool
    {
        return $run->status === 'revision' && in_array(((array) $run->result)['codigo'] ?? null, self::NO_DATA_CODES, true);
    }

    public function __construct(private readonly ?Waha $waha = null) {}

    /**
     * @param  bool  $resend  send the current calculation again even if an earlier one was already delivered
     */
    public function deliver(ValuationRequest $request, bool $resend = false): void
    {
        Cache::lock('clarto:valoracion-envio:'.$request->request_id, 120)->block(30, function () use ($request, $resend): void {
            $request->refresh();
            $run = $request->currentRun;
            if ($run === null) {
                return;
            }
            if ($request->delivery === null && ! $resend) {
                return;   // requests from before the delivery feature are sent only on request (panel)
            }
            $delivery = self::state($request);
            if ($delivery['run'] !== null && $delivery['run'] !== $run->number) {
                if (! $resend) {
                    return;   // a recalculation is sent to the client only on request («Enviar al cliente»)
                }
                $delivery = ['run' => $run->number, 'email' => self::fresh(), 'whatsapp' => self::fresh(), 'historial' => $delivery['historial']];
            }
            $delivery['run'] = $run->number;

            foreach (self::CHANNELS as $channel) {
                if (! config("valoraciones.envio.{$channel}", true) || $delivery[$channel]['estado'] === self::SENT) {
                    continue;
                }
                [$status, $error, $communicationId] = $channel === 'email' ? $this->email($request, $run) : $this->whatsApp($request, $run);
                $delivery[$channel] = [
                    'estado' => $status, 'fecha' => now()->toIso8601String(), 'error' => $error,
                    'intentos' => ($delivery[$channel]['intentos'] ?? 0) + 1, 'comunicacion_id' => $communicationId,
                ];
                $delivery['historial'][] = ['canal' => $channel, 'estado' => $status, 'fecha' => now()->toIso8601String(), 'calculo' => $run->number, 'error' => $error];
                $request->forceFill(['delivery' => $delivery])->save();
            }
        });
    }

    /**
     * @return array{run: ?int, email: array<string, mixed>, whatsapp: array<string, mixed>, historial: list<array<string, mixed>>, reenvio: int}
     */
    public static function state(ValuationRequest $request): array
    {
        $d = (array) $request->delivery;

        return [
            'run' => isset($d['run']) ? (int) $d['run'] : null,
            'email' => (array) ($d['email'] ?? self::fresh()),
            'whatsapp' => (array) ($d['whatsapp'] ?? self::fresh()),
            'historial' => array_values((array) ($d['historial'] ?? [])),
            'reenvio' => (int) ($d['reenvio'] ?? 0),
        ];
    }

    /** @return array{email: string, whatsapp: string} for the API */
    public static function summary(ValuationRequest $request): array
    {
        $state = self::state($request);

        return ['email' => (string) $state['email']['estado'], 'whatsapp' => (string) $state['whatsapp']['estado']];
    }

    /** Something to retry (scheduled command, panel, a later API call)? */
    public static function pending(ValuationRequest $request): bool
    {
        $state = self::state($request);

        return $request->current_run_id !== null && $request->delivery !== null
            && ($state['run'] === null || collect(self::CHANNELS)->contains(fn (string $c): bool => $state[$c]['estado'] !== self::SENT));
    }

    /** Delivery state of a new request (set when the API creates it: only those are sent automatically). */
    public static function initial(): array
    {
        return ['run' => null, 'email' => self::fresh(), 'whatsapp' => self::fresh(), 'historial' => []];
    }

    /** @return array{estado: string, fecha: null, error: null, intentos: int, comunicacion_id: null} */
    private static function fresh(): array
    {
        return ['estado' => self::PENDING, 'fecha' => null, 'error' => null, 'intentos' => 0, 'comunicacion_id' => null];
    }

    /** @return array{0: string, 1: ?string, 2: ?int} */
    private function email(ValuationRequest $request, ValuationRun $run): array
    {
        $to = (string) ($request->contact['email'] ?? '');
        $pdf = $run->status === 'estimada' && $run->pdf_path !== null ? Storage::disk(ValuationEngine::DISK)->get($run->pdf_path) : null;
        $link = $pdf !== null ? ReportLinks::issue($request, ReportLinks::EMAIL) : null;
        $mail = new ValuationReportMail($request, $run, $pdf, $link['url'] ?? null, $link['expires_at'] ?? null);
        try {
            Mail::to($to)->send($mail);
            [$status, $error] = [self::SENT, null];
        } catch (Throwable $e) {
            report($e);
            [$status, $error] = [self::ERROR, mb_strimwidth($e->getMessage(), 0, 250, '…')];
        }
        $logged = Communications::record($request->team_id, 'email', Communication::OUT, [
            'email' => $to, 'phone' => $request->contact['telefono'] ?? null, 'subject' => $mail->subjectLine(), 'body' => ReportLinks::redact($mail->plainText()),
            'status' => $status === self::SENT ? 'enviado' : 'error', 'error' => $error, 'source' => 'valoraciones', 'related' => $request,
        ]);

        return [$status, $error, $logged?->getKey()];
    }

    /** @return array{0: string, 1: ?string, 2: ?int} */
    private function whatsApp(ValuationRequest $request, ValuationRun $run): array
    {
        $phone = (string) ($request->contact['telefono'] ?? '');
        $partyId = Communications::partyFor($request->team_id, \App\WhatsApp\Phone::e164($phone), $request->contact['email'] ?? null);
        $link = null;
        if ($run->status === 'estimada') {
            $link = ReportLinks::issue($request, ReportLinks::WHATSAPP);
            if ($link === null) {
                return [self::ERROR, 'Enlaces del informe revocados: crea uno nuevo en el panel («Nuevo enlace del informe»).', null];
            }
        }
        $resent = self::state($request)['reenvio'];   // «Nuevo enlace del informe» + send: a new message, not the same one
        try {
            $c = Communications::send($request->team_id, $partyId ? Party::query()->find($partyId) : null, 'whatsapp', $phone, self::whatsAppText($request, $run, $link), [
                'key' => 'valoracion:'.$request->request_id.':'.$run->number.':whatsapp'.($resent > 0 ? ':'.$resent : ''), 'source' => 'valoraciones', 'related' => $request,
            ], $this->waha);
        } catch (Throwable $e) {
            report($e);

            return [self::ERROR, mb_strimwidth($e->getMessage(), 0, 250, '…'), null];
        }
        $c->forceFill(['body' => ReportLinks::redact((string) $c->body)])->saveQuietly();   // the register keeps «…/r/AbCd…», never the whole code
        if ($c->status === 'enviado') {
            return [self::SENT, null, $c->getKey()];
        }
        $line = WhatsAppLine::forTeam($request->team_id);

        return [$line->connected() ? self::ERROR : self::NO_LINE, $c->error, $c->getKey()];
    }

    /**
     * WhatsApp 1, owner's text (21/11/2026, «tú», signed «Equipo CLARTO»): {nombre}, {zona} (never the full address:
     * the message shows on a locked screen), {fecha_caducidad} dd/mm/aaaa and the short link. The closing question
     * becomes a statement only when the client chose a time slot in the form; no office is named (none is confirmed
     * by a person yet, the postcode only suggests one).
     *
     * @param  array{url: string, expires_at: \Carbon\CarbonInterface}|null  $link  from ReportLinks (issued here when null)
     */
    public static function whatsAppText(ValuationRequest $request, ValuationRun $run, ?array $link = null): string
    {
        $name = strtok(trim((string) ($request->contact['nombre'] ?? '')), ' ') ?: '';
        $hello = $name !== '' ? "Hola, {$name}." : 'Hola.';
        $zone = mb_convert_case((string) ($request->catastro['municipio'] ?? $request->property['municipio'] ?? ''), MB_CASE_TITLE);
        $slot = Str::lower(Str::ascii((string) ($request->contact['franja'] ?? '')));
        $question = match (true) {
            str_starts_with($slot, 'man') => 'Te llamaremos por la mañana. Si te viene mejor otro momento, respóndenos a este mensaje.',
            str_starts_with($slot, 'tar') => 'Te llamaremos por la tarde. Si te viene mejor otro momento, respóndenos a este mensaje.',
            default => '¿Te viene mejor que te llamemos por la mañana o por la tarde?',
        };
        if ($run->status !== 'estimada') {
            return "{$hello} Hemos recibido tu solicitud de valoración de tu inmueble en {$zone} (ref. {$request->reference}).\n\n"
                .(self::noOfficialData($run) ? self::NO_DATA_NOTICE : 'Para darte una cifra fiable, el equipo la revisará personalmente y te contactará en breve.')."\n\n"
                ."{$question}\n\nEquipo CLARTO";
        }
        $link ??= ReportLinks::issue($request, ReportLinks::WHATSAPP);
        $until = $link !== null ? $link['expires_at']->copy()->timezone('Europe/Madrid')->format('d/m/Y') : '';

        return "{$hello} Ya tienes disponible la valoración orientativa de tu inmueble en {$zone}.\n\n"
            ."Puedes consultar y descargar el informe hasta el {$until}:\n".($link['url'] ?? '')."\n\n"
            ."Es una estimación informativa. Si estás pensando en vender, podemos revisar contigo las características de la vivienda y preparar una valoración personalizada.\n\n"
            ."{$question}\n\nEquipo CLARTO";
    }

    /**
     * Panel «Nuevo enlace del informe» with «send it»: e-mail and WhatsApp of the current calculation go out again
     * (with a new WhatsApp idempotency key), history kept.
     */
    public static function markForResend(ValuationRequest $request): void
    {
        $state = self::state($request);
        $request->forceFill(['delivery' => [
            'run' => $request->currentRun?->number, 'email' => self::fresh(), 'whatsapp' => self::fresh(),
            'historial' => $state['historial'], 'reenvio' => (int) ($state['reenvio'] ?? 0) + 1,
        ]])->save();
    }
}
