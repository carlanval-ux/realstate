<?php

declare(strict_types=1);

namespace App\Valuations;

use Carbon\CarbonImmutable;

/**
 * CLARTO's own estimate, methodology v2 (docs/clarto/valoraciones.md; v1: orquestacion/valoraciones-investigacion.md §4.1):
 *
 *   closing price = €/m² base × built m² (with common elements) × Π(1 + adjustment) [+ annexes]
 *
 *  0. Foral cadastre (Álava, Gipuzkoa, Bizkaia, Navarra: not in the State Catastro) → review («catastro_foral»).
 *  1. Zone, anywhere in Spain: postcode series (≥ muestra_cp_min operations) → municipal series (≥ muestra_municipio_min)
 *     → province: context only, never a price (the provincial mean is pulled up by the large towns and does not represent
 *     a small municipality) → review «sin_datos_zona» with the provincial figures for the team. Only sources whose
 *     automated reuse is permitted (precios.json «uso: autorizado»); the others only with
 *     valoraciones.fuentes_pendientes_licencia (internal tests, flagged). A postcode marked «excepcion» (the municipal
 *     mean does not represent it) goes to review unless it has its own usable series.
 *  2. €/m²: closing prices > appraisals (MIVAU, column by building age if its sample is enough, × appraisalToClosing(),
 *     indexed with the INE IPV of the region when the IPV has a later quarter) > offers (− 10 %).
 *  3. Adjustments (ADJUSTMENTS, product capped at ±30 % or review).
 *  4. Sufficiency in points (sufficiency()); interval from it.
 * Not enough data → «revision» with the reason; every figure and source is kept in the result.
 */
final class InHouseEstimator implements ValuationProvider
{
    public const VERSION = '2.0.0';

    /** Offer (asking) → closing. Reasoned assumption; no licensed offer series is used today. */
    public const OFFER_TO_CLOSING = -0.10;

    /**
     * Adjustments v1 (fractions on €/m²). Initial hypotheses of the methodology, to calibrate with CLARTO's closed
     * sales and, once contracted, with the AVM; justification of each in docs/clarto/valoraciones.md.
     */
    public const ADJUSTMENTS = [
        'conservacion' => ['a_reformar' => -0.15, 'buen_estado' => 0.0, 'reformado' => 0.07, 'obra_nueva' => 0.10],
        'bajo' => -0.07,
        'sin_ascensor_1_2' => -0.03,
        'sin_ascensor_3' => -0.10,
        'sin_ascensor_por_planta_extra' => -0.03,   // each floor above the 3rd without lift
        'atico_con_ascensor' => 0.05,
        'interior' => -0.05,
        'terraza_10' => 0.03,
        'terraza_10_costa' => 0.05,
        'antiguedad' => ['antes_1960' => -0.05, '1960_1980' => -0.03, '1981_2005' => 0.0, 'desde_2006' => 0.03],
        'tamano' => ['menos_50' => 0.05, 'mas_120' => -0.05, 'mas_180' => -0.10],
        'garaje' => 0.05,      // provisional until fixed amounts per municipality (precios.json «anexos»)
        'trastero' => 0.015,   // idem
    ];

    public const ADJUSTMENT_LIMIT = 0.30;

    /** Interval half-width by sufficiency; + MARGIN_MUNICIPAL when the base is not a postcode; + MARGIN_DISCREPANCY. */
    public const MARGIN = ['alta' => 0.08, 'media' => 0.12, 'baja' => 0.18];

    public const MARGIN_MUNICIPAL = 0.02;

    public const MARGIN_DISCREPANCY = 0.05;

    public const MARGIN_MAX = 0.25;

    public const MAX_M2 = 300;

    private const RESIDENTIAL = ['piso', 'casa', 'atico', 'duplex', 'estudio'];

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
     * The price area of a municipality: by INE code (Catastro), else by alias or by name — any official spelling
     * («Alacant/Alicante», «Puerto de Santa María, El» = «El Puerto de Santa María») — within the province when known.
     *
     * @return array<string, mixed>|null
     */
    public static function area(?string $ine, ?string $municipality, ?string $province = null): ?array
    {
        $areas = (array) (self::data()['ambitos'] ?? []);
        if ($ine !== null && isset($areas[$ine])) {
            return $areas[$ine];
        }
        if ($ine !== null && $ine !== '') {
            return null;   // Catastro said which municipality it is: no name guessing
        }
        $name = Catastro::normalize((string) $municipality);
        $code = self::data()['alias'][$name] ?? null;
        if ($code === null && $name !== '') {
            $hits = collect($areas)->filter(fn (array $a, int|string $c): bool => ($province === null || str_starts_with((string) $c, $province))
                && in_array($name, self::spellings((string) ($a['nombre'] ?? '')), true));
            $code = $hits->count() === 1 ? $hits->keys()->first() : null;
        }

        return $code !== null ? ($areas[$code] ?? null) : null;
    }

