<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Modules\ClartoModules;
use App\Valuations\Catastro;
use App\Valuations\GhlValuationSync;
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
            ]);
            $valuation->forceFill(['reference' => 'VAL-'.str_pad((string) $valuation->getKey(), 6, '0', STR_PAD_LEFT)])->save();

            return $valuation;
        });

        // New request, or a retry after an error: calculate (a finished one is returned as it is).
        $isNew = $valuation->wasRecentlyCreated || $valuation->current_run_id === null;
        if ($valuation->current_run_id === null || $valuation->status === 'error') {
            $run = $engine->run($valuation, $isNew ? 'api' : 'reintento');
            self::later(fn () => app(ValuationEngine::class)->improveSummary($run->fresh()));
        }
        $this->syncLater($valuation);

        return response()->json($valuation->refresh()->apiResponse(), $existing === null ? 201 : 200);
    }

    public function show(string $requestId): JsonResponse
    {
        $valuation = ValuationRequest::query()->where('request_id', $requestId)->first();
        if ($valuation === null) {
            return response()->json(['message' => 'No encontrada.'], 404);
        }
        $this->syncLater($valuation);

        return response()->json($valuation->apiResponse());
    }

    /** Signed link (web, GHL, e-mail): the report of that run, while the signature is valid. */
    public function signedReport(string $requestId, int $run): Response
    {
        $valuation = ValuationRequest::query()->where('request_id', $requestId)->firstOrFail();

        return self::pdf($valuation, $valuation->runs()->where('number', $run)->firstOrFail());
    }

    /** Panel: staff allowed to see this request (ValuationAccess). */
    public function panelReport(ValuationRequest $valuationRequest, ?int $run = null): Response
    {
        abort_unless(Gate::allows('viewValuation', $valuationRequest), 403);
        $model = $run === null ? $valuationRequest->currentRun : $valuationRequest->runs()->where('number', $run)->first();
        abort_if($model === null, 404);

        return self::pdf($valuationRequest, $model);
    }

    private static function pdf(ValuationRequest $valuation, ValuationRun $run): Response
    {
        abort_if($run->pdf_path === null || ! Storage::disk(ValuationEngine::DISK)->exists($run->pdf_path), 404);

        return response((string) Storage::disk(ValuationEngine::DISK)->get($run->pdf_path), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="CLARTO-estimacion-'.$valuation->reference.'-'.$run->number.'.pdf"',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** GHL after the answer (the web does not wait); a failed or pending sync is retried on every call. */
    private function syncLater(ValuationRequest $valuation): void
    {
        if (in_array($valuation->ghl_status, ['pendiente', 'error'], true) || ($valuation->ghl_status === 'sincronizada' && $valuation->ghl_synced_at < $valuation->currentRun?->updated_at)) {
            self::later(fn () => (new GhlValuationSync)->sync($valuation->fresh()));
        }
    }

    /** After the answer is sent (the web does not wait for GHL or the AI); inline when config valoraciones.en_linea (tests). */
    public static function later(\Closure $job): void
    {
        config('valoraciones.en_linea') ? $job() : dispatch($job)->afterResponse();
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
            'contacto.telefono' => ['nullable', 'string', 'max:30'],
            'contacto.canal' => ['nullable', Rule::in(['email', 'telefono', 'whatsapp'])],
            'contacto.franja' => ['nullable', 'string', 'max:60'],
            'consentimiento' => ['required', 'array'],
            'consentimiento.version' => ['required', 'string', 'max:40'],
            'consentimiento.fecha' => ['required', 'date'],
            'atribucion' => ['nullable', 'array'],
        ];
    }
}
