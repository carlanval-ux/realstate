<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Modules\ClartoModules;
use App\Valuations\Catastro;
use App\Valuations\GhlValuationSync;
use App\Valuations\ProcessValuation;
use App\Valuations\ValuationDelivery;
use App\Valuations\ValuationEngine;
use App\Valuations\ValuationRequest;
use App\Valuations\ValuationRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Liberu\Foundation\Organizations\Models\Team;
use Symfony\Component\HttpFoundation\Response;

/**
 * Website → Liberu valuations API (contract: docs/clarto/valoraciones.md). Server-to-server with X-Valoraciones-Clave;
 * the report itself is served by a signed, expiring link (no login) or, in the panel, to staff allowed to see it.
 */
final class ValoracionesController
{
    public const MODULE = 'valoraciones';

    public function catastro(Request $request, Catastro $catastro): JsonResponse
    {
        if (($refusal = ClartoModules::refusal(self::MODULE)) !== null) {
            return response()->json(['message' => $refusal, 'estado' => 'desactivado'], 403);
        }
        $data = $request->validate([
            'rc' => ['nullable', 'string', 'max:25'],
            'direccion' => ['required_without:rc', 'nullable', 'string', 'max:200'],
            'municipio' => ['required_without:rc', 'nullable', 'string', 'max:120'],
            'provincia' => ['nullable', 'string', 'max:60'],
        ]);

        return response()->json(filled($data['rc'] ?? null)
            ? $catastro->byReference((string) $data['rc'])
            : $catastro->search($data['direccion'] ?? null, $data['municipio'] ?? null, $data['provincia'] ?? null));
    }

    public function store(Request $request, ValuationEngine $engine): JsonResponse
    {
        $data = $request->validate(self::rules());
        $data['contacto']['telefono'] = \App\WhatsApp\Phone::e164((string) $data['contacto']['telefono']);
        $existing = ValuationRequest::query()->where('request_id', $data['request_id'])->first();
        if ($existing === null && ($refusal = ClartoModules::refusal(self::MODULE)) !== null) {
            return response()->json(['message' => $refusal, 'estado' => 'desactivado'], 403);
        }

        $valuation = Cache::lock('clarto:valoracion-alta:'.$data['request_id'], 30)->block(25, function () use ($data, $request): ValuationRequest {
            $found = ValuationRequest::query()->where('request_id', $data['request_id'])->first();
            if ($found !== null) {
                return $found;
            }
            $property = $data['inmueble'];
            if (! ($property['confirmado_por_usuario'] ?? false)) {
                $property['rc'] = null;   // only a dwelling the visitor confirmed is looked up
            }
            $valuation = ValuationRequest::query()->create([
                'request_id' => $data['request_id'],
                'team_id' => Team::query()->oldest()->value('id'),
                'operation' => $data['operacion'],
                'property' => $property,
                'contact' => $data['contacto'],
                'consent' => $data['consentimiento'],
                'attribution' => $data['atribucion'] ?? null,
                'client_ip' => (string) ($request->header('X-Cliente-Ip') ?: $request->ip()),
                'delivery' => ValuationDelivery::initial(),
            ]);
            $valuation->forceFill(['reference' => 'VAL-'.str_pad((string) $valuation->getKey(), 6, '0', STR_PAD_LEFT)])->save();

            return $valuation;
        });

        // New request, or a retry after an error: calculate (a finished one is returned as it is).
        $isNew = $valuation->wasRecentlyCreated || $valuation->current_run_id === null;
        if ($valuation->current_run_id === null || $valuation->status === 'error') {
            $engine->run($valuation, $isNew ? 'api' : 'reintento');
        }
        $this->processLater($valuation->refresh());

        return response()->json($valuation->refresh()->apiResponse(), $existing === null ? 201 : 200);
    }

    public function show(string $requestId): JsonResponse
    {
        $valuation = ValuationRequest::query()->where('request_id', $requestId)->first();
        if ($valuation === null) {
            return response()->json(['message' => 'No encontrada.'], 404);
        }
        $this->processLater($valuation);

        return response()->json($valuation->apiResponse());
    }