    /** @return list<string> normalised spellings of an official name */
    private static function spellings(string $name): array
    {
        $out = [];
        foreach ([$name, ...explode('/', $name)] as $part) {
            $part = trim($part);
            $out[] = Catastro::normalize($part);
            if (preg_match("/^(.*),\s*(el|la|los|las|l'|els|les|es|sa|ses|a|o|os|as)$/iu", $part, $m)) {
                $out[] = Catastro::normalize($m[2].' '.$m[1]);
            }
        }

        return array_values(array_unique($out));
    }

    public static function appraisalToClosing(): float
    {
        return (float) (self::data()['calibracion']['tasacion_a_cierre']['factor'] ?? 1.0);
    }

    public function estimate(array $subject): array
    {
        $s = $subject;
        // «codigo» is the stable reason for the web, the delivery texts and GHL; the message may be more specific.
        $revision = fn (string $code, array $extra = [], ?string $message = null): array => ['estado' => 'revision', 'motivo' => __('clarto.valoraciones.revision.'.($message ?? $code), $extra), 'codigo' => $code];
        $limits = [];
        $province = Provinces::code($s['provincia'] ?? null);

        if (! in_array($s['tipo'] ?? null, self::RESIDENTIAL, true) || (filled($s['uso_catastro'] ?? null) && $s['uso_catastro'] !== 'Residencial')) {
            return $revision('no_vivienda');
        }
        // 0. Álava, Gipuzkoa, Bizkaia, Navarra: their cadastres are not in the State Catastro and none publishes the
        // dwelling's surfaces in an open service, so the dwelling cannot be checked: the team values it.
        if (Provinces::foral($province) && ! ($s['catastro'] ?? false)) {
            return $revision('catastro_foral', ['provincia' => Provinces::name($province)]);
        }
        $m2 = (float) ($s['superficie_m2'] ?? 0);
        if ($m2 <= 0) {
            return $revision('sin_superficie');
        }
        if ($m2 < 20 || $m2 > self::MAX_M2) {
            return $revision('superficie_atipica', ['m2' => self::n($m2)]);
        }
        if (($s['discrepancia'] ?? null) === 'excesiva') {
            return $revision('discrepancia', ['declarada' => self::n((float) $s['superficie_declarada_m2']), 'catastro' => self::n((float) $s['superficie_catastro_m2'])]);
        }

        // 1. Zone: postcode → municipality, usable series only; the province is context, never the price.
        $thresholds = (array) (self::data()['umbrales'] ?? []);
        $area = self::area($s['ine'] ?? null, $s['municipio'] ?? null, $province);
        $postcode = $area['codigos_postales'][(string) ($s['codigo_postal'] ?? '')] ?? null;
        $allowPending = (bool) config('valoraciones.fuentes_pendientes_licencia', false);
        $prepare = fn (array $series, int $level, string $scope): array => array_map(fn (array $x): array => [...$x, 'nivel' => $level, 'ambito' => $scope,
            'tipo' => self::source($x['fuente'])['tipo'], 'uso' => self::source($x['fuente'])['uso'], 'fin' => self::periodEnd((string) ($x['periodo'] ?? ''))], $series);
        $all = collect([...$prepare((array) ($postcode['series'] ?? []), 0, (string) ($postcode['nombre'] ?? '')), ...$prepare((array) ($area['series'] ?? []), 1, (string) ($area['nombre'] ?? ''))])
            ->filter(fn (array $x): bool => (float) ($x['eur_m2'] ?? 0) > 0);
        $minimum = fn (array $x): bool => ! isset($x['muestras']) || (int) $x['muestras'] >= (int) ($x['nivel'] === 0 ? ($thresholds['muestra_cp_min'] ?? 30) : ($thresholds['muestra_municipio_min'] ?? 20));
        $series = $all->filter(fn (array $x): bool => ($x['uso'] === 'autorizado' || $allowPending) && $minimum($x))->values();

        $rank = ['compraventa' => 0, 'tasacion' => 1, 'oferta' => 2];
        $base = $series->sortBy(fn (array $x): array => [$x['nivel'], $rank[$x['tipo']] ?? 9])->first();
        if ($base !== null && $base['nivel'] === 1 && is_array($postcode['excepcion'] ?? null)) {
            return $revision('zona_no_representativa', ['motivo' => $postcode['excepcion']['motivo'] ?? '']);
        }
        if ($base === null) {
            $municipality = mb_convert_case((string) ($area['nombre'] ?? $s['municipio'] ?? '') ?: '—', MB_CASE_TITLE);
            $message = match (true) {
                $all->isNotEmpty() => 'sin_datos_autorizados',
                $province !== null => 'sin_datos_zona',
                default => 'sin_datos',
            };

            return [...$revision('sin_datos_zona', ['municipio' => $municipality, 'provincia' => Provinces::name($province) ?? '—'], $message), 'contexto' => self::provinceContext($province)];
        }

        // 2. €/m² of closing price. Appraisals: the column by building age when its own sample reaches the minimum.
        $age = is_numeric($s['ano'] ?? null) ? (int) date('Y') - (int) $s['ano'] : null;
        $column = 'eur_m2';
        if ($base['tipo'] === 'tasacion' && isset($base['mas_5_anos'])) {
            $wanted = $age !== null && $age <= 5 ? 'hasta_5_anos' : 'mas_5_anos';
            $column = isset($base[$wanted]) && (int) ($base['muestras_'.$wanted] ?? $base['muestras'] ?? 0) >= (int) ($thresholds['muestra_municipio_min'] ?? 20) ? $wanted : 'eur_m2';
            $base['muestras'] = $base['muestras_'.$column] ?? $base['muestras'] ?? null;
        }
        $toClosing = fn (array $x, string $col = 'eur_m2'): float => (float) ($x[$col] ?? $x['eur_m2']) * match ($x['tipo']) {
            'tasacion' => self::appraisalToClosing(),
            'oferta' => 1 + self::OFFER_TO_CLOSING,
            default => 1.0,
        };
        $ipv = $base['tipo'] === 'tasacion' ? self::ipvIndexation($province, (string) $base['periodo']) : null;
        $baseM2 = $toClosing($base, $column) * ($ipv['factor'] ?? 1.0);
        $other = $series->first(fn (array $x): bool => $x['fuente'] !== $base['fuente'] && isset($rank[$x['tipo']]));
        $otherM2 = $other === null ? null : $toClosing($other);
        $spread = $otherM2 === null ? 0.0 : abs($baseM2 - $otherM2) / (($baseM2 + $otherM2) / 2);

        // 3. Adjustments.
        $adj = self::adjustments($s, (bool) ($postcode['costa'] ?? $area['costa'] ?? false));
        $factor = array_product(array_map(fn (array $a): float => 1 + $a['valor'], $adj)) ?: 1.0;
        if (abs($factor - 1) > self::ADJUSTMENT_LIMIT) {
            return $revision('ajustes_extremos', ['pct' => self::pct($factor - 1)]);
        }
        $annexAmounts = self::annexAmounts($s);
        $central = self::round($baseM2 * $factor * $m2 + array_sum(array_column($annexAmounts, 'importe')));

        // 4. Sufficiency (points) and interval.
        [$points, $breakdown] = self::sufficiency($s, $base);
        if ($points < 30) {
            return $revision('insuficiente');
        }
        $level = $points >= 70 ? 'alta' : ($points >= 45 ? 'media' : 'baja');
        $margin = self::MARGIN[$level] + ($base['nivel'] === 1 ? self::MARGIN_MUNICIPAL : 0) + (($s['discrepancia'] ?? null) === 'superficie' ? self::MARGIN_DISCREPANCY : 0);
        $margin = min(self::MARGIN_MAX, max($margin, $spread / 2));

        // Limitations shown in the report.
        if (! ($s['catastro'] ?? false)) {
            $limits[] = 'No se ha podido confirmar el inmueble en Catastro: superficie y año según los datos aportados.';
        }
        if ($base['tipo'] === 'tasacion') {
            $limits[] = 'El precio base es el valor tasado medio de '.$base['ambito'].' ('.($column === 'hasta_5_anos' ? 'viviendas de hasta 5 años' : ($column === 'mas_5_anos' ? 'viviendas de más de 5 años' : 'total')).'), tomado como aproximación del precio de cierre. '.self::closingContext($province);
        }
        if ($ipv !== null) {
            $limits[] = "Precio base actualizado de {$ipv['desde']} a {$ipv['hasta']} con el Índice de Precios de Vivienda del INE de {$ipv['ccaa']} (× ".number_format($ipv['factor'], 3, ',', '.').').';
        }
        if ($base['nivel'] === 1) {
            $limits[] = 'Precio medio de todo el municipio: '.($postcode !== null ? 'hay datos por código postal ('.$s['codigo_postal'].') más precisos, pendientes de licencia.' : 'no hay datos autorizados por barrio o código postal.');
        }
        if ($base['fin'] === null) {
            $limits[] = "La fuente no publica la fecha exacta del periodo ({$base['periodo']}).";
        }
        if ($base['tipo'] === 'oferta') {
            $limits[] = 'Solo hay precios de oferta para esta zona: se han convertido a cierre con un '.self::pct(self::OFFER_TO_CLOSING).'.';
        }
        foreach ($series->whereIn('fuente', array_filter([$base['fuente'], $other['fuente'] ?? null]))->where('uso', '!=', 'autorizado')->pluck('fuente')->unique() as $pending) {
            $limits[] = 'PRUEBA INTERNA: incluye datos de '.self::source($pending)['nombre'].' cuyo uso comercial requiere licencia.';
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
        if (collect($adj)->contains(fn (array $a): bool => str_contains($a['concepto'], 'provisional'))) {
            $limits[] = 'Garaje y trastero se valoran con un ajuste porcentual provisional hasta que CLARTO fije importes por municipio.';
        }
        $limits[] = 'Las fuentes de precio publican €/m² «construido» sin aclarar si incluye elementos comunes; aquí se aplica a la superficie construida con comunes, como la da Catastro.';
        $limits[] = 'Datos estadísticos agregados: calidades, orientación, vistas, eficiencia energética y cargas no se valoran sin visitar el inmueble. No es el valor de referencia del Catastro ni sirve como base de impuestos.';

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
            'fuentes' => self::sources($s, $base, $other),
            'limitaciones' => $limits,
            'calculo' => [
                'metodo' => 'Precio medio estadístico de la zona ajustado (Estimador CLARTO '.self::VERSION.')',
                'formula' => '€/m² base × m² construidos con comunes × Π(1 + ajuste) + anexos',
                'base_eur_m2' => (int) round($baseM2),
                'base_tipo' => $base['tipo'],
                'base_fuente' => self::source($base['fuente'])['nombre'],
                'base_ambito' => $base['ambito'],
                'base_nivel' => $base['nivel'] === 0 ? 'codigo_postal' : 'municipio',
                'base_columna' => $column,
                'base_eur_m2_publicado' => (float) ($base[$column] ?? $base['eur_m2']),
                'base_muestras' => $base['muestras'] ?? null,
                'base_periodo' => $base['periodo'],
                'factor_tasacion_cierre' => $base['tipo'] === 'tasacion' ? self::appraisalToClosing() : null,
                'indexacion_ipv' => $ipv,
                'provincia' => Provinces::name($province),
                'contraste_eur_m2' => $otherM2 === null ? null : (int) round($otherM2),
                'superficie_m2' => round($m2, 1),
                'superficie_origen' => $s['superficie_origen'],
                'ajustes' => $adj,
                'factor_ajustes' => round($factor, 4),
                'anexos' => $annexAmounts,
                'suficiencia_puntos' => $points,
                'suficiencia_detalle' => $breakdown,
                'margen' => round($margin, 4),
            ],
        ];
    }

