<?php

declare(strict_types=1);

namespace App\Valuations;

/**
 * Designed CLARTO report (ValuationReport formats «portada» and «esencial»): Manrope / DM Sans / Source Serif 4,
 * CLARTO palette, a photo from CLARTO's website, vector icons for the property data, the interval as a gauge and the
 * sales invitation. «portada» = cover + two content pages; «esencial» = the same two content pages, the first one with
 * the photo as header. If the content does not fit two pages, it is drawn again with shorter lists.
 */
final class ValuationBrochure
{
    private const PETROL = '173D38';

    private const PETROL_SOFT = '2A5650';

    private const TERRA = 'BD7968';

    private const TERRA_TEXT = '9E5847';

    private const TERRA_LIGHT = 'D99B8C';

    private const IVORY = 'F5F1E9';

    private const IVORY_DEEP = 'E4DBCB';

    private const INK = '132A27';

    private const MUTED = '526663';

    private const ON_DARK = 'C9D3D1';

    private const LINE = 'D8D0C2';

    private const LEFT = 40.0;

    private const RIGHT = 555.0;

    private const BOTTOM = 786.0;

    private BrandPdf $pdf;

    private float $y = 0;

    private int $contentPages = 0;

    private bool $overflow = false;

    private function __construct(private readonly ReportData $d, private readonly bool $cover, private readonly bool $tight) {}

    /** Whether the last render still did not fit its pages (checked by clarto:valoraciones-prueba). */
    public static bool $lastOverflow = false;

    public static function render(ReportData $data, bool $cover): string
    {
        foreach ([false, true] as $tight) {
            $brochure = new self($data, $cover, $tight);
            $bytes = $brochure->draw();
            self::$lastOverflow = $brochure->overflow;
            if (! $brochure->overflow) {
                return $bytes;
            }
        }

        return $bytes;
    }

    private function draw(): string
    {
        $this->pdf = new BrandPdf;
        if ($this->cover) {
            $this->coverPage();
        }
        $this->estimatePage();
        $this->detailPage();
        $this->overflow = $this->overflow || $this->contentPages > 2;

        return $this->pdf->output('Estimación orientativa '.$this->d->reference(), $this->d->trace());
    }

    // ── Cover ────────────────────────────────────────────────────────────────────────────────────────────

