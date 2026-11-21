<?php

declare(strict_types=1);

namespace App\Valuations;

/**
 * The CLARTO report of one run: at most two A4 pages (verified from the bytes in clarto:valoraciones-prueba).
 * Page 1: estimate, interval, confirmed data, summary. Page 2: calculation, sources, limitations and the call to a
 * personalised valuation. If the content does not fit, it is rendered again in compact mode (shorter lists).
 */
final class ValuationReport
{
    private const PETROL = '173D38';

    private const TERRA = 'BD7968';

    private const TERRA_TEXT = '9E5847';

    private const TERRA_LIGHT = 'D99B8C';

    private const IVORY = 'F5F1E9';

    private const INK = '132A27';

    private const MUTED = '526663';

    private const LINE = 'D8D0C2';

    private const LEFT = 48.0;

    private const RIGHT = 547.0;

    private const BOTTOM = 770.0;

    private BrandPdf $pdf;

    private float $y = 0;

    private bool $overflow = false;

    private function __construct(private readonly ValuationRequest $request, private readonly ValuationRun $run, private readonly bool $compact) {}

    public static function render(ValuationRequest $request, ValuationRun $run): string
    {
        foreach ([false, true] as $compact) {
            $report = new self($request, $run, $compact);
            $bytes = $report->draw();
            if (! $report->overflow) {
                return $bytes;
            }
        }

        return $bytes;   // compact mode cuts lists to fit; overflow here is not expected
    }

