<?php

declare(strict_types=1);

namespace App\Valuations;

/**
 * The CLARTO report of one run, in the format chosen in the module («Formato del informe»):
 *  - compacta: two A4 pages, text first (this class);
 *  - portada: designed cover + two content pages (ValuationBrochure) — the two-page limit applies to the content;
 *  - esencial: two designed pages with the photo as page header (ValuationBrochure).
 * Page counts are verified from the bytes in clarto:valoraciones-prueba. If the content does not fit, it is drawn
 * again with shorter lists. The calculation version and data snapshot go into the PDF metadata, not the page.
 */
final class ValuationReport
{
    public const FORMATS = ['portada' => 'Con portada (portada + 2 páginas)', 'esencial' => 'Esencial ilustrada (2 páginas)', 'compacta' => 'Compacta (2 páginas)'];

    public const DEFAULT_FORMAT = 'portada';

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

    private function __construct(private readonly ReportData $d, private readonly bool $compact) {}

    public static function render(ValuationRequest $request, ValuationRun $run, ?string $format = null): string
    {
        $format ??= ValuationSetting::current()->reportFormat();
        $data = new ReportData($request, $run);
        if ($format !== 'compacta') {
            return ValuationBrochure::render($data, $format === 'portada');
        }
        foreach ([false, true] as $compact) {
            $report = new self($data, $compact);
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
        $d = $this->d;
        $r = $d->r;

        // ── Page 1 ────────────────────────────────────────────────────────────────────────────────────
        $this->page(1);
        $this->pdf->text(self::LEFT, $this->y + 10, 'ESTIMACIÓN ORIENTATIVA', 'bold', 8.5, self::TERRA_TEXT, 1.4);
        $this->y += 20;
        $this->pdf->text(self::LEFT, $this->y + 20, 'Estimación del precio de venta', 'bold', 21, self::PETROL);
        $this->y += 30;
        $this->y = $this->pdf->paragraph(self::LEFT, $this->y, self::RIGHT - self::LEFT, $d->address().($d->cat ? ' · '.$d->cat['municipio'] : ''), 'regular', 10.5, self::MUTED) + 12;

        // Price box with the interval bar.
        $top = $this->y;
        $this->pdf->rect(self::LEFT, $top, self::RIGHT - self::LEFT, 118, self::IVORY);
        $this->pdf->rect(self::LEFT, $top, 4, 118, self::TERRA);
        $this->pdf->text(self::LEFT + 20, $top + 24, 'PRECIO ESTIMADO DE CIERRE', 'bold', 8, self::TERRA_TEXT, 1.2);
        $this->pdf->text(self::LEFT + 20, $top + 58, ReportData::eur($r['precio_central']), 'bold', 30, self::PETROL);
        $this->pdf->textRight(self::RIGHT - 20, $top + 30, number_format((float) ($r['eur_m2'] ?? 0), 0, ',', '.').' €/m²', 'bold', 12, self::PETROL);
        $this->pdf->textRight(self::RIGHT - 20, $top + 46, 'Suficiencia de datos: '.($r['suficiencia'] ?? '—'), 'regular', 9, self::MUTED);
        $this->pdf->textRight(self::RIGHT - 20, $top + 60, 'Fecha: '.$d->date, 'regular', 9, self::MUTED);
        [$min, $max, $central] = [(float) $r['intervalo']['min'], (float) $r['intervalo']['max'], (float) $r['precio_central']];
        $barX = self::LEFT + 20;
        $barW = self::RIGHT - self::LEFT - 40;
        $this->pdf->rect($barX, $top + 80, $barW, 6, self::TERRA_LIGHT);
        $mark = $barX + ($max > $min ? ($central - $min) / ($max - $min) : 0.5) * $barW;
        $this->pdf->rect($mark - 1.5, $top + 75, 3, 16, self::PETROL);
        $this->pdf->text($barX, $top + 104, 'Intervalo orientativo: '.ReportData::eur($min).' – '.ReportData::eur($max), 'bold', 9, self::INK);
        $this->pdf->textRight($barX + $barW, $top + 104, 'Precio de cierre estimado, no de oferta', 'regular', 8.5, self::MUTED);
        $this->y = $top + 118 + 20;

        $this->heading('Datos del inmueble');
        foreach ($d->propertyRows() as [, $label, $value]) {
            $this->row($label, $value);
        }
        $this->y = $this->pdf->paragraph(self::LEFT, $this->y + 2, self::RIGHT - self::LEFT, $d->surfacesLine(), 'regular', 8.5, self::MUTED) + 8;

        $this->heading('Resumen');
        $this->y = $this->pdf->paragraph(self::LEFT, $this->y, self::RIGHT - self::LEFT, (string) ($d->run->summary ?? ''), 'regular', 10, self::INK, 1.45) + 4;
        if ($d->run->summary_source === 'ia') {
            $this->y = $this->pdf->paragraph(self::LEFT, $this->y, self::RIGHT - self::LEFT, 'Resumen generado automáticamente a partir de los datos calculados.', 'italic', 8, self::MUTED);
        }
        if ($d->run->simulated) {
            $this->y = $this->pdf->paragraph(self::LEFT, $this->y + 4, self::RIGHT - self::LEFT, 'ATENCIÓN: este informe usa datos SIMULADOS de un proveedor (entorno de pruebas). No es una estimación real.', 'bold', 9, self::TERRA_TEXT);
        }

        // ── Page 2 ────────────────────────────────────────────────────────────────────────────────────
        $this->page(2);
        $this->heading('Cómo se ha calculado');
        foreach ($d->calculationRows($this->compact ? 5 : 9) as [$label, $value]) {
            $this->row($label, $value);
        }
        $this->y += 10;

        $this->heading('Fuentes');
        foreach ($d->sourceLines($this->compact ? 4 : 6) as $line) {
            $this->bullet($line, 8.5);
        }
        $this->y += 8;

        $this->heading('Observaciones');
        foreach ($d->observations($this->compact ? 5 : 8) as $line) {
            $this->bullet($line, 8.5);
        }
        $this->y += 12;

        // Sales invitation (ReportData::SALES_*).
        $inner = self::RIGHT - self::LEFT - 40;
        $lead = $this->pdf->wrap(ReportData::SALES_LEAD, 'italic', 8.8, $inner);
        $pillars = array_map(fn (array $p): array => $this->pdf->wrap($p[1].': '.$p[2], 'regular', 8.3, $inner - 12), ReportData::SALES_PILLARS);
        $height = 46 + count($lead) * 11.5 + 6 + array_sum(array_map(fn (array $l): float => count($l) * 10.8 + 3, $pillars)) + 36;
        $this->room($height + 6);
        $top = $this->y;
        $this->pdf->rect(self::LEFT, $top, self::RIGHT - self::LEFT, $height, self::PETROL);
        $this->pdf->text(self::LEFT + 20, $top + 20, ReportData::SALES_EYEBROW, 'bold', 7.5, self::TERRA_LIGHT, 1.3);
        $this->pdf->text(self::LEFT + 20, $top + 38, ReportData::SALES_TITLE, 'bold', 13, 'FFFFFF');
        $y = $top + 46;
        foreach ($lead as $line) {
            $y += 11.5;
            $this->pdf->text(self::LEFT + 20, $y - 2.5, $line, 'italic', 8.8, self::TERRA_LIGHT);
        }
        $y += 6;
        foreach ($pillars as $i => $lines) {
            $this->pdf->rect(self::LEFT + 21, $y + 4, 3.5, 3.5, self::TERRA);
            foreach ($lines as $j => $line) {
                $y += 10.8;
                $this->pdf->text(self::LEFT + 32, $y - 2.4, $line, 'regular', 8.3, 'E7EDEB');
            }
            $y += 3;
        }
        $this->pdf->text(self::LEFT + 20, $top + $height - 20, ReportData::SALES_CTA, 'bold', 10.5, 'FFFFFF');
        $this->pdf->text(self::LEFT + 20, $top + $height - 8, ReportData::PHONE.'  ·  WhatsApp '.ReportData::WHATSAPP.'  ·  '.ReportData::EMAIL.'  ·  '.ReportData::WEB, 'bold', 8.5, self::TERRA_LIGHT);
        $this->y = $top + $height;

        $bytes = $this->pdf->output('Estimación orientativa '.$d->reference(), $d->trace());
        if ($this->pdf->pageCount() > 2) {
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
        $this->pdf->textRight(self::RIGHT, 32, 'Ref. '.$this->d->reference(), 'bold', 9, self::TERRA_LIGHT);
        $this->pdf->textRight(self::RIGHT, 46, 'Informe de estimación · página '.$number.' de 2', 'regular', 8.5, 'E7EDEB');
        $this->pdf->line(self::LEFT, 800, self::RIGHT, 800, self::TERRA, 1);
        $this->pdf->text(self::LEFT, 814, 'CLARTO · ', 'bold', 8, self::PETROL);
        $this->pdf->text(self::LEFT + $this->pdf->width('CLARTO · ', 'bold', 8), 814, 'Lo complejo, claro.', 'italic', 8, self::TERRA_TEXT);
        $this->pdf->textRight(self::RIGHT, 814, 'Estimación orientativa · no es una tasación oficial', 'regular', 7.5, self::MUTED);
        $this->y = 104;
    }

    /** Moves to page 2 when the next block does not fit; a third page is never kept (overflow → compact). */
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
        $this->pdf->addPage();
        $this->y = 60;
    }

    private function heading(string $title): void
    {
        $this->room(40);
        $this->pdf->text(self::LEFT, $this->y + 14, $title, 'bold', 13, self::PETROL);
        $this->pdf->line(self::LEFT, $this->y + 21, self::LEFT + 36, $this->y + 21, self::TERRA, 1.6);
        $this->y += 30;
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
}