    private function coverPage(): void
    {
        $d = $this->d;
        $r = $d->r;
        $pdf = $this->pdf;
        $pdf->addPage();
        $pdf->rect(0, 0, BrandPdf::W, BrandPdf::H, self::PETROL);
        $pdf->jpeg(resource_path('valoraciones/logo-negativo.jpg'), self::LEFT - 6, 24, 176);
        $pdf->textRight(self::RIGHT, 38, 'INFORME DE ESTIMACIÓN', 'heading', 8.5, self::TERRA_LIGHT, 1.6);
        $pdf->textRight(self::RIGHT, 53, 'Ref. '.$d->reference().' · '.$d->date, 'text', 8.5, self::ON_DARK);

        [$photo, $caption, $fx, $fy] = $d->photo();
        $pdf->jpegCover($photo, 0, 86, BrandPdf::W, 404, $fx, $fy);
        $pdf->withAlpha(0.16, fn () => $pdf->rect(0, 86, BrandPdf::W, 404, self::PETROL));
        $this->fade(330, 490);
        $pdf->rect(0, 486, BrandPdf::W, 10, self::PETROL);   // covers the photo's last pixel row
        $pdf->textRight(self::RIGHT, 482, $caption, 'text', 6.5, self::ON_DARK);

        $pdf->rect(self::LEFT, 512, 44, 3, self::TERRA);
        $pdf->text(self::LEFT, 538, 'ESTIMACIÓN ORIENTATIVA DEL PRECIO DE VENTA', 'heading', 9, self::TERRA_LIGHT, 1.5);
        [$street, $unit] = $d->addressParts();
        $size = $pdf->width($street, 'display', 28) <= self::RIGHT - self::LEFT ? 28 : 22;
        $lines = array_slice($pdf->wrap($street, 'display', $size, self::RIGHT - self::LEFT), 0, 2);
        $y = 546;
        foreach ($lines as $line) {
            $y += $size * 1.15;
            $pdf->text(self::LEFT, $y, $line, 'display', $size, self::IVORY);
        }
        $y += 22;
        $pdf->text(self::LEFT, $y, trim(($unit !== '' ? $unit.'  ·  ' : '')), 'heading', 12, self::ON_DARK);
        $pdf->text(self::LEFT + ($unit !== '' ? $pdf->width($unit.'  ·  ', 'heading', 12) : 0), $y, mb_convert_case($d->municipality(), MB_CASE_TITLE), 'serif-italic', 15, self::TERRA_LIGHT);
        if ($d->contactName() !== '') {
            $pdf->text(self::LEFT, $y + 22, 'Preparado para '.$d->contactName(), 'text', 9, self::ON_DARK);
        }

        $top = 680;
        $pdf->rect(self::LEFT, $top, self::RIGHT - self::LEFT, 104, self::IVORY);
        $pdf->rect(self::LEFT, $top, 5, 104, self::TERRA);
        $pdf->text(self::LEFT + 24, $top + 26, 'PRECIO ESTIMADO DE CIERRE', 'heading', 8, self::TERRA_TEXT, 1.4);
        $pdf->text(self::LEFT + 24, $top + 64, ReportData::eur($r['precio_central']), 'display', 34, self::PETROL);
        $pdf->text(self::LEFT + 24, $top + 86, number_format((float) $r['eur_m2'], 0, ',', '.').' €/m² · suficiencia de los datos: '.$r['suficiencia'], 'text', 9, self::MUTED);
        $x = 330;
        $pdf->text($x, $top + 26, 'INTERVALO ORIENTATIVO', 'heading', 8, self::TERRA_TEXT, 1.4);
        $pdf->text($x, $top + 50, ReportData::eur($r['intervalo']['min']).' – '.ReportData::eur($r['intervalo']['max']), 'heading', 14, self::PETROL);
        $this->miniBar($x, $top + 64, self::RIGHT - 24 - $x);
        $pdf->text($x, $top + 90, 'Fecha de la estimación: '.$d->date, 'text', 8.5, self::MUTED);

        $pdf->text(self::LEFT, 818, 'Lo complejo, claro.', 'serif-italic', 12, self::TERRA_LIGHT);
        $pdf->textRight(self::RIGHT, 817, ReportData::WEB.'  ·  '.ReportData::PHONE.'  ·  WhatsApp '.ReportData::WHATSAPP, 'text', 8.5, self::ON_DARK);
    }

    // ── Content page 1: estimate, data, summary ─────────────────────────────────────────────────────────

