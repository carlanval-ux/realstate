<?php

declare(strict_types=1);

namespace App\Valuations;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Adapter for a commercial AVM (idealista/data or Tinsa). Neither publishes its API (orquestacion/valoraciones-datos/
 * proveedores/INFORME-proveedores.md), so the HTTP driver is prepared, not certified:
 *  - auth: OAuth2 client credentials when token_url is set (client_id + secret), else the secret as a Bearer key;
 *  - request: POST JSON with our normalised fields (REQUEST_FIELDS);
 *  - answer: the first numeric value among VALUE_PATHS / MIN_PATHS / MAX_PATHS (dot paths).
 * Adjust these three lists when the contract and documentation arrive. The simulated driver answers a deterministic
 * figure near CLARTO's own estimate, marked as simulated everywhere.
 */
final class ExternalAvm implements ValuationProvider
{
    public const REQUEST_FIELDS = ['rc', 'direccion', 'codigo_postal', 'municipio', 'coordenadas', 'tipo', 'superficie_m2', 'dormitorios', 'banos', 'planta', 'ascensor', 'exterior', 'terraza_m2', 'garaje', 'trastero', 'conservacion', 'ano'];

    public const VALUE_PATHS = ['valor', 'value', 'valuation.value', 'data.value', 'price', 'estimatedValue'];

    public const MIN_PATHS = ['min', 'valor_min', 'valuation.min', 'data.min', 'range.min', 'lowValue'];

    public const MAX_PATHS = ['max', 'valor_max', 'valuation.max', 'data.max', 'range.max', 'highValue'];

    /** @param array{nombre: string, driver: string, url: ?string, token_url: ?string, client_id: ?string, secret: ?string} $config */
    public function __construct(private readonly string $key, private readonly array $config) {}

    public function key(): string
    {
        return $this->key;
    }

    public function name(): string
    {
        return $this->config['nombre'];
    }

    public function simulated(): bool
    {
        return $this->config['driver'] === 'simulado';
    }

    public function estimate(array $subject): ?array
    {
        return $this->simulated() ? $this->simulate($subject) : $this->request($subject);
    }

    /** @param array<string, mixed> $subject */
    private function request(array $subject): ?array
    {
        if (blank($this->config['url']) || blank($this->config['secret'])) {
            throw new ProviderFailed(__('clarto.valoraciones.proveedor.sin_configurar', ['p' => $this->name()]));
        }

        try {
            $response = Http::withToken($this->token())->acceptJson()->timeout((int) config('valoraciones.proveedor_timeout', 12))
                ->post((string) $this->config['url'], Arr::only($subject, self::REQUEST_FIELDS) + ['operacion' => 'venta']);
        } catch (ConnectionException $e) {
            throw new ProviderFailed(__('clarto.valoraciones.proveedor.sin_respuesta', ['p' => $this->name()]), previous: $e);
        }
        if ($response->status() === 404 || $response->status() === 204) {
            return null;   // no value for this dwelling: the next provider is asked
        }
        if (! $response->successful()) {
            throw new ProviderFailed(__('clarto.valoraciones.proveedor.error_http', ['p' => $this->name(), 's' => $response->status()]));
        }

        $json = (array) $response->json();
        $pick = function (array $paths) use ($json): ?float {
            foreach ($paths as $path) {
                if (is_numeric($value = data_get($json, $path))) {
                    return (float) $value;
                }
            }

            return null;
        };
        $value = $pick(self::VALUE_PATHS);
        if ($value === null || $value <= 0) {
            throw new ProviderFailed(__('clarto.valoraciones.proveedor.sin_valor', ['p' => $this->name()]));
        }

        return $this->result($subject, $value, $pick(self::MIN_PATHS) ?? $value * 0.9, $pick(self::MAX_PATHS) ?? $value * 1.1, [
            'nombre' => $this->name().' (AVM)', 'tipo' => 'avm', 'ambito' => 'Inmueble', 'fecha_datos' => now()->toDateString(),
            'licencia' => __('clarto.valoraciones.proveedor.licencia_contrato'), 'url' => (string) $this->config['url'],
        ]);
    }