    /**
     * INE IPV of the region from the base's quarter to the latest published one; null when the IPV is not newer
     * (nothing is extrapolated) or the province is unknown.
     *
     * @return array{desde: string, hasta: string, factor: float, serie: string, ccaa: string}|null
     */
    public static function ipvIndexation(?string $province, string $period): ?array
    {
        $ccaa = Provinces::ccaa($province);
        $series = (array) (self::data()['ipv'][$ccaa] ?? []);
        $indices = (array) ($series['indices'] ?? []);
        ksort($indices);
        $last = array_key_last($indices);
        if ($last === null || ! isset($indices[$period]) || strcmp((string) $last, $period) <= 0 || (float) $indices[$period] <= 0) {
            return null;
        }

        return ['desde' => $period, 'hasta' => (string) $last, 'factor' => round((float) $indices[$last] / (float) $indices[$period], 4),
            'serie' => (string) ($series['serie'] ?? ''), 'ccaa' => (string) Provinces::CCAA[$ccaa]];
    }

    /** Appraisal vs registered sale prices in the province (Registradores, ODbL): context for the report. */
    private static function closingContext(?string $province): string
    {
        $p = (array) (self::data()['provincias'][(string) $province] ?? []);
        if (! isset($p['registradores']['eur_m2'], $p['mivau']['eur_m2'])) {
            return (string) (self::data()['calibracion']['tasacion_a_cierre']['contexto'] ?? '');
        }
        $eur = fn (float $v): string => number_format($v, 0, ',', '.').' €/m²';
        $ratio = (float) $p['registradores']['eur_m2'] / (float) $p['mivau']['eur_m2'] - 1;

        return 'Contexto: en la provincia de '.$p['nombre'].' las compraventas registradas declaran de media '.$eur((float) $p['registradores']['eur_m2'])
            .' (Colegio de Registradores, Open Data ODbL, '.$p['registradores']['periodo'].') frente a '.$eur((float) $p['mivau']['eur_m2'])
            .' de valor tasado medio (MIVAU, '.$p['mivau']['periodo'].'): '.self::pct($ratio).'.';
    }