    private function estimatePage(): void
    {
        $d = $this->d;
        $r = $d->r;
        $pdf = $this->pdf;
        $this->contentPage();

        if (! $this->cover) {
            [$photo, $caption, $fx, $fy] = $d->photo();
            $pdf->jpegCover($photo, 0, 62, BrandPdf::W, 196, $fx, $fy);
            $pdf->withAlpha(0.22, fn () => $pdf->rect(0, 62, BrandPdf::W, 196, self::PETROL));
            $this->fade(120, 258);
            $pdf->text(self::LEFT, 202, 'Estimación del precio de venta', 'display', 23, 'FFFFFF');
            $pdf->text(self::LEFT, 224, $this->fit($d->displayAddress(), 'text-bold', 11, self::RIGHT - self::LEFT), 'text-bold', 11, self::IVORY);
            $pdf->text(self::LEFT, 243, mb_convert_case($d->municipality(), MB_CASE_TITLE), 'serif-italic', 12, self::TERRA_LIGHT);
            $pdf->textRight(self::RIGHT, 250, $caption, 'text', 6, self::ON_DARK);
            $this->y = 276;
        } else {
            $pdf->text(self::LEFT, $this->y + 10, 'SU ESTIMACIÓN', 'heading', 8.5, self::TERRA_TEXT, 1.5);
            $pdf->text(self::LEFT, $this->y + 38, 'Estimación del precio de venta', 'display', 22, self::PETROL);
            $this->y = $pdf->paragraph(self::LEFT, $this->y + 44, self::RIGHT - self::LEFT, $d->displayAddress().' · '.mb_convert_case($d->municipality(), MB_CASE_TITLE), 'text', 10.5, self::MUTED) + 14;
        }

        // Price card with the gauge.
        $top = $this->y;
        $pdf->rect(self::LEFT, $top, self::RIGHT - self::LEFT, 150, self::IVORY);
        $this->gauge(self::LEFT + 104, $top + 98, 62);
        $x = self::LEFT + 216;
        $pdf->text($x, $top + 30, 'PRECIO ESTIMADO DE CIERRE', 'heading', 8, self::TERRA_TEXT, 1.4);
        $pdf->text($x, $top + 70, ReportData::eur($r['precio_central']), 'display', 32, self::PETROL);
        $pdf->text($x, $top + 92, 'Intervalo orientativo: '.ReportData::eur($r['intervalo']['min']).' – '.ReportData::eur($r['intervalo']['max']), 'text-bold', 10, self::INK);
        $pdf->text($x, $top + 110, number_format((float) $r['eur_m2'], 0, ',', '.').' €/m² · fecha '.$d->date.' · precio de cierre, no de oferta', 'text', 8.5, self::MUTED);
        $pdf->text($x, $top + 132, 'Suficiencia de los datos', 'text', 8.5, self::MUTED);
        $dots = $x + $pdf->width('Suficiencia de los datos', 'text', 8.5) + 12;
        for ($i = 1; $i <= 3; $i++) {
            $i <= $d->sufficiencyLevel()
                ? $pdf->circle($dots + ($i - 1) * 13, $top + 129, 4.2, self::PETROL)
                : $pdf->circle($dots + ($i - 1) * 13, $top + 129, 4.2, self::PETROL, false, 0.9);
        }
        $pdf->text($dots + 38, $top + 132, (string) $r['suficiencia'], 'text-bold', 8.5, self::PETROL);
        $this->y = $top + 168;

        // Property data with icons.
        $this->heading('Datos del inmueble');
        $rows = $d->propertyRows();
        $cell = $this->tight ? 36 : 40;
        $col = (self::RIGHT - self::LEFT) / 2;
        foreach (array_chunk($rows, 2) as $pair) {
            foreach ($pair as $i => [$icon, $label, $value]) {
                $cx = self::LEFT + $i * $col;
                $cy = $this->y + $cell / 2;
                $pdf->withAlpha(0.16, fn () => $pdf->circle($cx + 14, $cy, 13, self::TERRA));
                $this->icon($icon, $cx + 14, $cy, 6.5, self::TERRA_TEXT);
                $pdf->text($cx + 36, $cy - 3, mb_strtoupper($label), 'heading', 6.8, self::MUTED, 0.6);
                $pdf->text($cx + 36, $cy + 10, $this->fit($value, 'text-bold', 9.5, $col - 44), 'text-bold', 9.5, self::INK);
            }
            $this->y += $cell;
        }
        $this->y = $pdf->paragraph(self::LEFT, $this->y + 2, self::RIGHT - self::LEFT, $d->surfacesLine(), 'text', 8, self::MUTED) + 12;

        // Summary.
        $this->heading('Resumen');
        $pdf->text(self::LEFT - 2, $this->y + 34, '“', 'serif-italic', 46, self::TERRA_LIGHT);
        // The summary takes the room left on the page (cut with «…» if needed: the full text is in the panel).
        $notes = ($d->run->summary_source === 'ia' ? 14 : 0) + ($d->run->simulated ? 30 : 0);
        $room = (int) floor((self::BOTTOM - $notes - $this->y) / 15);
        $summary = (string) ($d->run->summary ?? '');
        $lines = $pdf->wrap($summary, 'text', 10, self::RIGHT - self::LEFT - 26);
        if (count($lines) > $room) {   // whole sentences that fit; the full text is in page 2's calculation and in the panel
            $kept = '';
            foreach (preg_split('/(?<=\.)\s+/u', $summary) ?: [] as $sentence) {
                if (count($pdf->wrap(trim($kept.' '.$sentence), 'text', 10, self::RIGHT - self::LEFT - 26)) > $room) {
                    break;
                }
                $kept = trim($kept.' '.$sentence);
            }
            $lines = $kept !== '' ? $pdf->wrap($kept, 'text', 10, self::RIGHT - self::LEFT - 26) : array_slice($lines, 0, max(1, $room));
        }
        foreach ($lines as $line) {
            $this->y += 15;
            $pdf->text(self::LEFT + 26, $this->y - 2.8, $line, 'text', 10, self::INK);
        }
        $this->y += 4;
        if ($d->run->summary_source === 'ia') {
            $this->y = $pdf->paragraph(self::LEFT + 26, $this->y, self::RIGHT - self::LEFT - 26, 'Resumen generado automáticamente a partir de los datos calculados.', 'serif-italic', 8.5, self::MUTED);
        }
        if ($d->run->simulated) {
            $this->y = $pdf->paragraph(self::LEFT, $this->y + 6, self::RIGHT - self::LEFT, 'ATENCIÓN: este informe usa datos SIMULADOS de un proveedor (entorno de pruebas). No es una estimación real.', 'text-bold', 9, self::TERRA_TEXT);
        }
        $this->overflow = $this->overflow || $this->y > self::BOTTOM;
    }