    /** Signed link (web, GHL, e-mail): the report of that run, while the signature is valid. */
    public function signedReport(Request $request, string $requestId, int $run): Response
    {
        $valuation = ValuationRequest::query()->where('request_id', $requestId)->firstOrFail();
        // Links revoked by the team (panel): the signed ones sent before the short links stop working too.
        if ($valuation->report_links_revoked_at !== null) {
            return InformeEnlaceController::page('revocado', 410);
        }
        $response = self::pdf($valuation, $valuation->runs()->where('number', $run)->firstOrFail());
        if (! self::isPreview($request)) {
            $valuation->increment('report_opens');
            dispatch(fn () => (new GhlValuationSync)->reportOpens($valuation->refresh()))->afterResponse();
        }

        return $response;
    }

    /** Panel: staff allowed to see this request (ValuationAccess). */
    public function panelReport(ValuationRequest $valuationRequest, ?int $run = null): Response
    {
        abort_unless(Gate::allows('viewValuation', $valuationRequest), 403);
        $model = $run === null ? $valuationRequest->currentRun : $valuationRequest->runs()->where('number', $run)->first();
        abort_if($model === null, 404);

        return self::pdf($valuationRequest, $model);
    }

    /**
     * L-15: «aperturas del informe» in GHL (the 2nd one raises the temperature). Not a person, so not counted:
     *  - link previews and mail scanners, by user agent (WhatsApp, Facebook/iMessage, bots, Proofpoint, SafeLinks…);
     *  - anything from our own server or network (private or loopback address after Apache's remoteip): WAHA builds the
     *    WhatsApp preview itself when sending, from the VPS, with a random desktop user agent (seen 21/11/2026, VAL-000007);
     *  - browser prefetches (Sec-Purpose / Purpose: prefetch).
     * Shared by the short links (/r/*) and the old signed route. ponytail: user-agent list, extend if needed.
     */
    public static function isPreview(Request $request): bool
    {
        $ip = (string) $request->ip();
        $ownNetwork = $ip === '' || str_starts_with($ip, '127.') || $ip === '::1'
            || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) === false;
        $prefetch = str_contains(strtolower($request->header('Sec-Purpose', '').' '.$request->header('Purpose', '').' '.$request->header('X-Purpose', '')), 'prefetch')
            || str_contains(strtolower((string) $request->header('X-Moz', '')), 'prefetch');

