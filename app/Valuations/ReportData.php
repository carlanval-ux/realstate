<?php

declare(strict_types=1);

namespace App\Valuations;

/**
 * What every report format prints, computed once from a request and one of its runs: price, interval, confirmed
 * property data (with an icon name each), calculation rows, sources and observations (Spanish, as the reports).
 */
final class ReportData
{
    public const DISCLAIMER = 'Estimación orientativa: no es una tasación oficial (Orden ECO/805/2003) ni sustituye la visita de un profesional.';

    public const PHONE = '960 30 31 31';

    public const WHATSAPP = '623 22 97 79';

    public const EMAIL = 'info@clarto.es';

    public const WEB = 'clarto.es';

    /** Photos owned by CLARTO's website (Unsplash License, docs/design/asset-manifest.md of the web). */
    public const PHOTOS = [
        'valencia' => ['img/valencia-wide-1600.jpg', 'Imagen ilustrativa de València · Foto: DaYsO / Unsplash', 0.5, 0.35],
        'mediterranea' => ['img/inmobiliaria-tall-720.jpg', 'Imagen ilustrativa · Foto: Stefan Vibes / Unsplash', 0.5, 0.45],
    ];

    /** @var array<string, mixed> */
    public array $r;

    /** @var array<string, mixed> */
    public array $calc;

    /** @var array<string, mixed> */
    public array $p;

    /** @var array<string, mixed>|null */
    public ?array $cat;

    public string $date;

    public function __construct(public readonly ValuationRequest $request, public readonly ValuationRun $run)
    {
        $this->r = (array) $run->result;
        $this->calc = (array) ($this->r['calculo'] ?? []);
        $this->p = (array) $request->property;
        $this->cat = is_array($request->catastro) && isset($request->catastro['rc']) ? $request->catastro : null;
        $this->date = $run->created_at?->timezone(config('app.timezone'))->format('d/m/Y') ?? now()->format('d/m/Y');
    }

    public static function eur(mixed $value): string
    {
        return number_format((float) $value, 0, ',', '.').' €';
    }