    // ── Content page 2: calculation, sources, observations, sales invitation ────────────────────────────

    private function detailPage(): void
    {
        $d = $this->d;
        $pdf = $this->pdf;
        $this->contentPage();

        $this->heading('Cómo se ha calculado');
        foreach ($d->calculationRows($this->tight ? 5 : 8) as [$label, $value]) {
            $lines = $pdf->wrap($value, 'text', 9, self::RIGHT - self::LEFT - 128);
            $pdf->text(self::LEFT, $this->y + 11, $label, 'heading', 8.5, self::MUTED);
            foreach ($lines as $i => $line) {
                $pdf->text(self::LEFT + 128, $this->y + 11 + $i * 12.5, $line, 'text', 9, self::INK);
            }
            $this->y += count($lines) * 12.5 + 5;
            $pdf->line(self::LEFT, $this->y, self::RIGHT, $this->y, self::LINE, 0.4);
        }
        $this->y += 12;

        $this->heading('Fuentes');
        foreach ($d->sourceLines($this->tight ? 3 : 4) as $line) {
            $this->bullet($line);
        }
        $this->y += 8;
        $this->heading('Observaciones');
        foreach ($d->observations($this->tight ? 5 : 7) as $line) {
            $this->bullet($line);
        }

        $height = $this->salesBlock(0, false);
        $this->salesBlock(max($this->y + 14, self::BOTTOM - 16 - $height), true);
    }

