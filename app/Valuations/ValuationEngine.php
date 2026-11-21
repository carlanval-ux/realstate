<?php

declare(strict_types=1);

namespace App\Valuations;

use App\Ai\AiClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Runs a valuation request: Catastro detail of the confirmed reference, the normalised dwelling (subject()), the
 * providers in order (enabled AVMs, then CLARTO's estimator; a failing provider is recorded and the next one is
 * used), the summary (template now, AI afterwards: improveSummary()) and the PDF. Every run is a new ValuationRun:
 * recalculating keeps the history.
 */
final class ValuationEngine
{
    /** Built area with common elements ≈ useful area × this (≈15 % walls and partitions, ≈10 % common elements). */
    public const USEFUL_TO_BUILT = 1.25;

    public const DISK = 'local';

    public function __construct(private readonly Catastro $catastro = new Catastro) {}

    public function run(ValuationRequest $request, string $trigger = 'api', ?int $userId = null): ValuationRun
    {
        return Cache::lock('clarto:valoracion:'.$request->request_id, 120)->block(30, function () use ($request, $trigger, $userId): ValuationRun {
            $request->refresh();
            $run = new ValuationRun([
                'valuation_request_id' => $request->getKey(),
                'number' => (int) $request->runs()->max('number') + 1,
                'version' => InHouseEstimator::VERSION,
                'trigger' => $trigger,
                'user_id' => $userId,
            ]);

            try {
                $this->refreshCatastro($request, $trigger !== 'api');
                $subject = $this->subject($request);
                [$result, $provider, $errors] = $this->estimate($subject);
                $run->fill([
                    'status' => $result['estado'],
                    'provider' => $provider?->key(),
                    'simulated' => (bool) $provider?->simulated(),
                    'provider_errors' => $errors ?: null,
                    'version' => $result['version_calculo'] ?? InHouseEstimator::VERSION,
                ]);
                if ($result['estado'] === 'estimada') {
                    $result['resumen'] = $this->templateSummary($request, $result, $subject);
                    $run->fill(['result' => $result, 'summary' => $result['resumen'], 'summary_source' => 'plantilla']);
                } else {
                    $run->fill(['result' => ['subject' => $subject] + $result, 'message' => $result['motivo']]);
                }
                DB::transaction(function () use ($run, $request): void {
                    $run->save();
                    if ($run->status === 'estimada') {
                        $this->storePdf($request, $run);
                    }
                    $request->forceFill(['current_run_id' => $run->getKey(), 'status' => $run->status, 'last_error' => null])->save();
                });
            } catch (Throwable $e) {
                report($e);
                $message = mb_strimwidth($e->getMessage(), 0, 500, '…');
                $run->fill(['status' => 'error', 'message' => $message, 'result' => null, 'pdf_path' => null])->save();
                $request->forceFill(['current_run_id' => $run->getKey(), 'status' => 'error', 'last_error' => $message])->save();
            }

            return $run->refresh();
        });
    }

