<?php

declare(strict_types=1);

namespace App\Valuations;

use Carbon\CarbonImmutable;

/**
 * CLARTO's own estimate (methodology in docs/clarto/valoraciones.md, «Estimador CLARTO» VERSION):
 *
 *  1. Base €/m² of the postcode or municipality from dated public statistics (resources/valoraciones/precios.json),
 *     only from sources whose reuse is authorised (others only with valoraciones.fuentes_pendientes_licencia, for
 *     internal tests): closing prices first, then appraisals converted to closing with the open-data factor
 *     (appraisalToClosing()), then offers converted with OFFER_TO_CLOSING.
 *  2. Surface: built area including the share of common elements, without annexes (ValuationEngine::subject()).
 *  3. Adjustments for condition, floor/lift, exterior, terrace, age and annexes (ADJUSTMENTS), capped.
 *  4. Interval from the data sufficiency, the spread between sources and surface discrepancies.
 *
 * Not enough data (no series for the area, not a dwelling, no or implausible surface) → «revision» with the reason.
 */
final class InHouseEstimator implements ValuationProvider
{
    public const VERSION = '1.0.0';

    /** Offer (asking) price → closing price. ponytail: one national figure; per-area gaps when a source publishes them. */
    public const OFFER_TO_CLOSING = -0.10;

    /** Adjustments on the base €/m² (fractions). */
    public const ADJUSTMENTS = [
        'conservacion' => ['a_reformar' => -0.12, 'buen_estado' => 0.0, 'reformado' => 0.06, 'obra_nueva' => 0.12],
        'bajo' => -0.06,
        'sin_ascensor' => [1 => -0.02, 2 => -0.05, 3 => -0.10],   // floor 3 and above
        'alto_con_ascensor' => 0.02,                              // floor 4 and above with lift
        'atico' => 0.05,
        'interior' => -0.05,
        'terraza' => [4 => 0.02, 10 => 0.04],                     // from m²
        'antiguedad' => [60 => -0.05, 40 => -0.03],               // years, when not refurbished
        'reciente' => 0.03,                                        // 10 years or less
        'garaje' => 0.05,
        'trastero' => 0.015,
    ];

    public const CAP = [-0.30, 0.25];

    /** Half-width of the interval by sufficiency. */
    public const MARGIN = ['alta' => 0.08, 'media' => 0.12, 'baja' => 0.18];

    private const RESIDENTIAL = ['piso', 'casa', 'atico', 'duplex', 'estudio'];

    private const LEVELS = [3 => 'alta', 2 => 'media', 1 => 'baja'];

    /** @var array<string, mixed>|null */
    private static ?array $data = null;

    public function key(): string
    {
        return 'clarto';
    }

    public function name(): string
    {
        return 'Estimador CLARTO';
    }

    public function simulated(): bool
    {
        return false;
    }

