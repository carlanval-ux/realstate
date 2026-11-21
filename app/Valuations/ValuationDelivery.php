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
 * Sends the result to the person who asked: e-mail (the panel's mailbox) with the PDF attached and a signed download
 * link, and WhatsApp (company line, App\Communications) with the link. A «revision» or «error» result sends a short
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
     * @return array{run: ?int, email: array<string, mixed>, whatsapp: array<string, mixed>, historial: list<array<string, mixed>>}
     */
    public static function state(ValuationRequest $request): array
    {
        $d = (array) $request->delivery;

        return [
            'run' => isset($d['run']) ? (int) $d['run'] : null,
            'email' => (array) ($d['email'] ?? self::fresh()),
            'whatsapp' => (array) ($d['whatsapp'] ?? self::fresh()),
            'historial' => array_values((array) ($d['historial'] ?? [])),
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
        $mail = new ValuationReportMail($request, $run, $pdf, $pdf !== null ? $request->reportUrl() : null);
        try {
            Mail::to($to)->send($mail);
            [$status, $error] = [self::SENT, null];
        } catch (Throwable $e) {
            report($e);
            [$status, $error] = [self::ERROR, mb_strimwidth($e->getMessage(), 0, 250, '…')];
        }
        $logged = Communications::record($request->team_id, 'email', Communication::OUT, [
            'email' => $to, 'phone' => $request->contact['telefono'] ?? null, 'subject' => $mail->subjectLine(), 'body' => $mail->plainText(),
            'status' => $status === self::SENT ? 'enviado' : 'error', 'error' => $error, 'source' => 'valoraciones', 'related' => $request,
        ]);

        return [$status, $error, $logged?->getKey()];
    }

    /** @return array{0: string, 1: ?string, 2: ?int} */
    private function whatsApp(ValuationRequest $request, ValuationRun $run): array
    {
        $phone = (string) ($request->contact['telefono'] ?? '');
        $partyId = Communications::partyFor($request->team_id, \App\WhatsApp\Phone::e164($phone), $request->contact['email'] ?? null);
        try {
            $c = Communications::send($request->team_id, $partyId ? Party::query()->find($partyId) : null, 'whatsapp', $phone, self::whatsAppText($request, $run), [
                'key' => 'valoracion:'.$request->request_id.':'.$run->number.':whatsapp', 'source' => 'valoraciones', 'related' => $request,
            ], $this->waha);
        } catch (Throwable $e) {
            report($e);

            return [self::ERROR, mb_strimwidth($e->getMessage(), 0, 250, '…'), null];
        }
        if ($c->status === 'enviado') {
            return [self::SENT, null, $c->getKey()];
        }
        $line = WhatsAppLine::forTeam($request->team_id);

        return [$line->connected() ? self::ERROR : self::NO_LINE, $c->error, $c->getKey()];
    }

    /**
     * WhatsApp 1 (GHL strategy annex B.2): notice + link + one question. L-16: it does not ask again what the form
     * already asked (the selling horizon); the closing line depends on it. {zona} only, never the full address
     * (the message shows on a locked screen).
     */
    public static function whatsAppText(ValuationRequest $request, ValuationRun $run): string
    {
        $name = strtok(trim((string) ($request->contact['nombre'] ?? '')), ' ') ?: '';
        $zone = mb_convert_case((string) ($request->catastro['municipio'] ?? $request->property['municipio'] ?? ''), MB_CASE_TITLE);
        $hello = "Hola, {$name}. Le escribimos desde CLARTO.\n";   // automated: no advisor name (owner, 21/11/2026)
        if ($run->status !== 'estimada') {
            return $hello."Hemos recibido su solicitud de valoración de su inmueble en {$zone} (ref. {$request->reference}). Para darle una cifra fiable, un asesor la revisará personalmente y le contactará en breve.\n¿Prefiere que le llamemos o seguimos por aquí?";
        }
        $until = now()->addDays(max(1, (int) config('valoraciones.informe_dias', 30)))->format('d/m/Y');
        $text = $hello."Ya le hemos enviado por correo la valoración orientativa de su inmueble en {$zone} (ref. {$request->reference}). También puede descargarla aquí hasta el {$until}:\n".$request->reportUrl()."\n\n";
        $when = match (true) {
            str_starts_with(Str::lower(Str::ascii((string) ($request->contact['franja'] ?? ''))), 'man') => ' por la mañana',
            str_starts_with(Str::lower(Str::ascii((string) ($request->contact['franja'] ?? ''))), 'tar') => ' por la tarde',
            default => '',
        };
        $booking = $request->bookingUrl('whatsapp1');

        return $text.match ((string) ($request->contact['plazo_venta'] ?? '')) {
            'menos_3m', '3_6m' => "Le contactaremos desde la oficina de {$request->office()}{$when} para revisarlo con usted.".($booking !== null ? " Si prefiere fijar ya la visita gratuita a la vivienda: {$booking}" : ' Si prefiere fijar ya la visita gratuita a la vivienda, responda a este mensaje.'),
            '6_12m', 'mas_12m' => '¿Quiere que le contemos qué conviene preparar antes de vender? Responda a este mensaje y lo vemos con usted.',
            'solo_informacion' => 'Si tiene cualquier duda sobre el informe, responda a este mensaje.',
            default => '¿Está pensando en vender próximamente o, de momento, explorando opciones? Si quiere, revisamos el resultado con usted.',
        };
    }
}