    public static function n(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, ',', '.'), '0'), ',');
    }

    public function address(): string
    {
        return $this->request->address();
    }

    /** «CL CUBA 22, Esc 1, Pl 02, Pt 03» → «Calle Cuba 22 · Escalera 1 · Planta 2 · Puerta 3» (Catastro style made readable). */
    public function displayAddress(): string
    {
        return implode(' · ', array_filter(self::readable($this->address())));
    }

    /** @return array{0: string, 1: string} [street and number, «Escalera · Planta · Puerta» (or '')] */
    public function addressParts(): array
    {
        return self::readable($this->address());
    }

    /** @return array{0: string, 1: string} */
    public static function readable(string $address): array
    {
        if (! preg_match('/^(CL|AV|PS|PZ|CM|RD|TR|GV|UR|CR|PJ|BV)\s+(.+?)(,.*)?$/u', $address, $m)) {
            return [$address, ''];
        }
        $types = ['CL' => 'Calle', 'AV' => 'Avenida', 'PS' => 'Paseo', 'PZ' => 'Plaza', 'CM' => 'Camino', 'RD' => 'Ronda', 'TR' => 'Travesía', 'GV' => 'Gran Vía', 'UR' => 'Urbanización', 'CR' => 'Carretera', 'PJ' => 'Pasaje', 'BV' => 'Bulevar'];
        // Catastro puts the article last: «REPUBLICA ARGENTINA DE LA 30» → «DE LA REPUBLICA ARGENTINA 30».
        $name = (string) preg_replace('/^(.*?)\s+(DE LA|DE LAS|DE LOS|DE LES|DELS|DEL|DE)\s+(\d+\w*)$/u', '$2 $1 $3', $m[2]);
        $street = mb_convert_case(mb_strtolower($name), MB_CASE_TITLE);
        $street = (string) preg_replace_callback('/\b(De|Del|La|Las|Los|El|Els|Les|I|Y)\b/u', fn (array $w): string => mb_strtolower($w[1]), $street);
        $parts = [];
        foreach ([['Esc', 'Escalera'], ['Pl', 'Planta'], ['Pt', 'Puerta']] as [$code, $label]) {
            if (preg_match('/\b'.$code.'\s+(\w+)/u', $m[3] ?? '', $p)) {
                $parts[] = $label.' '.(ctype_digit($p[1]) ? (string) (int) $p[1] : ltrim($p[1], '0'));
            }
        }

        return [trim($types[$m[1]].' '.$street), implode(' · ', $parts)];
    }

    public function municipality(): string
    {
        return (string) ($this->cat['municipio'] ?? $this->p['municipio'] ?? '');
    }

    public function reference(): string
    {
        return (string) ($this->request->reference ?? '');
    }

    public function contactName(): string
    {
        return trim((string) ($this->request->contact['nombre'] ?? ''));
    }

    /** @return array{0: string, 1: string, 2: float, 3: float} photo path, caption, focus x, focus y */
    public function photo(): array
    {
        [$file, $caption, $fx, $fy] = str_contains(Catastro::normalize($this->municipality()), 'VALENCIA') && ! str_contains(Catastro::normalize($this->municipality()), 'GANDIA')
            ? self::PHOTOS['valencia'] : self::PHOTOS['mediterranea'];

        return [resource_path('valoraciones/'.$file), $caption, $fx, $fy];
    }

    /** Version of the calculation and data, for the PDF metadata (not printed). */
    public function trace(): string
    {
        return 'Estimador CLARTO '.($this->r['version_calculo'] ?? '—').' · datos '.($this->r['version_datos'] ?? '—').' · cálculo n.º '.$this->run->number;
    }

    /** @return list<array{0: string, 1: string, 2: string}> [icon, label, value] */
    public function propertyRows(): array
    {
        $p = $this->p;
        $cat = $this->cat;
        $yes = fn ($v): string => $v === null ? 'no indicado' : ($v ? 'sí' : 'no');
        $plural = fn ($n, string $one, string $many): string => $n.' '.((int) $n === 1 ? $one : $many);

        return [
            ['documento', 'Referencia catastral', (string) ($cat['rc'] ?? ($p['rc'] ?? 'No confirmada en Catastro'))],
            ['superficie', 'Superficie de cálculo', isset($this->calc['superficie_m2']) ? self::n((float) $this->calc['superficie_m2']).' m² construidos con comunes, sin anexos' : '—'],
            ['casa', 'Tipo', ucfirst(str_replace('_', ' ', (string) ($p['tipo'] ?? '—'))).(filled($cat['tipologia'] ?? null) ? ' · '.$cat['tipologia'] : '')],
            ['calendario', 'Año de construcción', (string) ($cat['ano_construccion'] ?? $p['ano_construccion'] ?? 'No indicado')],
            ['planta', 'Planta y ascensor', trim(($p['planta'] ?? $cat['planta'] ?? '—').' · ascensor: '.$yes($p['ascensor'] ?? null))],
            ['estado', 'Estado de conservación', isset($p['conservacion']) ? ucfirst(str_replace('_', ' ', (string) $p['conservacion'])) : 'No indicado'],
            ['dormitorio', 'Distribución', trim((isset($p['dormitorios']) ? $plural($p['dormitorios'], 'dormitorio', 'dormitorios') : '').(isset($p['banos']) ? ' · '.$plural($p['banos'], 'baño', 'baños') : ''), ' ·') ?: 'No indicada'],
            ['sol', 'Exterior y terraza', ucfirst($yes($p['exterior'] ?? null)).' · terraza '.(isset($p['terraza_m2']) && $p['terraza_m2'] ? self::n((float) $p['terraza_m2']).' m²' : 'no')],
            ['coche', 'Garaje y trastero', 'Garaje: '.str_replace('_', ' ', (string) ($p['garaje'] ?? 'no indicado')).' · trastero: '.$yes($p['trastero'] ?? null)],
        ];
    }

    /** Surfaces as declared and as in Catastro, one line. */
    public function surfacesLine(): string
    {
        $p = $this->p;
        $cat = $this->cat;
        $declared = trim((isset($p['superficie_util_m2']) ? 'útil '.self::n((float) $p['superficie_util_m2']).' m²' : '')
            .(isset($p['superficie_construida_m2']) ? ' · construida '.self::n((float) $p['superficie_construida_m2']).' m²'.(($p['incluye_elementos_comunes'] ?? false) ? ' con comunes' : '') : ''), ' ·');
        $catastro = $cat === null ? '' : 'Catastro: '.(($cat['superficie_vivienda_m2'] ?? null) ? 'vivienda '.self::n((float) $cat['superficie_vivienda_m2']).' m²' : 'total '.self::n((float) $cat['superficie_total_catastro_m2']).' m²')
            .(($cat['superficie_elementos_comunes_m2'] ?? null) ? ' · comunes '.self::n((float) $cat['superficie_elementos_comunes_m2']).' m²' : '')
            .implode('', array_map(fn (array $a): string => ' · '.mb_strtolower($a['descripcion']).' '.self::n((float) $a['superficie_m2']).' m²', (array) ($cat['anexos'] ?? [])))
            .' · uso '.mb_strtolower((string) $cat['uso']);

        return implode('. ', array_filter([$declared !== '' ? 'Superficies indicadas: '.$declared : null, $catastro ?: null])).'.';
    }

    /** @return list<array{0: string, 1: string}> [label, value] */
    public function calculationRows(int $maxAdjustments = 9): array
    {
        $s = $this->calc;
        if (! isset($s['base_eur_m2'])) {
            return [['Método', (string) ($s['metodo'] ?? '—')]];
        }
        $published = self::eur($s['base_eur_m2_publicado'] ?? $s['base_eur_m2']).'/m²';
        $factor = (float) ($s['factor_tasacion_cierre'] ?? 1);
        $column = ['hasta_5_anos' => ' (viviendas de hasta 5 años)', 'mas_5_anos' => ' (viviendas de más de 5 años)'][$s['base_columna'] ?? ''] ?? '';
        $rows = [['Precio base de la zona', self::eur($s['base_eur_m2']).'/m² · '.match ($s['base_tipo']) {
            'tasacion' => 'valor tasado medio '.$published.$column.($factor != 1.0 ? ' × '.number_format($factor, 3, ',', '.') : ', tomado como precio de cierre'),
            'oferta' => 'oferta '.$published.' − '.self::n(-InHouseEstimator::OFFER_TO_CLOSING * 100).' % (de oferta a cierre)',
            default => 'compraventas '.$published,
        }.' · '.($s['base_ambito'] ?? '').', '.$s['base_periodo'].(isset($s['base_muestras']) ? ', '.number_format((float) $s['base_muestras'], 0, ',', '.').' operaciones' : '')]];
        if ($s['contraste_eur_m2'] ?? null) {
            $rows[] = ['Contraste', self::eur($s['contraste_eur_m2']).'/m² (otra fuente estadística)'];
        }
        foreach (array_slice((array) ($s['ajustes'] ?? []), 0, $maxAdjustments) as $adj) {
            $rows[] = ['Ajuste', $adj['concepto'].': '.($adj['valor'] > 0 ? '+' : '').self::n(round($adj['valor'] * 100, 1)).' %'];
        }
        foreach ((array) ($s['anexos'] ?? []) as $annex) {
            $rows[] = ['Anexo', $annex['concepto'].': '.self::eur($annex['importe'])];
        }
        $rows[] = ['Resultado', self::eur($s['base_eur_m2']).'/m² × '.self::n((float) $s['superficie_m2']).' m² × '.number_format((float) ($s['factor_ajustes'] ?? 1), 3, ',', '.').' = '.self::eur($this->r['precio_central']).' (redondeado a 1.000 €)'];
        if (isset($s['suficiencia_puntos'])) {
            $rows[] = ['Suficiencia', $this->r['suficiencia'].' ('.$s['suficiencia_puntos'].' puntos: '.implode('; ', array_map(fn (array $p): string => $p['concepto'].' +'.$p['puntos'], (array) $s['suficiencia_detalle'])).'); intervalo ±'.self::n(round(((float) $s['margen']) * 100, 1)).' %'];
        }

        return $rows;
    }

    /** @return list<string> */
    public function sourceLines(int $max = 6): array
    {
        $kind = fn (string $type): string => ['compraventa' => 'precios de compraventa (cierre)', 'tasacion' => 'valores de tasación', 'oferta' => 'precios de oferta', 'referencia_fiscal' => 'referencia fiscal', 'catastro' => 'datos catastrales', 'avm' => 'modelo automático de valoración'][$type] ?? $type;

        return array_map(fn (array $f): string => $f['nombre'].' — '.$kind((string) $f['tipo']).(filled($f['ambito'] ?? null) ? ', '.$f['ambito'] : '').(filled($f['valor'] ?? null) ? ', '.$f['valor'] : '')
            .'. Datos: '.($f['fecha_datos'] ?? '—').'. Licencia: '.($f['licencia'] ?? '—'), array_slice((array) ($this->r['fuentes'] ?? []), 0, $max));
    }

    /** @return list<string> */
    public function observations(int $max = 8): array
    {
        return array_slice([self::DISCLAIMER, ...(array) ($this->r['limitaciones'] ?? [])], 0, $max);
    }

    /** 1–3 for the sufficiency dots. */
    public function sufficiencyLevel(): int
    {
        return ['baja' => 1, 'media' => 2, 'alta' => 3][$this->r['suficiencia'] ?? ''] ?? 1;
    }
}