    /**
     * Provincial figures kept with a «sin_datos_zona» review, for the team preparing the valuation (never a price).
     *
     * @return array<string, mixed>|null
     */
    private static function provinceContext(?string $province): ?array
    {
        $p = self::data()['provincias'][(string) $province] ?? null;

        return is_array($p) ? ['provincia' => $p['nombre'] ?? Provinces::name($province), 'mivau' => $p['mivau'] ?? null, 'registradores' => $p['registradores'] ?? null,
            'nota' => 'Media provincial: solo contexto. Incluye las ciudades grandes y no representa un municipio pequeño.'] : null;
    }

    /**
     * Points 0–100 (alta ≥ 70, media 45–69, baja 30–44, < 30 → review).
     *
     * @param  array<string, mixed>  $s
     * @param  array<string, mixed>  $base
     * @return array{0: int, 1: list<array{concepto: string, puntos: int}>}
     */
    public static function sufficiency(array $s, array $base): array
    {
        $rows = [];
        if ($s['catastro'] ?? false) {
            $rows[] = ['concepto' => 'Inmueble identificado en Catastro y confirmado', 'puntos' => 30];
            if (($s['discrepancia'] ?? null) !== 'superficie') {
                $rows[] = ['concepto' => 'Superficies coherentes (diferencia hasta 15 %)', 'puntos' => 10];
            }
        }
        $n = (int) ($base['muestras'] ?? 0);
        $count = number_format($n, 0, ',', '.');
        $rows[] = match (true) {
            $base['nivel'] === 0 => ['concepto' => "Precio del código postal ({$count} operaciones)", 'puntos' => 30],
            $n >= (int) (self::data()['umbrales']['muestra_municipio_alta'] ?? 100) => ['concepto' => "Precio municipal ({$count} operaciones)", 'puntos' => 20],
            default => ['concepto' => "Precio municipal con muestra pequeña ({$count})", 'puntos' => 10],
        };
        $months = $base['fin'] === null ? null : (int) $base['fin']->diffInMonths(CarbonImmutable::now(), true);
        if ($months !== null && $months <= 6) {
            $rows[] = ['concepto' => "Dato de hace {$months} meses (hasta 6)", 'puntos' => 15];
        } elseif ($months !== null && $months <= 12) {
            $rows[] = ['concepto' => "Dato de hace {$months} meses (hasta 12)", 'puntos' => 8];
        }

        return [array_sum(array_column($rows, 'puntos')), $rows];
    }