    /**
     * The dwelling as the providers see it. Surface: built area including the share of common elements, WITHOUT
     * annexes (garage, storage), which are adjustments; Catastro wins when it is close to what the visitor wrote.
     *
     * @return array<string, mixed>
     */
    public function subject(ValuationRequest $request): array
    {
        $p = (array) $request->property;
        $cat = is_array($request->catastro) && isset($request->catastro['rc']) ? $request->catastro : null;
        $num = fn (mixed $v): ?float => is_numeric($v) && (float) $v > 0 ? (float) $v : null;

        $useful = $num($p['superficie_util_m2'] ?? null);
        $built = $num($p['superficie_construida_m2'] ?? null);
        $catBuilt = $num($cat['superficie_construida_m2'] ?? null);
        $catCommon = (float) ($cat['superficie_elementos_comunes_m2'] ?? 0);
        $catTotal = $num($cat['superficie_total_catastro_m2'] ?? null);
        $annexes = array_sum(array_column((array) ($cat['anexos'] ?? []), 'superficie_m2'));
        // A single-family house is sold (and recorded by notaries) with its whole built area: garage and storage rooms
        // inside the building are part of it, so the total counts and they get no separate adjustment.
        $singleFamily = ($p['tipo'] ?? null) === 'casa' || str_contains(mb_strtolower((string) ($cat['tipologia'] ?? '')), 'unifamiliar');
        if ($singleFamily && $catTotal !== null) {
            $catBuilt = $catTotal;
            $annexes = 0;
        }

        $declared = match (true) {
            $built !== null => ($p['incluye_elementos_comunes'] ?? false) ? $built : $built + $catCommon,
            $useful !== null => round($useful * self::USEFUL_TO_BUILT, 1),
            default => null,
        };
        $origin = $built !== null ? 'construida_declarada' : ($useful !== null ? 'util_convertida' : null);
        $m2 = $declared;
        $note = $origin === 'util_convertida' ? 'Superficie construida estimada a partir de la útil indicada ('.self::n($useful).' m² × '.self::n(self::USEFUL_TO_BUILT).').' : null;
        $discrepancy = null;

        if ($catBuilt !== null) {
            $diff = $declared === null ? 0 : abs($declared - $catBuilt) / $catBuilt;
            [$m2, $origin, $note] = [$catBuilt, 'catastro', $singleFamily && $cat['anexos'] !== [] ? 'Vivienda unifamiliar: se calcula con toda la superficie construida de Catastro ('.self::n($catBuilt).' m²), que incluye garaje y almacenes; por eso no se añaden ajustes por ellos.' : null];
            if ($declared !== null && $diff > 0.10) {
                if ($annexes > 0 && $catTotal !== null && abs($declared - $catTotal) / $catTotal <= 0.08) {
                    $note = 'La superficie indicada ('.self::n($declared).' m²) coincide con el total de Catastro, que incluye anexos ('.self::n($annexes).' m² de garaje, trastero u otros). Se calcula con la vivienda y su parte de elementos comunes ('.self::n($catBuilt).' m²); los anexos se valoran aparte y no se suman dos veces.';
                    $discrepancy = 'anexos';
                } elseif ($diff > 0.35) {
                    $discrepancy = 'excesiva';
                } else {
                    $note = 'La superficie indicada ('.self::n($declared).' m²) difiere un '.self::n(round($diff * 100)).' % de la de Catastro ('.self::n($catBuilt).' m² con elementos comunes). Se usa la de Catastro; el equipo puede revisarla si hay obras o ampliaciones no inscritas.';
                    $discrepancy = 'superficie';
                }
            } elseif ($declared === null) {
                $note = 'Superficie según Catastro: '.self::n($catBuilt).' m² construidos con elementos comunes'.($annexes > 0 ? ', sin anexos ('.self::n($annexes).' m², valorados aparte).' : '.');
            }
        }

        $floor = $p['planta'] ?? $cat['planta'] ?? null;
        $floor = match (true) {
            $floor === null || $floor === '' => null,
            is_numeric($floor) => (int) $floor,
            (bool) preg_match('/^(B|BJ|BAJO|BAJA|PB|00|B0)$/i', trim((string) $floor)) => 0,
            (bool) preg_match('/^-?\d+/', trim((string) $floor), $m) => (int) $m[0],
            default => null,
        };
        $garage = $p['garaje'] ?? null;
        if ($garage === null && collect((array) ($cat['anexos'] ?? []))->contains('tipo', 'garaje')) {
            $garage = 'incluido';
        }

        return [
            'tipo' => (string) ($p['tipo'] ?? 'piso'),
            'rc' => $cat['rc'] ?? ($p['rc'] ?? null),
            'direccion' => (string) ($p['direccion_confirmada'] ?? $cat['direccion'] ?? ''),
            'municipio' => (string) ($cat['municipio'] ?? $p['municipio'] ?? ''),
            'ine' => $cat['municipio_ine'] ?? null,
            'codigo_postal' => $p['codigo_postal'] ?? $cat['codigo_postal'] ?? null,
            'coordenadas' => $cat['coordenadas'] ?? null,
            'catastro' => $cat !== null,
            'catastro_fuente' => $cat['fuente'] ?? null,
            'uso_catastro' => $cat['uso'] ?? null,
            'superficie_m2' => $m2,
            'superficie_origen' => $origin,
            'superficie_declarada_m2' => $declared,
            'superficie_catastro_m2' => $catBuilt,
            'superficie_nota' => $note,
            'anexos_en_superficie' => $singleFamily && $catTotal !== null,
            'discrepancia' => $discrepancy,
            'planta' => $floor,
            'ascensor' => isset($p['ascensor']) ? (bool) $p['ascensor'] : null,
            'exterior' => isset($p['exterior']) ? (bool) $p['exterior'] : null,
            'terraza_m2' => $num($p['terraza_m2'] ?? null),
            'garaje' => $garage,
            'trastero' => isset($p['trastero']) ? (bool) $p['trastero'] : collect((array) ($cat['anexos'] ?? []))->contains('tipo', 'trastero'),
            'conservacion' => $p['conservacion'] ?? null,
            'ano' => $cat['ano_construccion'] ?? ($p['ano_construccion'] ?? null),
            'dormitorios' => $p['dormitorios'] ?? null,
            'banos' => $p['banos'] ?? null,
        ];
    }