    private function draw(): string
    {
        $this->pdf = new BrandPdf;
        $r = (array) $this->run->result;
        $s = (array) ($r['calculo'] ?? []);
        $p = (array) $this->request->property;
        $cat = is_array($this->request->catastro) && isset($this->request->catastro['rc']) ? $this->request->catastro : null;
        $eur = fn ($v): string => number_format((float) $v, 0, ',', '.').' €';
        $date = $this->run->created_at?->timezone(config('app.timezone'))->format('d/m/Y') ?? now()->format('d/m/Y');

        // ── Page 1 ────────────────────────────────────────────────────────────────────────────────────
        $this->page(1);
        $this->eyebrow('Estimación automática orientativa', self::TERRA_TEXT);
        $this->y += 6;
        $this->pdf->text(self::LEFT, $this->y + 20, 'Estimación del precio de venta', 'bold', 21, self::PETROL);
        $this->y += 30;
        $this->y = $this->pdf->paragraph(self::LEFT, $this->y, self::RIGHT - self::LEFT, $this->request->address().($cat ? ' · '.$cat['municipio'] : ''), 'regular', 10.5, self::MUTED) + 12;

        // Price box with the interval bar.
        $top = $this->y;
        $this->pdf->rect(self::LEFT, $top, self::RIGHT - self::LEFT, 118, self::IVORY);
        $this->pdf->rect(self::LEFT, $top, 4, 118, self::TERRA);
        $this->pdf->text(self::LEFT + 20, $top + 24, mb_strtoupper('Precio estimado de '.(($r['tipo_precio'] ?? 'cierre') === 'cierre' ? 'cierre' : 'oferta')), 'bold', 8, self::TERRA_TEXT, 1.2);
        $this->pdf->text(self::LEFT + 20, $top + 58, $eur($r['precio_central']), 'bold', 30, self::PETROL);
        $this->pdf->textRight(self::RIGHT - 20, $top + 30, number_format((float) ($r['eur_m2'] ?? 0), 0, ',', '.').' €/m²', 'bold', 12, self::PETROL);
        $this->pdf->textRight(self::RIGHT - 20, $top + 46, 'Suficiencia de datos: '.($r['suficiencia'] ?? '—'), 'regular', 9, self::MUTED);
        $this->pdf->textRight(self::RIGHT - 20, $top + 60, 'Fecha: '.$date, 'regular', 9, self::MUTED);
        [$min, $max, $central] = [(float) $r['intervalo']['min'], (float) $r['intervalo']['max'], (float) $r['precio_central']];
        $barX = self::LEFT + 20;
        $barW = self::RIGHT - self::LEFT - 40;
        $this->pdf->rect($barX, $top + 80, $barW, 6, self::TERRA_LIGHT);
        $mark = $barX + ($max > $min ? ($central - $min) / ($max - $min) : 0.5) * $barW;
        $this->pdf->rect($mark - 1.5, $top + 75, 3, 16, self::PETROL);
        $this->pdf->text($barX, $top + 104, 'Mínimo '.$eur($min), 'regular', 9, self::INK);
        $this->pdf->textRight($barX + $barW, $top + 104, 'Máximo '.$eur($max), 'regular', 9, self::INK);
        $this->y = $top + 118 + 20;

        // Confirmed data.
        $this->heading('Datos del inmueble');
        $yes = fn ($v): string => $v === null ? 'No indicado' : ($v ? 'Sí' : 'No');
        $rows = array_filter([
            ['Referencia catastral', $cat['rc'] ?? ($p['rc'] ?? 'No confirmada en Catastro')],
            ['Tipo', ucfirst(str_replace('_', ' ', (string) ($p['tipo'] ?? '—'))).(filled($cat['tipologia'] ?? null) ? ' · '.$cat['tipologia'] : '')],
            ['Superficie de cálculo', isset($s['superficie_m2']) ? self::n((float) $s['superficie_m2']).' m² construidos con elementos comunes, sin anexos ('.self::origin($s['superficie_origen'] ?? null).')' : '—'],
            ['Superficies indicadas', trim((isset($p['superficie_util_m2']) ? 'útil '.self::n((float) $p['superficie_util_m2']).' m²' : '').(isset($p['superficie_construida_m2']) ? ' · construida '.self::n((float) $p['superficie_construida_m2']).' m²'.(($p['incluye_elementos_comunes'] ?? false) ? ' (con comunes)' : '') : ''), ' ·') ?: '—'],
            $cat ? ['Catastro', ($cat['superficie_vivienda_m2'] ? 'vivienda '.self::n((float) $cat['superficie_vivienda_m2']).' m²' : 'total '.self::n((float) $cat['superficie_total_catastro_m2']).' m²')
                .($cat['superficie_elementos_comunes_m2'] ? ' · comunes '.self::n((float) $cat['superficie_elementos_comunes_m2']).' m²' : '')
                .implode('', array_map(fn (array $a): string => ' · '.mb_strtolower($a['descripcion']).' '.self::n((float) $a['superficie_m2']).' m²', (array) $cat['anexos']))
                .' · uso '.mb_strtolower((string) $cat['uso'])] : null,
            ['Año de construcción', (string) ($cat['ano_construccion'] ?? $p['ano_construccion'] ?? 'No indicado')],
            ['Planta y ascensor', trim(($p['planta'] ?? $cat['planta'] ?? '—').' · ascensor: '.mb_strtolower($yes($p['ascensor'] ?? null)))],
            ['Estado de conservación', isset($p['conservacion']) ? ucfirst(str_replace('_', ' ', (string) $p['conservacion'])) : 'No indicado'],
            ['Distribución', trim((isset($p['dormitorios']) ? $p['dormitorios'].((int) $p['dormitorios'] === 1 ? ' dormitorio' : ' dormitorios') : '').(isset($p['banos']) ? ' · '.$p['banos'].((int) $p['banos'] === 1 ? ' baño' : ' baños') : ''), ' ·') ?: 'No indicada'],
            ['Exterior, terraza', $yes($p['exterior'] ?? null).' · terraza '.(isset($p['terraza_m2']) && $p['terraza_m2'] ? self::n((float) $p['terraza_m2']).' m²' : 'no')],
            ['Garaje y trastero', 'garaje: '.str_replace('_', ' ', (string) ($p['garaje'] ?? 'no indicado')).' · trastero: '.mb_strtolower($yes($p['trastero'] ?? null))],
        ]);
        foreach ($rows as [$label, $value]) {
            $this->row($label, (string) $value);
        }
        $this->y += 10;

        $this->heading('Resumen');
        $this->y = $this->pdf->paragraph(self::LEFT, $this->y, self::RIGHT - self::LEFT, (string) ($this->run->summary ?? ''), 'regular', 10, self::INK, 1.45) + 4;
        if ($this->run->simulated) {
            $this->y = $this->pdf->paragraph(self::LEFT, $this->y + 4, self::RIGHT - self::LEFT, 'ATENCIÓN: este informe usa datos SIMULADOS de un proveedor (entorno de pruebas). No es una estimación real.', 'bold', 9, self::TERRA_TEXT);
        }

        // ── Page 2 ────────────────────────────────────────────────────────────────────────────────────
        $this->page(2);
        $this->heading('Cómo se ha calculado');
        $method = (string) ($s['metodo'] ?? '—');
        $this->y = $this->pdf->paragraph(self::LEFT, $this->y, self::RIGHT - self::LEFT, $method.'. Versión del cálculo '.($r['version_calculo'] ?? '—').', datos '.($r['version_datos'] ?? '—').'.', 'regular', 9.5, self::INK);
        if (isset($s['base_eur_m2'])) {
            $this->y += 4;
            $published = $eur($s['base_eur_m2_publicado'] ?? $s['base_eur_m2']).'/m²';
            $this->row('Precio base de la zona', $eur($s['base_eur_m2']).'/m² de cierre · '.match ($s['base_tipo']) {
                'tasacion' => 'valor tasado '.$published.' × '.number_format(InHouseEstimator::appraisalToClosing(), 3, ',', '.').' (factor de tasación a cierre)',
                'oferta' => 'oferta '.$published.' − '.self::n(-InHouseEstimator::OFFER_TO_CLOSING * 100).' % (de oferta a cierre)',
                default => 'compraventas '.$published,
            }.' · '.($s['base_ambito'] ?? '').', '.$s['base_periodo']);
            if ($s['contraste_eur_m2'] ?? null) {
                $this->row('Contraste', $eur($s['contraste_eur_m2']).'/m² (otra fuente estadística)');
            }
            foreach (array_slice((array) $s['ajustes'], 0, $this->compact ? 5 : 9) as $adj) {
                $this->row('Ajuste', $adj['concepto'].': '.($adj['valor'] > 0 ? '+' : '').self::n(round($adj['valor'] * 100, 1)).' %');
            }
            $this->row('Resultado', $eur($r['eur_m2']).'/m² × '.self::n((float) $s['superficie_m2']).' m² = '.$eur($r['precio_central']).' (redondeado); intervalo ±'.self::n(round(((float) $s['margen']) * 100, 1)).' %');
        }
        $this->y += 10;

        $this->heading('Fuentes');
        foreach (array_slice((array) ($r['fuentes'] ?? []), 0, $this->compact ? 4 : 6) as $f) {
            $this->bullet($f['nombre'].' — '.self::kind((string) $f['tipo']).(filled($f['ambito'] ?? null) ? ', '.$f['ambito'] : '').(filled($f['valor'] ?? null) ? ', '.$f['valor'] : '').'. Datos: '.($f['fecha_datos'] ?? '—').'. Licencia: '.($f['licencia'] ?? '—'), 8.5);
        }
        $this->y += 8;

        $this->heading('Principales limitaciones');
        foreach (array_slice([
            'Estimación automática orientativa: no es una tasación oficial (Orden ECO/805/2003) ni sustituye la visita de un profesional.',
            ...(array) ($r['limitaciones'] ?? []),
        ], 0, $this->compact ? 5 : 8) as $limit) {
            $this->bullet((string) $limit, 8.5);
        }
        $this->y += 12;

        // Call to action.
        $this->room(92);
        $top = $this->y;
        $this->pdf->rect(self::LEFT, $top, self::RIGHT - self::LEFT, 86, self::PETROL);
        $this->pdf->text(self::LEFT + 20, $top + 26, 'Solicitar una valoración personalizada con CLARTO', 'bold', 13, 'FFFFFF');
        $this->pdf->paragraph(self::LEFT + 20, $top + 32, self::RIGHT - self::LEFT - 40, 'Nuestro equipo puede revisar el inmueble —estado real, calidades, orientación, cargas y documentación— para ajustar este resultado y acompañarle en la venta. Sin compromiso.', 'regular', 9.5, 'E7EDEB');
        $this->pdf->text(self::LEFT + 20, $top + 76, 'clarto.es  ·  960 30 31 31  ·  info@clarto.es  ·  Ref. '.($this->request->reference ?? ''), 'bold', 9, self::TERRA_LIGHT);
        $this->y = $top + 86;

        $total = $this->pdf->pageCount();
        $bytes = $this->pdf->output('Estimación '.($this->request->reference ?? ''));
        if ($total > 2) {
            $this->overflow = true;
        }

        return $bytes;
    }