    /**
     * @param  array<string, mixed>  $s
     * @return list<array{concepto: string, valor: float}>
     */
    public static function adjustments(array $s, bool $coast = false): array
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

        $flat = in_array($s['tipo'], ['piso', 'atico', 'duplex', 'estudio'], true);   // houses: no floor adjustment
        $floor = $s['planta'] ?? null;
        if ($flat && $floor !== null) {
            if ($floor <= 0) {
                $add('Planta baja', $a['bajo']);
            } elseif (($s['ascensor'] ?? null) === false) {
                $add("Planta {$floor} sin ascensor", $floor <= 2 ? $a['sin_ascensor_1_2'] : $a['sin_ascensor_3'] + ($floor - 3) * $a['sin_ascensor_por_planta_extra']);
            }
        }
        if ($s['tipo'] === 'atico' && ($s['ascensor'] ?? null) === true) {
            $add('Ático con ascensor', $a['atico_con_ascensor']);
        }
        if (($s['exterior'] ?? null) === false) {
            $add('Vivienda interior', $a['interior']);
        }
        if ((float) ($s['terraza_m2'] ?? 0) > 10) {
            $add('Terraza de más de 10 m²'.($coast ? ' (zona de costa)' : ''), $coast ? $a['terraza_10_costa'] : $a['terraza_10']);
        }