    /** @return list<ValuationProvider> enabled AVMs first, CLARTO's estimator last */
    public function providers(): array
    {
        $settings = ValuationSetting::current();
        $external = [];
        foreach (ValuationSetting::PROVIDERS as $key) {
            $config = $settings->provider($key);
            if ($config['driver'] !== 'off') {
                $external[] = new ExternalAvm($key, $config);
            }
        }

        return [...$external, new InHouseEstimator];
    }

    /**
     * @param  array<string, mixed>  $subject
     * @return array{0: array<string, mixed>, 1: ?ValuationProvider, 2: list<array<string, string>>}
     */
    public function estimate(array $subject): array
    {
        $errors = [];
        $revision = null;
        foreach ($this->providers() as $provider) {
            try {
                $answer = $provider->estimate($subject);
            } catch (Throwable $e) {
                Log::warning('Valoraciones: proveedor fallido', ['proveedor' => $provider->key(), 'error' => $e->getMessage()]);
                $errors[] = ['proveedor' => $provider->key(), 'nombre' => $provider->name(), 'error' => mb_strimwidth($e->getMessage(), 0, 300, '…'), 'fecha' => now()->toIso8601String()];

                continue;
            }
            if (($answer['estado'] ?? null) === 'estimada') {
                foreach ($errors as $error) {
                    $answer['limitaciones'][] = __('clarto.valoraciones.proveedor.respaldo', ['p' => $error['nombre'], 'usado' => $provider->name()]);
                }
                $answer['proveedor'] = $provider->key();

                return [$answer, $provider, $errors];
            }
            $revision ??= $answer;
        }

        return [$revision ?? ['estado' => 'revision', 'motivo' => __('clarto.valoraciones.revision.insuficiente'), 'codigo' => 'insuficiente'], null, $errors];
    }

    /** AI summary from the computed result only; replaces the template and the PDF. Silent when it cannot. */
    public function improveSummary(ValuationRun $run): void
    {
        if (! config('valoraciones.resumen_ia', true) || $run->status !== 'estimada' || $run->summary_source === 'ia') {
            return;
        }
        $result = (array) $run->result;
        $facts = array_filter([
            'precio_central_eur' => $result['precio_central'] ?? null, 'intervalo_eur' => $result['intervalo'] ?? null, 'eur_m2' => $result['eur_m2'] ?? null,
            'tipo_precio' => $result['tipo_precio'] ?? null, 'suficiencia' => $result['suficiencia'] ?? null, 'municipio' => $run->request->catastro['municipio'] ?? $run->request->property['municipio'] ?? null,
            'calculo' => $result['calculo'] ?? null, 'fuentes' => array_map(fn (array $f): array => array_intersect_key($f, array_flip(['nombre', 'tipo', 'fecha_datos'])), (array) ($result['fuentes'] ?? [])),
            'limitaciones' => $result['limitaciones'] ?? null,
        ]);
        $json = json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            $text = trim(AiClient::make()->complete('writing',
                'Eres redactor de CLARTO, agencia inmobiliaria. Escribe en español de España un resumen de 3 o 4 frases (máximo 110 palabras) de una estimación automática de precio de venta para su propietario. Usa SOLO los datos del JSON: no inventes cifras, fuentes ni características. Di que es una estimación orientativa, explica en una frase de qué se ha partido y menciona el intervalo. Sin saludos, sin títulos, sin listas, sin markdown.',
                (string) $json)['content']);
        } catch (Throwable $e) {
            Log::info('Valoraciones: resumen IA no disponible, se mantiene la plantilla', ['run' => $run->getKey(), 'error' => $e->getMessage()]);

            return;
        }