    /** The sales invitation (ReportData::SALES_*). Returns its height; draws it only with $draw. */
    private function salesBlock(float $top, bool $draw): float
    {
        $pdf = $this->pdf;
        $x = self::LEFT + 24;
        $width = self::RIGHT - self::LEFT - 48;
        $titleSize = $pdf->width(ReportData::SALES_TITLE, 'display', 17) <= $width ? 17 : 15;
        $titleLines = $pdf->wrap(ReportData::SALES_TITLE, 'display', $titleSize, $width);
        $leadLines = $pdf->wrap(ReportData::SALES_LEAD, 'serif-italic', 10, $width);
        $colWidth = $width / 3;
        $pillarHeight = 0;
        foreach (ReportData::SALES_PILLARS as [, $title, $text]) {
            $pillarHeight = max($pillarHeight, max(26, count($pdf->wrap($title, 'heading', 9.3, $colWidth - 40)) * 11.5 + 4) + count($pdf->wrap($text, 'text', 7.9, $colWidth - 12)) * 10.3);
        }
        $titleTop = $top + 44;
        $leadTop = $titleTop + (count($titleLines) - 1) * 19 + 4;
        $pillarsTop = $leadTop + count($leadLines) * 13.2 + 14;
        $bar = $pillarsTop + $pillarHeight + 10;
        $height = $bar + 34 + 12 - $top;
        if (! $draw) {
            return $height;
        }
        if ($top + $height > self::BOTTOM - 14) {
            $this->overflow = true;
        }

        $pdf->rect(self::LEFT, $top, self::RIGHT - self::LEFT, $height, self::PETROL);
        $pdf->withAlpha(0.5, fn () => $pdf->circle(self::RIGHT - 52, $top + 52, 42, self::PETROL_SOFT));
        $pdf->text($x, $top + 24, ReportData::SALES_EYEBROW, 'heading', 8, self::TERRA_LIGHT, 1.6);
        foreach ($titleLines as $i => $line) {
            $pdf->text($x, $titleTop + $i * 19, $line, 'display', $titleSize, 'FFFFFF');
        }
        foreach ($leadLines as $i => $line) {
            $pdf->text($x, $leadTop + 11 + $i * 13.2, $line, 'serif-italic', 10, self::TERRA_LIGHT);
        }
        foreach (ReportData::SALES_PILLARS as $i => [$icon, $title, $text]) {
            $px = $x + $i * $colWidth;
            $pdf->circle($px + 11, $pillarsTop + 11, 11, self::TERRA);
            $this->icon($icon, $px + 11, $pillarsTop + 11, 5.6, 'FFFFFF');
            $titleLines = $pdf->wrap($title, 'heading', 9.3, $colWidth - 40);
            foreach ($titleLines as $j => $line) {
                $pdf->text($px + 28, $pillarsTop + 9 + $j * 11.5 + (count($titleLines) === 1 ? 6 : 0), $line, 'heading', 9.3, 'FFFFFF');
            }
            $pdf->paragraph($px, $pillarsTop + max(26, count($titleLines) * 11.5 + 4), $colWidth - 12, $text, 'text', 7.9, self::ON_DARK, 1.3);
        }
        $pdf->rect($x - 8, $bar, $width + 16, 34, self::TERRA);
        $pdf->text($x + 4, $bar + 22, ReportData::SALES_CTA, 'heading', 11.5, 'FFFFFF');
        $pdf->textRight($x + $width - 4, $bar + 22, ReportData::PHONE.'  ·  WhatsApp '.ReportData::WHATSAPP, 'text-bold', 9, 'FFFFFF');
        $this->y = $top + $height;
        $pdf->textCenter(BrandPdf::W / 2, $this->y + 13, ReportData::EMAIL.'  ·  '.ReportData::WEB, 'text-bold', 8.5, self::TERRA_TEXT);

        return $height;
    }

    // ── Shared pieces ────────────────────────────────────────────────────────────────────────────────────

    private function contentPage(): void
    {
        $pdf = $this->pdf;
        $pdf->addPage();
        $this->contentPages++;
        $number = $pdf->pageCount();
        $total = $this->cover ? 3 : 2;
        $pdf->rect(0, 0, BrandPdf::W, 62, self::PETROL);
        $pdf->jpeg(resource_path('valoraciones/logo-negativo.jpg'), self::LEFT - 5, 17, 146);
        $pdf->textRight(self::RIGHT, 29, 'ESTIMACIÓN ORIENTATIVA', 'heading', 8, self::TERRA_LIGHT, 1.5);
        $pdf->textRight(self::RIGHT, 43, 'Ref. '.$this->d->reference().' · '.$this->d->date, 'text', 8, self::ON_DARK);
        $pdf->line(self::LEFT, 806, self::RIGHT, 806, self::TERRA, 1);
        $pdf->text(self::LEFT, 822, 'CLARTO · ', 'heading', 8, self::PETROL);
        $pdf->text(self::LEFT + $pdf->width('CLARTO · ', 'heading', 8), 822, 'Lo complejo, claro.', 'serif-italic', 9, self::TERRA_TEXT);
        $pdf->textRight(self::RIGHT, 822, 'Estimación orientativa · no es una tasación oficial · página '.$number.' de '.$total, 'text', 7.5, self::MUTED);
        $this->y = 86;
    }

    private function heading(string $title): void
    {
        $this->pdf->text(self::LEFT, $this->y + 14, $title, 'heading', 14, self::PETROL);
        $this->pdf->rect(self::LEFT, $this->y + 21, 30, 2.2, self::TERRA);
        $this->y += 32;
    }

    private function bullet(string $text): void
    {
        $this->pdf->rect(self::LEFT + 1, $this->y + 6, 3.5, 3.5, self::TERRA);
        $this->y = $this->pdf->paragraph(self::LEFT + 12, $this->y, self::RIGHT - self::LEFT - 12, $text, 'text', $this->tight ? 7.4 : 8, self::INK, $this->tight ? 1.3 : 1.35) + ($this->tight ? 2 : 3);
    }