        $year = is_numeric($s['ano'] ?? null) ? (int) $s['ano'] : null;
        if ($year !== null && ! in_array($state, ['reformado', 'obra_nueva'], true)) {
            $band = $year < 1960 ? 'antes_1960' : ($year <= 1980 ? '1960_1980' : ($year <= 2005 ? '1981_2005' : 'desde_2006'));
            $add("Año de construcción {$year}", $a['antiguedad'][$band]);
        }
        $m2 = (float) ($s['superficie_m2'] ?? 0);
        $add('Tamaño ('.self::n($m2).' m²)', $m2 < 50 ? $a['tamano']['menos_50'] : ($m2 > 180 ? $a['tamano']['mas_180'] : ($m2 > 120 ? $a['tamano']['mas_120'] : 0.0)));

        $inArea = (bool) ($s['anexos_en_superficie'] ?? false);   // a house: garage and storage are in its built area
        $amounts = (array) (self::data()['anexos']['importes'][(string) ($s['ine'] ?? '')] ?? []);
        if (! $inArea && ($s['garaje'] ?? null) === 'incluido' && ! isset($amounts['garaje'])) {
            $add('Plaza de garaje incluida (ajuste provisional)', $a['garaje']);
        }
        if (! $inArea && ($s['trastero'] ?? false) === true && ! isset($amounts['trastero'])) {
            $add('Trastero incluido (ajuste provisional)', $a['trastero']);
        }

        return $out;
    }

    /**
     * Fixed amounts for annexes when CLARTO has set them for the municipality (precios.json «anexos.importes»).
     *
     * @param  array<string, mixed>  $s
     * @return list<array{concepto: string, importe: float}>
     */
    private static function annexAmounts(array $s): array
    {
        $amounts = (array) (self::data()['anexos']['importes'][(string) ($s['ine'] ?? '')] ?? []);
        $out = [];
        if (! ($s['anexos_en_superficie'] ?? false) && ($s['garaje'] ?? null) === 'incluido' && isset($amounts['garaje'])) {
            $out[] = ['concepto' => 'Plaza de garaje', 'importe' => (float) $amounts['garaje']];
        }
        if (! ($s['anexos_en_superficie'] ?? false) && ($s['trastero'] ?? false) === true && isset($amounts['trastero'])) {
            $out[] = ['concepto' => 'Trastero', 'importe' => (float) $amounts['trastero']];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $s
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>|null  $other
     * @return list<array<string, string>>
     */
    private static function sources(array $s, array $base, ?array $other): array
    {
        $sources = [];
        if (($s['catastro'] ?? false) && is_array($s['catastro_fuente'] ?? null)) {
            $f = $s['catastro_fuente'];
            $sources[] = ['nombre' => 'Dirección General del Catastro (Sede Electrónica, referencia '.$s['rc'].')', 'tipo' => 'catastro', 'ambito' => 'Inmueble',
                'fecha_datos' => 'consulta '.CarbonImmutable::parse((string) $f['consultado'])->format('d/m/Y'), 'licencia' => $f['licencia'], 'url' => $f['url']];
        }
        foreach (array_filter([$base, $other]) as $x) {
            $src = self::source($x['fuente']);
            $sources[] = [
                'nombre' => $src['nombre'], 'tipo' => $src['tipo'], 'ambito' => $x['ambito'],
                'fecha_datos' => $x['periodo'].' (instantánea '.(self::data()['version'] ?? '').')', 'licencia' => $src['licencia'], 'url' => $src['url'],
                'valor' => number_format((float) $x['eur_m2'], 0, ',', '.').' €/m²'.(isset($x['muestras']) ? ' · '.number_format((float) $x['muestras'], 0, ',', '.').' operaciones' : ''),
            ];
        }

        return $sources;
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
        return ($fraction > 0 ? '+' : '').self::n(round($fraction * 100, 1)).' %';
    }
}