    private function page(int $number): void
    {
        if ($this->pdf->pageCount() >= $number) {
            return;
        }
        $this->pdf->addPage();
        $this->pdf->rect(0, 0, BrandPdf::W, 74, self::PETROL);
        $this->pdf->jpeg(resource_path('valoraciones/logo-negativo.jpg'), self::LEFT - 8, 20, 180);
        $this->pdf->textRight(self::RIGHT, 32, 'Ref. '.($this->request->reference ?? ''), 'bold', 9, self::TERRA_LIGHT);
        $this->pdf->textRight(self::RIGHT, 46, 'Informe de estimación · página '.$number.' de 2', 'regular', 8.5, 'E7EDEB');
        // Footer.
        $this->pdf->line(self::LEFT, 800, self::RIGHT, 800, self::TERRA, 1);
        $this->pdf->text(self::LEFT, 814, 'CLARTO · ', 'bold', 8, self::PETROL);
        $this->pdf->text(self::LEFT + $this->pdf->width('CLARTO · ', 'bold', 8), 814, 'Lo complejo, claro.', 'italic', 8, self::TERRA_TEXT);
        $this->pdf->textRight(self::RIGHT, 814, 'Estimación automática orientativa · no es una tasación oficial', 'regular', 7.5, self::MUTED);
        $this->y = 104;
    }