    /** Semicircular gauge: the interval on a scale 15 % wider each side, the needle on the central price. */
    private function gauge(float $cx, float $cy, float $radius): void
    {
        $pdf = $this->pdf;
        $r = $this->d->r;
        [$min, $max, $central] = [(float) $r['intervalo']['min'], (float) $r['intervalo']['max'], (float) $r['precio_central']];
        $lo = $min * 0.85;
        $hi = max($max * 1.15, $lo + 1);
        $angle = fn (float $v): float => 180 - 180 * (max($lo, min($hi, $v)) - $lo) / ($hi - $lo);
        $pdf->arc($cx, $cy, $radius, 0, 180, self::IVORY_DEEP, 14);
        $pdf->arc($cx, $cy, $radius, $angle($max), $angle($min), self::TERRA_LIGHT, 14);
        $a = deg2rad($angle($central));
        $pdf->line($cx, $cy, $cx + ($radius - 4) * cos($a), $cy - ($radius - 4) * sin($a), self::PETROL, 2.6);
        $pdf->circle($cx, $cy, 5.5, self::PETROL);
        $pdf->textCenter($cx - $radius, $cy + 18, $this->short($min), 'text-bold', 8, self::MUTED);
        $pdf->textCenter($cx + $radius, $cy + 18, $this->short($max), 'text-bold', 8, self::MUTED);
        $pdf->textCenter($cx, $cy + 34, 'Intervalo orientativo', 'text', 7.5, self::MUTED);
    }

    private function miniBar(float $x, float $y, float $width): void
    {
        $pdf = $this->pdf;
        $r = $this->d->r;
        [$min, $max, $central] = [(float) $r['intervalo']['min'], (float) $r['intervalo']['max'], (float) $r['precio_central']];
        $pdf->rect($x, $y, $width, 5, self::IVORY_DEEP);
        $pdf->rect($x + $width * 0.13, $y, $width * 0.74, 5, self::TERRA_LIGHT);
        $pos = $x + $width * (0.13 + 0.74 * ($max > $min ? ($central - $min) / ($max - $min) : 0.5));
        $pdf->rect($pos - 1.5, $y - 4, 3, 13, self::PETROL);
    }

    /** Photo fading into petrol between two heights (thin strips of growing opacity). */
    private function fade(float $from, float $to): void
    {
        // Overlapping layers (each from its start to the bottom), so there are no seams between strips.
        $steps = 60;
        $h = ($to - $from) / $steps;
        for ($i = 0; $i < $steps; $i++) {
            $this->pdf->withAlpha(0.05, fn () => $this->pdf->rect(0, $from + $i * $h, BrandPdf::W, $to - $from - $i * $h + 2, self::PETROL));
        }
        $this->pdf->rect(0, $to - 8, BrandPdf::W, 8, self::PETROL);
    }

    /** «152.000 €» → «152 mil €» for the gauge ends. */
    private function short(float $value): string
    {
        return number_format(round($value / 1000), 0, ',', '.').' mil €';
    }

    /** The text, shortened with «…» to fit the width. */
    private function fit(string $text, string $font, float $size, float $width): string
    {
        if ($this->pdf->width($text, $font, $size) <= $width) {
            return $text;
        }
        while (mb_strlen($text) > 4 && $this->pdf->width($text.'…', $font, $size) > $width) {
            $text = mb_substr($text, 0, -1);
        }

        return rtrim($text).'…';
    }

