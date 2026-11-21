<?php

declare(strict_types=1);

namespace App\Valuations;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * GoHighLevel for a valuation request (moved here from the website's src/lib/ghl.ts): upsert the contact, then ONE
 * opportunity per request in the acquisition pipeline, with the inputs, the result, the calculation version and the
 * private report link in its custom fields. A retry never duplicates: the opportunity id is saved as soon as GHL
 * returns it, and before creating one the pipeline is searched for this request (tag «#xxxxxxxxxx» in the name or the
 * solicitud_id field), exactly like the web did. Later runs (recalculation) update the same opportunity.
 *
 * Won in GHL → the existing GhlHandoff flow (owner, draft property, TARMIO task); ClartoValuationsServiceProvider
 * adds the valuation reference to that draft property.
 */
final class GhlValuationSync
{
    public const SOURCE = 'Web clarto.es · Valoración automática';

    public static function configured(): bool
    {
        return filled(config('ghl.token')) && filled(config('ghl.location_id')) && filled(config('ghl.pipeline_id')) && filled(config('valoraciones.ghl.stage_id'));
    }

    /** Short stable suffix of the request id that identifies its opportunity (same as the web's requestTag()). */
    public static function tag(string $requestId): string
    {
        return '#'.substr(str_replace('-', '', $requestId), 0, 10);
    }

    public function sync(ValuationRequest $request): void
    {
        if (! self::configured()) {
            $request->forceFill(['ghl_status' => 'sin_configurar', 'ghl_error' => null])->save();

            return;
        }

        Cache::lock('clarto:valoracion-ghl:'.$request->request_id, 60)->block(20, function () use ($request): void {
            $request->refresh();
            $request->increment('ghl_attempts');
            try {
                $contactId = $request->ghl_contact_id ?: $this->upsertContact($request);
                $request->forceFill(['ghl_contact_id' => $contactId])->save();

                $fields = $this->customFields($request);
                $opportunityId = $request->ghl_opportunity_id ?: $this->findOpportunity($request, $contactId);
                if ($opportunityId === null) {
                    $created = $this->call('POST', '/opportunities/', [
                        'locationId' => config('ghl.location_id'), 'pipelineId' => config('ghl.pipeline_id'), 'pipelineStageId' => config('valoraciones.ghl.stage_id'),
                        'contactId' => $contactId, 'status' => 'open', 'source' => self::SOURCE, 'name' => $this->name($request), 'customFields' => $fields,
                    ]);
                    $opportunityId = (string) ($created['opportunity']['id'] ?? '');
                    if ($opportunityId === '') {
                        throw new RuntimeException('GHL no ha devuelto la oportunidad');
                    }
                    $request->forceFill(['ghl_opportunity_id' => $opportunityId])->save();   // before anything else can fail
                } else {
                    $request->forceFill(['ghl_opportunity_id' => $opportunityId])->save();
                    $this->call('PUT', '/opportunities/'.rawurlencode($opportunityId), ['name' => $this->name($request), 'customFields' => $fields]);
                }
                $request->forceFill(['ghl_status' => 'sincronizada', 'ghl_error' => null, 'ghl_synced_at' => now()])->save();
            } catch (Throwable $e) {
                report($e);
                $request->forceFill(['ghl_status' => 'error', 'ghl_error' => mb_strimwidth($e->getMessage(), 0, 300, '…')])->save();
            }
        });
    }

    private function upsertContact(ValuationRequest $request): string
    {
        $c = (array) $request->contact;
        $name = trim((string) ($c['nombre'] ?? ''));
        $parts = preg_split('/\s+/', $name) ?: [];
        $answer = $this->call('POST', '/contacts/upsert', array_filter([
            'locationId' => config('ghl.location_id'),
            'firstName' => array_shift($parts) ?: null,
            'lastName' => implode(' ', $parts) ?: null,
            'name' => $name ?: null,
            'email' => $c['email'] ?? null,
            'phone' => $c['telefono'] ?? null,
            'source' => self::SOURCE,
            'tags' => ['valoracion-web', 'propietario'],
        ], fn ($v): bool => $v !== null && $v !== ''));
        $id = (string) ($answer['contact']['id'] ?? '');
        if ($id === '') {
            throw new RuntimeException('GHL no ha devuelto el contacto');
        }

        return $id;
    }

    private function findOpportunity(ValuationRequest $request, string $contactId): ?string
    {
        $answer = $this->call('GET', '/opportunities/search?'.http_build_query([
            'location_id' => config('ghl.location_id'), 'pipeline_id' => config('ghl.pipeline_id'), 'contact_id' => $contactId, 'limit' => 100,
        ]));
        $tag = self::tag($request->request_id);
        $field = config('ghl.fields.solicitud_id');
        foreach ((array) ($answer['opportunities'] ?? []) as $o) {
            if (str_contains((string) ($o['name'] ?? ''), $tag)) {
                return (string) $o['id'];
            }
            foreach ((array) ($o['customFields'] ?? []) as $cf) {
                if (filled($field) && ($cf['id'] ?? null) === $field && (string) ($cf['fieldValue'] ?? $cf['field_value'] ?? $cf['value'] ?? '') === $request->request_id) {
                    return (string) $o['id'];
                }
            }
        }

        return null;
    }

    private function name(ValuationRequest $request): string
    {
        $p = (array) $request->property;

        return trim((string) preg_replace('/\s+/', ' ', 'Valoración · '.($request->catastro['municipio'] ?? $p['municipio'] ?? '').' · '.($p['tipo'] ?? '').' '.self::tag($request->request_id)));
    }

    /** @return list<array{id: string, field_value: string}> */
    private function customFields(ValuationRequest $request): array
    {
        $p = (array) $request->property;
        $run = $request->currentRun;
        $r = (array) ($run?->result ?? []);
        $eur = fn ($v): string => number_format((float) $v, 0, ',', '.').' €';
        $values = [
            'solicitud_id' => $request->request_id,
            'operacion' => $request->operation,
            'tipo_inmueble' => $p['tipo'] ?? null,
            'municipio' => $request->catastro['municipio'] ?? $p['municipio'] ?? null,
            'codigo_postal' => $p['codigo_postal'] ?? $request->catastro['codigo_postal'] ?? null,
            'direccion' => $request->address(),
            'superficie_m2' => $r['calculo']['superficie_m2'] ?? $p['superficie_construida_m2'] ?? $p['superficie_util_m2'] ?? null,
            'dormitorios' => $p['dormitorios'] ?? null,
            'banos' => $p['banos'] ?? null,
            'referencia_valoracion' => $request->reference,
            'estado_valoracion' => $run?->status ?? $request->status,
            'precio_estimado' => isset($r['precio_central']) ? (string) $r['precio_central'] : null,
            'intervalo' => isset($r['intervalo']) ? $eur($r['intervalo']['min']).' – '.$eur($r['intervalo']['max']) : null,
            'suficiencia' => $r['suficiencia'] ?? null,
            'version_calculo' => $r['version_calculo'] ?? $run?->version,
            'informe_url' => $request->reportUrl(),
            'datos_aportados' => mb_strimwidth((string) json_encode($p, JSON_UNESCAPED_UNICODE), 0, 2000, '…'),
        ];
        $ids = [...(array) config('ghl.fields'), ...(array) config('valoraciones.ghl.fields')];
        $out = [];
        foreach ($ids as $name => $id) {
            if (filled($id) && filled($values[$name] ?? null)) {
                $out[] = ['id' => (string) $id, 'field_value' => (string) $values[$name]];
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function call(string $method, string $path, ?array $body = null): array
    {
        try {
            $response = $this->http()->send($method, config('ghl.api_url').$path, $body === null ? [] : ['json' => $body]);
        } catch (ConnectionException $e) {
            throw new RuntimeException("GHL {$method} ".strtok($path, '?').': sin respuesta', previous: $e);
        }
        if (! $response->successful()) {
            throw new RuntimeException("GHL {$method} ".strtok($path, '?').': HTTP '.$response->status());
        }

        return (array) $response->json();
    }

    private function http(): PendingRequest
    {
        return Http::withToken((string) config('ghl.token'))->acceptJson()->timeout(10)->withHeaders(['Version' => (string) config('ghl.api_version')]);
    }
}