    /** @return array<string, mixed> */
    public static function data(): array
    {
        return self::$data ??= (array) json_decode((string) file_get_contents(resource_path('valoraciones/precios.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * The price area of a municipality: by INE code, else by name or alias.
     *
     * @return array<string, mixed>|null
     */
    public static function area(?string $ine, ?string $municipality): ?array
    {
        $areas = (array) (self::data()['ambitos'] ?? []);
        if ($ine !== null && isset($areas[$ine])) {
            return $areas[$ine];
        }
        $name = Catastro::normalize((string) $municipality);
        $code = self::data()['alias'][$name] ?? collect($areas)->search(fn (array $a): bool => Catastro::normalize((string) ($a['nombre'] ?? '')) === $name);

        return $code !== false && $code !== null ? ($areas[$code] ?? null) : null;
    }

    /** Appraisal → closing price factor (open data, see precios.json «calibracion»). */
    public static function appraisalToClosing(): float
    {
        return (float) (self::data()['calibracion']['tasacion_a_cierre']['factor'] ?? 1.0);
    }

    public function estimate(array $subject): array
    {
        $s = $subject;
        $revision = fn (string $reason, array $extra = []): array => ['estado' => 'revision', 'motivo' => __('clarto.valoraciones.revision.'.$reason, $extra), 'codigo' => $reason];

        if (! in_array($s['tipo'] ?? null, self::RESIDENTIAL, true) || (filled($s['uso_catastro'] ?? null) && $s['uso_catastro'] !== 'Residencial')) {
            return $revision('no_vivienda');
        }
        $m2 = (float) ($s['superficie_m2'] ?? 0);
        if ($m2 <= 0) {
            return $revision('sin_superficie');
        }
        if ($m2 < 20 || $m2 > 1000) {
            return $revision('superficie_atipica', ['m2' => self::n($m2)]);
        }
        if (($s['discrepancia'] ?? null) === 'excesiva') {
            return $revision('discrepancia', ['declarada' => self::n((float) $s['superficie_declarada_m2']), 'catastro' => self::n((float) $s['superficie_catastro_m2'])]);
        }

        // 1. Series of the postcode (more precise) and of the municipality. Sources pending a licence only in tests.
        $area = self::area($s['ine'] ?? null, $s['municipio'] ?? null);
        $postcode = $area['codigos_postales'][(string) ($s['codigo_postal'] ?? '')] ?? null;
        $allowPending = (bool) config('valoraciones.fuentes_pendientes_licencia', false);
        $all = collect((array) ($postcode['series'] ?? []))->map(fn (array $x): array => [...$x, 'nivel' => 0, 'ambito' => $postcode['nombre']])
            ->merge(collect((array) ($area['series'] ?? []))->map(fn (array $x): array => [...$x, 'nivel' => 1, 'ambito' => $area['nombre']]))
            ->filter(fn (array $x): bool => (float) ($x['eur_m2'] ?? 0) > 0)
            ->map(fn (array $x): array => [...$x, 'tipo' => self::source($x['fuente'])['tipo'], 'uso' => self::source($x['fuente'])['uso'], 'fin' => self::periodEnd((string) ($x['periodo'] ?? ''))]);
        $series = $all->filter(fn (array $x): bool => $x['uso'] === 'autorizado' || $allowPending)->values();
        if ($series->isEmpty()) {
            return $all->isEmpty()
                ? $revision('sin_datos', ['municipio' => $area['nombre'] ?? $s['municipio'] ?? '—'])
                : $revision('sin_datos_autorizados', ['municipio' => $area['nombre'] ?? $s['municipio']]);
        }

        // Base: closing prices first, then appraisals, then offers; the most specific area first. All to closing price.
        $rank = ['compraventa' => 0, 'tasacion' => 1, 'oferta' => 2];
        $toClosing = fn (array $x): float => (float) $x['eur_m2'] * match ($x['tipo']) {
            'tasacion' => self::appraisalToClosing(),
            'oferta' => 1 + self::OFFER_TO_CLOSING,
            default => 1.0,
        };
        $base = $series->sortBy(fn (array $x): array => [$rank[$x['tipo']] ?? 9, $x['nivel']])->first();
        $baseM2 = $toClosing($base);
        $other = $series->first(fn (array $x): bool => $x['fuente'] !== $base['fuente'] && isset($rank[$x['tipo']]));
        $otherM2 = $other === null ? null : $toClosing($other);
        $spread = $otherM2 === null ? 0.0 : abs($baseM2 - $otherM2) / (($baseM2 + $otherM2) / 2);

        // 2. Sufficiency.
        $limits = [];
        $score = 3;
        if (! ($s['catastro'] ?? false)) {
            $score--;
            $limits[] = 'No se ha podido confirmar el inmueble en Catastro: superficie y año según los datos aportados.';
        }
        if (($s['discrepancia'] ?? null) === 'superficie') {
            $score--;
        }
        if (isset($base['muestras']) && (int) $base['muestras'] < 100) {
            $score--;
            $limits[] = "La serie de precios de la zona se basa en pocas operaciones ({$base['muestras']}).";
        }
        if ($base['fin'] === null) {
            $limits[] = "La fuente no publica la fecha exacta del periodo ({$base['periodo']}).";
        } elseif (($months = (int) $base['fin']->diffInMonths(CarbonImmutable::now(), true)) > 12) {
            $score--;
            $limits[] = "Los datos de precio más recientes son de {$base['periodo']} (hace {$months} meses).";
        }
        if ($spread > 0.25) {
            $score--;
        }
        if ($base['tipo'] === 'oferta') {
            $score--;
            $limits[] = 'Solo hay precios de oferta para esta zona: se han convertido a precio de cierre con un descuento del '.self::pct(-self::OFFER_TO_CLOSING).'.';
        }
        if ($base['tipo'] === 'tasacion') {
            $limits[] = 'El precio base es el valor de tasación medio de '.$base['ambito'].', convertido a precio de cierre con el factor '.number_format(self::appraisalToClosing(), 3, ',', '.').' (compraventas registradas / valor tasado, provincia de Valencia).';
        }
        if ($base['nivel'] === 1 && $postcode !== null) {
            $score--;   // the municipality mixes areas whose prices differ (e.g. Gandia city and beach)
            $limits[] = 'Existen precios por código postal ('.$s['codigo_postal'].') más precisos, pendientes de licencia: se usa la media de '.$area['nombre'].', que mezcla zonas de precios distintos.';
        }
        foreach ($series->whereIn('fuente', array_filter([$base['fuente'], $other['fuente'] ?? null]))->where('uso', '!=', 'autorizado')->pluck('fuente')->unique() as $pending) {
            $limits[] = 'PRUEBA INTERNA: incluye datos de '.self::source($pending)['nombre'].' cuyo uso comercial requiere licencia.';
        }
        if ($score < 1) {
            return $revision('insuficiente');
        }
        $level = self::LEVELS[min(3, $score)];

        // 3. Adjustments.
        $adj = self::adjustments($s);
        $total = max(self::CAP[0], min(self::CAP[1], array_sum(array_column($adj, 'valor'))));
        $eurM2 = $baseM2 * (1 + $total);
        $central = self::round($eurM2 * $m2);

        // 4. Interval.
        $margin = max(self::MARGIN[$level], $spread / 2) + (($s['discrepancia'] ?? null) === 'superficie' ? 0.04 : 0);
        foreach (['conservacion', 'planta'] as $field) {
            if (($s[$field] ?? null) === null && $s['tipo'] !== 'casa') {
                $margin += 0.01;
            }
        }

        if (filled($s['superficie_nota'] ?? null)) {
            $limits[] = $s['superficie_nota'];
        }
        if ($spread > 0.15) {
            $limits[] = 'Las fuentes estadísticas difieren un '.self::pct($spread).' entre sí: el intervalo se ha ampliado.';
        }
        if (($s['conservacion'] ?? null) === null) {
            $limits[] = 'No se ha indicado el estado de conservación: se supone «buen estado».';
        }
        $limits[] = 'Los precios estadísticos son medias de la zona: calidades, orientación, vistas, eficiencia energética y cargas no se pueden valorar sin visitar el inmueble.';

        $sources = [];
        if (($s['catastro'] ?? false) && is_array($s['catastro_fuente'] ?? null)) {
            $f = $s['catastro_fuente'];
            $sources[] = ['nombre' => $f['nombre'].' (referencia '.$s['rc'].')', 'tipo' => 'catastro', 'ambito' => 'Inmueble', 'fecha_datos' => substr((string) $f['consultado'], 0, 10), 'licencia' => $f['licencia'], 'url' => $f['url']];
        }
        foreach (array_filter([$base, $other]) as $x) {
            $src = self::source($x['fuente']);
            $sources[] = [
                'nombre' => $src['nombre'], 'tipo' => $src['tipo'], 'ambito' => $x['ambito'],
                'fecha_datos' => (string) $x['periodo'], 'licencia' => $src['licencia'], 'url' => $src['url'],
                'valor' => number_format((float) $x['eur_m2'], 0, ',', '.').' €/m²'.(isset($x['muestras']) ? ' · '.number_format((float) $x['muestras'], 0, ',', '.').' operaciones' : ''),
            ];
        }
        if (in_array('tasacion', [$base['tipo'], $other['tipo'] ?? null], true)) {
            $calibration = (array) (self::data()['calibracion']['tasacion_a_cierre'] ?? []);
            $src = self::source('registradores_odbl');
            $sources[] = ['nombre' => $src['nombre'], 'tipo' => $src['tipo'], 'ambito' => 'Provincia de Valencia (factor de tasación a cierre)', 'fecha_datos' => '2026T2, acumulado 12 meses',
                'licencia' => $src['licencia'], 'url' => $src['url'], 'valor' => rtrim((string) ($calibration['explicacion'] ?? ''), '.')];
        }

        return [
            'estado' => 'estimada',
            'precio_central' => $central,
            'intervalo' => ['min' => self::round($central * (1 - $margin)), 'max' => self::round($central * (1 + $margin))],
            'tipo_precio' => 'cierre',
            'moneda' => 'EUR',
            'eur_m2' => (int) round($central / $m2),
            'suficiencia' => $level,
            'fecha' => now()->toIso8601String(),
            'version_calculo' => self::VERSION,
            'version_datos' => (string) (self::data()['version'] ?? ''),
            'fuentes' => $sources,
            'limitaciones' => $limits,
            'calculo' => [
                'metodo' => 'Precio medio estadístico de la zona ajustado (Estimador CLARTO '.self::VERSION.')',
                'base_eur_m2' => (int) round($baseM2),
                'base_tipo' => $base['tipo'],
                'base_fuente' => self::source($base['fuente'])['nombre'],
                'base_ambito' => $base['ambito'],
                'base_eur_m2_publicado' => (float) $base['eur_m2'],
                'base_periodo' => $base['periodo'],
                'contraste_eur_m2' => $otherM2 === null ? null : (int) round($otherM2),
                'superficie_m2' => round($m2, 1),
                'superficie_origen' => $s['superficie_origen'],
                'ajustes' => $adj,
                'ajuste_total' => round($total, 4),
                'margen' => round($margin, 4),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $s
     * @return list<array{concepto: string, valor: float}>
     */
    public static function adjustments(array $s): array
    {
        $a = self::ADJUSTMENTS;
        $out = [];
        $add = function (string $concept, float $value) use (&$out): void {
            if ($value != 0.0) {
                $out[] = ['concepto' => $concept, 'valor' => $value];
            }
        };
        $state = $s['conservacion'] ?? null;
        if ($state !== null && isset($a['conservacion'][$state])) {
            $add('Estado: '.str_replace('_', ' ', (string) $state), $a['conservacion'][$state]);
        }

        $flat = in_array($s['tipo'], ['piso', 'atico', 'duplex', 'estudio'], true);
        $floor = $s['planta'] ?? null;
        if ($flat && $floor !== null) {
            if ($floor <= 0) {
                $add('Planta baja', $a['bajo']);
            } elseif (($s['ascensor'] ?? null) === false) {
                $add("Planta {$floor} sin ascensor", $a['sin_ascensor'][min(3, $floor)]);
            } elseif (($s['ascensor'] ?? null) === true && $floor >= 4) {
                $add("Planta {$floor} con ascensor", $a['alto_con_ascensor']);
            }
        }
        if ($s['tipo'] === 'atico') {
            $add('Ático', $a['atico']);
        }
        if (($s['exterior'] ?? null) === false) {
            $add('Vivienda interior', $a['interior']);
        }
        $terrace = (float) ($s['terraza_m2'] ?? 0);
        $add('Terraza de '.self::n($terrace).' m²', $terrace >= 10 ? $a['terraza'][10] : ($terrace >= 4 ? $a['terraza'][4] : 0.0));

        $age = is_numeric($s['ano'] ?? null) ? (int) date('Y') - (int) $s['ano'] : null;
        if ($age !== null && ! in_array($state, ['reformado', 'obra_nueva'], true)) {
            $add("Antigüedad ({$age} años)", $age >= 60 ? $a['antiguedad'][60] : ($age >= 40 ? $a['antiguedad'][40] : ($age <= 10 ? $a['reciente'] : 0.0)));
        }
        $inArea = (bool) ($s['anexos_en_superficie'] ?? false);   // a house: garage and storage are in its built area
        if (! $inArea && ($s['garaje'] ?? null) === 'incluido') {
            $add('Plaza de garaje incluida', $a['garaje']);
        }
        if (! $inArea && ($s['trastero'] ?? false) === true) {
            $add('Trastero incluido', $a['trastero']);
        }

        return $out;
    }

    /** @return array{nombre: string, tipo: string, uso: string, licencia: string, url: string} */
    public static function source(string $key): array
    {
        $src = (array) (self::data()['fuentes'][$key] ?? []);

        return ['nombre' => (string) ($src['nombre'] ?? $key), 'tipo' => (string) ($src['tipo'] ?? 'oferta'), 'uso' => (string) ($src['uso'] ?? 'pendiente_licencia'), 'licencia' => (string) ($src['licencia'] ?? '—'), 'url' => (string) ($src['url'] ?? '')];
    }

    /** «2026T2» → 30/06/2026, «2025» → 31/12/2025, «2026-09» → 30/09/2026. */
    public static function periodEnd(string $period): ?CarbonImmutable
    {
        return match (true) {
            (bool) preg_match('/^(\d{4})T([1-4])$/', $period, $m) => CarbonImmutable::create((int) $m[1], (int) $m[2] * 3, 1)->endOfMonth()->startOfDay(),
            (bool) preg_match('/^(\d{4})-(\d{2})$/', $period, $m) => CarbonImmutable::create((int) $m[1], (int) $m[2], 1)->endOfMonth()->startOfDay(),
            (bool) preg_match('/^(\d{4})$/', $period, $m) => CarbonImmutable::create((int) $m[1], 12, 31),
            default => null,
        };
    }

    private static function round(float $value): int
    {
        return (int) (round($value / 1000) * 1000);
    }

    private static function n(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, ',', '.'), '0'), ',');
    }

    private static function pct(float $fraction): string
    {
        return self::n(round($fraction * 100, 1)).' %';
    }
}