        return $ownNetwork || $prefetch
            || (bool) preg_match('/whatsapp|facebookexternalhit|facebot|bot|crawler|spider|preview|scanner|proofpoint|mimecast|barracuda|safelinks|curl|wget|python|go-http-client|okhttp|axios|node-fetch/i', (string) $request->userAgent());
    }

    public static function pdf(ValuationRequest $valuation, ValuationRun $run, string $disposition = 'inline'): Response
    {
        abort_if($run->pdf_path === null || ! Storage::disk(ValuationEngine::DISK)->exists($run->pdf_path), 404);

        return response((string) Storage::disk(ValuationEngine::DISK)->get($run->pdf_path), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition.'; filename="CLARTO-estimacion-'.$valuation->reference.'-'.$run->number.'.pdf"',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** Summary, GoHighLevel and delivery to the client, queued (the web does not wait); pending steps are retried on every call. */
    private function processLater(ValuationRequest $valuation): void
    {
        if ($valuation->current_run_id !== null && (in_array($valuation->ghl_status, ['pendiente', 'error'], true) || ValuationDelivery::pending($valuation))) {
            ProcessValuation::dispatch($valuation->getKey());
        }
    }

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'request_id' => ['required', 'uuid'],
            'operacion' => ['required', Rule::in(['venta'])],
            'inmueble' => ['required', 'array'],
            'inmueble.rc' => ['nullable', 'string', 'regex:/^[0-9A-Za-z]{20}$/'],
            'inmueble.direccion_confirmada' => ['required', 'string', 'max:255'],
            'inmueble.municipio' => ['required', 'string', 'max:120'],
            'inmueble.codigo_postal' => ['nullable', 'regex:/^\d{5}$/'],
            'inmueble.confirmado_por_usuario' => ['required', 'boolean'],
            'inmueble.tipo' => ['required', Rule::in(['piso', 'casa', 'atico', 'duplex', 'estudio', 'local', 'otro'])],
            'inmueble.superficie_util_m2' => ['nullable', 'numeric', 'min:5', 'max:5000'],
            'inmueble.superficie_construida_m2' => ['nullable', 'numeric', 'min:5', 'max:5000'],
            'inmueble.incluye_elementos_comunes' => ['nullable', 'boolean'],
            'inmueble.dormitorios' => ['nullable', 'integer', 'min:0', 'max:30'],
            'inmueble.banos' => ['nullable', 'integer', 'min:0', 'max:20'],
            'inmueble.planta' => ['nullable', 'string', 'max:10'],
            'inmueble.ascensor' => ['nullable', 'boolean'],
            'inmueble.exterior' => ['nullable', 'boolean'],
            'inmueble.terraza_m2' => ['nullable', 'numeric', 'min:0', 'max:2000'],
            'inmueble.garaje' => ['nullable', Rule::in(['incluido', 'opcional', 'no'])],
            'inmueble.trastero' => ['nullable', 'boolean'],
            'inmueble.conservacion' => ['nullable', Rule::in(['a_reformar', 'buen_estado', 'reformado', 'obra_nueva'])],
            'inmueble.ano_construccion' => ['nullable', 'integer', 'min:1700', 'max:2100'],
            'contacto' => ['required', 'array'],
            'contacto.nombre' => ['required', 'string', 'max:120'],
            'contacto.email' => ['required', 'email', 'max:190'],
            'contacto.telefono' => ['required', 'string', 'max:30', function (string $attribute, mixed $value, \Closure $fail): void {
                // E.164 (Spain by default: 9 digits starting 6, 7, 8 or 9; other countries with «+»), needed for WhatsApp.
                $e164 = \App\WhatsApp\Phone::e164((string) $value);
                if ($e164 === null || (str_starts_with($e164, '+34') && ! preg_match('/^\+34[6789]\d{8}$/', $e164))) {
                    $fail(__('clarto.valoraciones.telefono_no_valido'));
                }
            }],
            'contacto.canal' => ['nullable', Rule::in(['email', 'telefono', 'whatsapp'])],
            'contacto.franja' => ['nullable', 'string', 'max:60'],
            'contacto.plazo_venta' => ['nullable', Rule::in(array_keys(GhlValuationSync::PLAZOS))],
            'contacto.relacion' => ['nullable', Rule::in(array_keys(GhlValuationSync::RELACIONES))],
            'contacto.comentario' => ['nullable', 'string', 'max:300'],
            'inmueble.en_venta' => ['nullable', Rule::in(['no', 'propietario', 'por_su_cuenta', 'agencia'])],
            'inmueble.ocupacion' => ['nullable', Rule::in(['propietario', 'habitual', 'vacia', 'alquilada'])],
            'consentimiento' => ['required', 'array'],
            'consentimiento.version' => ['required', 'string', 'max:40'],
            'consentimiento.fecha' => ['required', 'date'],
            'consentimiento.comercial' => ['nullable', 'array'],
            'consentimiento.comercial.email' => ['nullable', 'boolean'],
            'consentimiento.comercial.whatsapp' => ['nullable', 'boolean'],
            'consentimiento.comercial.version' => ['nullable', 'string', 'max:40'],
            'consentimiento.comercial.texto_email' => ['nullable', 'string', 'max:500'],
            'consentimiento.comercial.texto_whatsapp' => ['nullable', 'string', 'max:500'],
            'consentimiento.comercial.fecha' => ['nullable', 'date'],
            'atribucion' => ['nullable', 'array'],
        ];
    }
}