        if (! self::onlyKnownNumbers($text, (string) $json) || mb_strlen($text) < 120 || mb_strlen($text) > 1000 || str_contains($text, '#')) {
            Log::info('Valoraciones: resumen IA descartado (cifras no presentes en el cálculo o formato)', ['run' => $run->getKey()]);

            return;
        }
        $result['resumen'] = $text;
        $run->forceFill(['result' => $result, 'summary' => $text, 'summary_source' => 'ia'])->save();
        $this->storePdf($run->request, $run);
    }

    public function storePdf(ValuationRequest $request, ValuationRun $run): void
    {
        $pdf = ValuationReport::render($request, $run);
        $path = 'valoraciones/'.($request->reference ?: $request->request_id).'/informe-'.$run->number.'.pdf';
        Storage::disk(self::DISK)->put($path, $pdf);
        $run->forceFill(['pdf_path' => $path, 'pdf_pages' => BrandPdf::countPages($pdf)])->save();
    }

    /** Every number of 3+ digits in the text appears in the facts (thousand dots and decimals normalised). */
    public static function onlyKnownNumbers(string $text, string $facts): bool
    {
        $numbers = fn (string $s): array => array_map(fn (string $n): string => ltrim(str_replace(['.', ','], ['', '.'], $n), '0'), preg_match_all('/\d[\d.,]*\d|\d/', $s, $m) ? $m[0] : []);
        $known = array_flip(array_merge($numbers($facts), array_map(fn ($n) => (string) (int) $n, $numbers($facts))));
        foreach ($numbers($text) as $n) {
            if (strlen((string) (int) $n) >= 3 && ! isset($known[$n]) && ! isset($known[(string) (int) $n])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Deterministic summary (also the fallback when the AI is off or fails).
     *
     * @param  array<string, mixed>  $r
     * @param  array<string, mixed>  $s
     */
    public function templateSummary(ValuationRequest $request, array $r, array $s): string
    {
        $eur = fn ($v): string => number_format((float) $v, 0, ',', '.').' €';
        $calc = (array) ($r['calculo'] ?? []);
        $where = trim(($s['direccion'] ?: 'la vivienda').($s['municipio'] ? ' ('.$s['municipio'].')' : ''));
        $level = ['alta' => 'alta: hay datos recientes de la zona y el inmueble está identificado en Catastro', 'media' => 'media: el resultado es razonable, pero conviene revisarlo con el equipo', 'baja' => 'baja: los datos disponibles son limitados y el intervalo es amplio'][$r['suficiencia']] ?? $r['suficiencia'];

        $text = "Estimamos que {$where} podría venderse en torno a {$eur($r['precio_central'])} (precio de cierre), con un intervalo razonable entre {$eur($r['intervalo']['min'])} y {$eur($r['intervalo']['max'])}. ";
        if (isset($calc['base_eur_m2'])) {
            $adj = (float) ($calc['ajuste_total'] ?? 0);
            $text .= 'El cálculo parte de un precio de cierre de '.$eur($calc['base_eur_m2']).'/m² en '.($calc['base_ambito'] ?? 'la zona').' ('.($calc['base_fuente'] ?? '').', '.($calc['base_periodo'] ?? '').') aplicado a '.self::n((float) $calc['superficie_m2']).' m² construidos'
                .($adj != 0.0 ? ', con un ajuste total del '.($adj > 0 ? '+' : '').self::n(round($adj * 100, 1)).' % por las características indicadas' : '').'. ';
        } else {
            $text .= 'El valor procede del modelo automático '.($r['calculo']['metodo'] ?? '').'. ';
        }

        return $text."La suficiencia de los datos es {$level}. Es una estimación automática orientativa: el equipo de CLARTO puede revisar el inmueble para ajustar el resultado.";
    }

    private function refreshCatastro(ValuationRequest $request, bool $force): void
    {
        $rc = (string) ($request->property['rc'] ?? '');
        if (strlen($rc) !== 20 || (! $force && is_array($request->catastro) && isset($request->catastro['rc']))) {
            return;
        }
        try {
            $detail = $this->catastro->detail($rc);
            $request->catastro = $detail ?? ['error' => 'no_encontrada', 'rc' => null, 'consultado' => now()->toIso8601String()];
        } catch (CatastroUnavailable) {
            // Keep the previous detail on a recalculation; otherwise estimate without Catastro (lower sufficiency).
            if (! (is_array($request->catastro) && isset($request->catastro['rc']))) {
                $request->catastro = ['error' => 'catastro_no_disponible', 'consultado' => now()->toIso8601String()];
            }
        }
        $request->save();
    }

    private static function n(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',');
    }
}