    /** Line pictograms (stroke), centred on ($cx, $cy), half-size $s. */
    private function icon(string $name, float $cx, float $cy, float $s, string $hex): void
    {
        $p = $this->pdf;
        $w = 1.15;
        match ($name) {
            'documento' => (function () use ($p, $cx, $cy, $s, $hex, $w): void {
                $p->path([[$cx - 0.65 * $s, $cy - $s], [$cx + 0.3 * $s, $cy - $s], [$cx + 0.65 * $s, $cy - 0.6 * $s], [$cx + 0.65 * $s, $cy + $s], [$cx - 0.65 * $s, $cy + $s]], $hex, $w, false, true);
                foreach ([-0.25, 0.15, 0.55] as $k) {
                    $p->line($cx - 0.35 * $s, $cy + $k * $s, $cx + 0.35 * $s, $cy + $k * $s, $hex, $w * 0.8);
                }
            })(),
            'superficie' => (function () use ($p, $cx, $cy, $s, $hex, $w): void {
                $p->path([[$cx - $s, $cy - $s], [$cx + $s, $cy - $s], [$cx + $s, $cy + $s], [$cx - $s, $cy + $s]], $hex, $w, false, true);
                $p->line($cx - 0.5 * $s, $cy + 0.5 * $s, $cx + 0.5 * $s, $cy - 0.5 * $s, $hex, $w);
                $p->path([[$cx + 0.05 * $s, $cy - 0.5 * $s], [$cx + 0.5 * $s, $cy - 0.5 * $s], [$cx + 0.5 * $s, $cy - 0.05 * $s]], $hex, $w);
            })(),
            'casa' => $p->path([[$cx - $s, $cy - 0.05 * $s], [$cx, $cy - $s], [$cx + $s, $cy - 0.05 * $s], [$cx + 0.7 * $s, $cy - 0.05 * $s], [$cx + 0.7 * $s, $cy + $s], [$cx - 0.7 * $s, $cy + $s], [$cx - 0.7 * $s, $cy - 0.05 * $s]], $hex, $w, false, true),
            'calendario' => (function () use ($p, $cx, $cy, $s, $hex, $w): void {
                $p->path([[$cx - $s, $cy - 0.7 * $s], [$cx + $s, $cy - 0.7 * $s], [$cx + $s, $cy + $s], [$cx - $s, $cy + $s]], $hex, $w, false, true);
                $p->line($cx - $s, $cy - 0.15 * $s, $cx + $s, $cy - 0.15 * $s, $hex, $w);
                $p->line($cx - 0.45 * $s, $cy - $s, $cx - 0.45 * $s, $cy - 0.45 * $s, $hex, $w);
                $p->line($cx + 0.45 * $s, $cy - $s, $cx + 0.45 * $s, $cy - 0.45 * $s, $hex, $w);
            })(),
            'planta' => $p->path([[$cx - $s, $cy + $s], [$cx - $s, $cy + 0.33 * $s], [$cx - 0.33 * $s, $cy + 0.33 * $s], [$cx - 0.33 * $s, $cy - 0.33 * $s], [$cx + 0.33 * $s, $cy - 0.33 * $s], [$cx + 0.33 * $s, $cy - $s], [$cx + $s, $cy - $s]], $hex, $w),
            'estado', 'check' => (function () use ($p, $cx, $cy, $s, $hex, $w, $name): void {
                if ($name === 'estado') {
                    $p->circle($cx, $cy, $s, $hex, false, $w);
                }
                $p->path([[$cx - 0.5 * $s, $cy], [$cx - 0.1 * $s, $cy + 0.4 * $s], [$cx + 0.55 * $s, $cy - 0.45 * $s]], $hex, $w * 1.3);
            })(),
            'dormitorio' => (function () use ($p, $cx, $cy, $s, $hex, $w): void {
                $p->line($cx - $s, $cy - 0.7 * $s, $cx - $s, $cy + 0.8 * $s, $hex, $w);
                $p->path([[$cx - $s, $cy - 0.05 * $s], [$cx + $s, $cy - 0.05 * $s], [$cx + $s, $cy + 0.8 * $s]], $hex, $w);
                $p->line($cx - $s, $cy + 0.45 * $s, $cx + $s, $cy + 0.45 * $s, $hex, $w);
                $p->path([[$cx - 0.75 * $s, $cy - 0.05 * $s], [$cx - 0.75 * $s, $cy - 0.45 * $s], [$cx - 0.15 * $s, $cy - 0.45 * $s], [$cx - 0.15 * $s, $cy - 0.05 * $s]], $hex, $w);
            })(),
            'sol' => (function () use ($p, $cx, $cy, $s, $hex, $w): void {
                $p->circle($cx, $cy, 0.42 * $s, $hex, false, $w);
                for ($i = 0; $i < 8; $i++) {
                    $a = $i * M_PI / 4;
                    $p->line($cx + 0.68 * $s * cos($a), $cy + 0.68 * $s * sin($a), $cx + $s * cos($a), $cy + $s * sin($a), $hex, $w);
                }
            })(),
            'coche' => (function () use ($p, $cx, $cy, $s, $hex, $w): void {
                $p->path([[$cx - $s, $cy + 0.45 * $s], [$cx - $s, $cy - 0.05 * $s], [$cx - 0.6 * $s, $cy - 0.15 * $s], [$cx - 0.35 * $s, $cy - 0.65 * $s], [$cx + 0.35 * $s, $cy - 0.65 * $s], [$cx + 0.6 * $s, $cy - 0.15 * $s], [$cx + $s, $cy - 0.05 * $s], [$cx + $s, $cy + 0.45 * $s]], $hex, $w, false, true);
                $p->circle($cx - 0.5 * $s, $cy + 0.55 * $s, 0.22 * $s, $hex);
                $p->circle($cx + 0.5 * $s, $cy + 0.55 * $s, 0.22 * $s, $hex);
            })(),
            'globo' => (function () use ($p, $cx, $cy, $s, $hex, $w): void {
                $p->circle($cx, $cy, $s, $hex, false, $w);
                $p->line($cx - $s, $cy, $cx + $s, $cy, $hex, $w * 0.8);
                $p->line($cx, $cy - $s, $cx, $cy + $s, $hex, $w * 0.8);
                $p->line($cx - 0.85 * $s, $cy - 0.5 * $s, $cx + 0.85 * $s, $cy - 0.5 * $s, $hex, $w * 0.7);
                $p->line($cx - 0.85 * $s, $cy + 0.5 * $s, $cx + 0.85 * $s, $cy + 0.5 * $s, $hex, $w * 0.7);
            })(),
            'tecnologia' => (function () use ($p, $cx, $cy, $s, $hex, $w): void {
                $p->path([[$cx - 0.6 * $s, $cy - 0.6 * $s], [$cx + 0.6 * $s, $cy - 0.6 * $s], [$cx + 0.6 * $s, $cy + 0.6 * $s], [$cx - 0.6 * $s, $cy + 0.6 * $s]], $hex, $w, false, true);
                foreach ([-0.3, 0.3] as $k) {
                    $p->line($cx + $k * $s, $cy - 0.6 * $s, $cx + $k * $s, $cy - $s, $hex, $w);
                    $p->line($cx + $k * $s, $cy + 0.6 * $s, $cx + $k * $s, $cy + $s, $hex, $w);
                    $p->line($cx - 0.6 * $s, $cy + $k * $s, $cx - $s, $cy + $k * $s, $hex, $w);
                    $p->line($cx + 0.6 * $s, $cy + $k * $s, $cx + $s, $cy + $k * $s, $hex, $w);
                }
                $p->circle($cx, $cy, 0.2 * $s, $hex);
            })(),
            'video' => (function () use ($p, $cx, $cy, $s, $hex, $w): void {
                $p->path([[$cx - $s, $cy - 0.55 * $s], [$cx + 0.3 * $s, $cy - 0.55 * $s], [$cx + 0.3 * $s, $cy + 0.55 * $s], [$cx - $s, $cy + 0.55 * $s]], $hex, $w, false, true);
                $p->path([[$cx + 0.3 * $s, $cy - 0.12 * $s], [$cx + $s, $cy - 0.5 * $s], [$cx + $s, $cy + 0.5 * $s], [$cx + 0.3 * $s, $cy + 0.12 * $s]], $hex, $w, false, true);
                $p->circle($cx - 0.6 * $s, $cy - 0.18 * $s, 0.13 * $s, $hex);
            })(),
            'equipo' => (function () use ($p, $cx, $cy, $s, $hex, $w): void {
                foreach ([-0.42, 0.42] as $k) {
                    $p->circle($cx + $k * $s, $cy - 0.38 * $s, 0.27 * $s, $hex, false, $w);
                    $p->arc($cx + $k * $s, $cy + 0.75 * $s, 0.48 * $s, 0, 180, $hex, $w);
                }
            })(),
            default => $p->circle($cx, $cy, 0.4 * $s, $hex),
        };
    }
}