    /** @param array<string, mixed> $subject */
    private function simulate(array $subject): ?array
    {
        $own = (new InHouseEstimator)->estimate($subject);
        if (($own['estado'] ?? null) !== 'estimada') {
            return null;
        }
        // Deterministic ±4 % so the same dwelling always gets the same simulated answer.
        $shift = ((crc32($this->key.($subject['rc'] ?? $subject['direccion'] ?? '')) % 9) - 4) / 100;
        $value = $own['precio_central'] * (1 + $shift);

        return $this->result($subject, $value, $value * 0.92, $value * 1.08, [
            'nombre' => $this->name().' (SIMULADO: datos de prueba, no es una respuesta real del proveedor)', 'tipo' => 'avm', 'ambito' => 'Inmueble',
            'fecha_datos' => now()->toDateString(), 'licencia' => 'Simulación interna de CLARTO para pruebas', 'url' => '',
        ], $own);
    }

    /**
     * @param  array<string, mixed>  $subject
     * @param  array<string, string>  $source
     * @param  array<string, mixed>|null  $own
     */
    private function result(array $subject, float $value, float $min, float $max, array $source, ?array $own = null): array
    {
        $own ??= (new InHouseEstimator)->estimate($subject);
        $round = fn (float $v): int => (int) (round($v / 1000) * 1000);
        $m2 = max(1.0, (float) ($subject['superficie_m2'] ?? 1));

        return [
            'estado' => 'estimada',
            'precio_central' => $round($value),
            'intervalo' => ['min' => $round(min($min, $value)), 'max' => $round(max($max, $value))],
            'tipo_precio' => 'cierre',
            'moneda' => 'EUR',
            'eur_m2' => (int) round($value / $m2),
            'suficiencia' => 'alta',
            'fecha' => now()->toIso8601String(),
            'version_calculo' => InHouseEstimator::VERSION.'+'.$this->key,
            'version_datos' => (string) (InHouseEstimator::data()['version'] ?? ''),
            'fuentes' => [$source, ...array_slice((array) ($own['fuentes'] ?? []), 0, 3)],
            'limitaciones' => [
                __('clarto.valoraciones.proveedor.limitacion', ['p' => $this->name()]),
                ...(($own['estado'] ?? null) === 'estimada' ? ['Contraste con el Estimador CLARTO: '.number_format((float) $own['precio_central'], 0, ',', '.').' €.'] : []),
            ],
            'calculo' => ['metodo' => 'AVM '.$this->name().($this->simulated() ? ' (simulado)' : ''), 'superficie_m2' => round($m2, 1), 'superficie_origen' => $subject['superficie_origen'] ?? null, 'ajustes' => [], 'contraste_clarto' => $own['precio_central'] ?? null],
        ];
    }

    private function token(): string
    {
        if (blank($this->config['token_url'])) {
            return (string) $this->config['secret'];
        }

        return Cache::remember('clarto:avm-token:'.$this->key.':'.sha1((string) $this->config['client_id']), 1500, function (): string {
            try {
                $response = Http::asForm()->withBasicAuth((string) $this->config['client_id'], (string) $this->config['secret'])->timeout(10)
                    ->post((string) $this->config['token_url'], ['grant_type' => 'client_credentials']);
            } catch (Throwable $e) {
                throw new ProviderFailed(__('clarto.valoraciones.proveedor.sin_respuesta', ['p' => $this->name()]), previous: $e);
            }
            $token = $response->json('access_token');
            if (! $response->successful() || ! is_string($token)) {
                throw new ProviderFailed(__('clarto.valoraciones.proveedor.error_http', ['p' => $this->name(), 's' => $response->status()]));
            }

            return $token;
        });
    }
}