    /** Moves to page 2 when the next block does not fit; a third page is never created (overflow → compact). */
    private function room(float $height): void
    {
        if ($this->y + $height <= self::BOTTOM) {
            return;
        }
        if ($this->pdf->pageCount() < 2) {
            $this->page(2);

            return;
        }
        $this->overflow = true;
        $this->pdf->addPage();   // counted: the compact pass is used instead
        $this->y = 60;
    }

    private function heading(string $title): void
    {
        $this->room(40);
        $this->pdf->text(self::LEFT, $this->y + 14, $title, 'bold', 13, self::PETROL);
        $this->pdf->line(self::LEFT, $this->y + 21, self::LEFT + 36, $this->y + 21, self::TERRA, 1.6);
        $this->y += 30;
    }

    private function eyebrow(string $text, string $hex): void
    {
        $this->pdf->text(self::LEFT, $this->y + 10, mb_strtoupper($text), 'bold', 8.5, $hex, 1.4);
        $this->y += 14;
    }

    private function row(string $label, string $value): void
    {
        $lines = $this->pdf->wrap($value, 'regular', 9.5, self::RIGHT - self::LEFT - 150);
        $height = count($lines) * 13 + 4;
        $this->room($height);
        $this->pdf->text(self::LEFT, $this->y + 10, $label, 'bold', 9, self::MUTED);
        foreach ($lines as $i => $line) {
            $this->pdf->text(self::LEFT + 150, $this->y + 10 + $i * 13, $line, 'regular', 9.5, self::INK);
        }
        $this->y += $height;
        $this->pdf->line(self::LEFT, $this->y - 1, self::RIGHT, $this->y - 1, self::LINE, 0.4);
    }

    private function bullet(string $text, float $size): void
    {
        $lines = $this->pdf->wrap($text, 'regular', $size, self::RIGHT - self::LEFT - 14);
        $this->room(count($lines) * $size * 1.38 + 3);
        $this->pdf->rect(self::LEFT + 1, $this->y + $size * 0.75, 3.5, 3.5, self::TERRA);
        $this->y = $this->pdf->paragraph(self::LEFT + 12, $this->y, self::RIGHT - self::LEFT - 14, $text, 'regular', $size, self::INK) + 3;
    }

    private static function origin(?string $origin): string
    {
        return ['catastro' => 'según Catastro', 'construida_declarada' => 'superficie construida indicada', 'util_convertida' => 'estimada desde la útil'][$origin] ?? 'indicada';
    }

    private static function kind(string $type): string
    {
        return ['compraventa' => 'precios de compraventa (cierre)', 'tasacion' => 'valores de tasación', 'oferta' => 'precios de oferta', 'referencia_fiscal' => 'referencia fiscal', 'catastro' => 'datos catastrales', 'avm' => 'modelo automático de valoración'][$type] ?? $type;
    }

    private static function n(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, ',', '.'), '0'), ',');
    }
}
