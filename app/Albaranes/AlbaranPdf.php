<?php

declare(strict_types=1);

namespace App\Albaranes;

/**
 * The albarán as a PDF (A4, Helvetica), written directly: the panel has no PDF library and this needs no more
 * than text and lines. Amounts are before taxes; the document says it is not an invoice.
 *
 * ponytail: WinAnsi text only (Spanish/English fine; Turkish ğ/ş/ı are transliterated). Add a PDF library
 * when albaranes need logos or non-Latin-1 text.
 */
final class AlbaranPdf
{
    /** @var list<string> content stream of each page */
    private array $pages = [];

    private string $ops = '';

    private float $y = 0;

    public static function render(Albaran $a): string
    {
        $pdf = new self;
        $pdf->newPage();
        $money = fn (float $v): string => number_format($v, 2, ',', '.').' €';

        $pdf->text(50, 18, (string) config('app.name', 'CLARTO'), bold: true);
        $pdf->text(330, 14, 'ALBARÁN DE SERVICIO', bold: true);
        $pdf->down(18);
        $pdf->text(330, 10, 'Nº '.$a->number);
        $pdf->down(14);
        $pdf->text(330, 10, 'Fecha del servicio: '.$a->service_date?->format('d/m/Y'));
        $pdf->down(26);

        $pdf->text(50, 10, 'CLIENTE', bold: true);
        $pdf->down(14);
        foreach (array_filter([$a->client_name, $a->client_tax_id ? 'NIF: '.$a->client_tax_id : null, $a->client_address, trim(($a->client_email ?? '').'  '.($a->client_phone ?? ''))]) as $row) {
            $pdf->text(50, 10, (string) $row);
            $pdf->down(13);
        }
        if ($a->property !== null || $a->instruction_id !== null) {
            $pdf->down(4);
            $pdf->text(50, 10, implode('   ', array_filter([
                $a->property ? 'Inmueble: '.$a->property->reference().' · '.mb_strimwidth((string) $a->property->title, 0, 60, '…') : null,
                $a->instruction_id ? 'Encargo nº '.$a->instruction_id : null,
            ])));
            $pdf->down(13);
        }
        $pdf->down(14);

        $header = function () use ($pdf): void {
            $pdf->line(50, 545);
            $pdf->down(13);
            foreach ([[50, 'Concepto'], [340, 'Cant.'], [390, 'Precio'], [455, 'Dto.'], [495, 'Importe']] as [$x, $label]) {
                $pdf->text($x, 9, $label, bold: true);
            }
            $pdf->down(6);
            $pdf->line(50, 545);
            $pdf->down(14);
        };
        $header();

        foreach ($a->lines as $l) {
            $rows = explode("\n", wordwrap(trim(($l->service_code ? $l->service_code.' · ' : '').$l->description), 58, "\n", true));
            if ($pdf->y - 13 * count($rows) < 120) {
                $pdf->newPage();
                $header();
            }
            $pdf->text(340, 9, rtrim(rtrim(number_format((float) $l->quantity, 2, ',', '.'), '0'), ','));
            $pdf->text(390, 9, $money((float) $l->unit_price));
            $pdf->text(455, 9, (float) $l->discount_pct > 0 ? rtrim(rtrim(number_format((float) $l->discount_pct, 2, ',', ''), '0'), ',').' %' : '');
            $pdf->text(495, 9, $money((float) $l->amount));
            foreach ($rows as $row) {
                $pdf->text(50, 9, $row);
                $pdf->down(12);
            }
            $pdf->down(3);
        }

        $pdf->line(50, 545);
        $pdf->down(16);
        $pdf->text(330, 10, 'Total (sin impuestos): '.$money((float) $a->total), bold: true);
        $pdf->down(24);
        if (filled($a->notes)) {
            foreach (explode("\n", wordwrap((string) $a->notes, 95, "\n", true)) as $row) {
                $pdf->text(50, 9, $row);
                $pdf->down(12);
            }
            $pdf->down(8);
        }
        $pdf->text(50, 8, 'Este albarán no es una factura. La factura, con su numeración e impuestos, la emite el programa de facturación.');
        $pdf->down(36);

        if ($a->conformity !== Albaran::CONFORMITY_NONE) {
            $pdf->text(50, 10, 'Conforme el cliente (nombre, firma y fecha):', bold: true);
            $pdf->down(50);
            $pdf->line(50, 300);
        }

        return $pdf->output();
    }

    private function newPage(): void
    {
        if ($this->ops !== '') {
            $this->pages[] = $this->ops;
        }
        $this->ops = '';
        $this->y = 790;
    }

    private function down(float $points): void
    {
        $this->y -= $points;
    }

    private function text(float $x, float $size, string $text, bool $bold = false): void
    {
        $encoded = iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);
        $escaped = strtr($encoded === false ? $text : $encoded, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '', "\n" => ' ']);
        $this->ops .= sprintf("BT /%s %.1F Tf %.2F %.2F Td (%s) Tj ET\n", $bold ? 'F2' : 'F1', $size, $x, $this->y, $escaped);
    }

    private function line(float $x1, float $x2): void
    {
        $this->ops .= sprintf("0.5 w %.2F %.2F m %.2F %.2F l S\n", $x1, $this->y, $x2, $this->y);
    }

    private function output(): string
    {
        $this->pages[] = $this->ops;
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
        ];
        $kids = [];
        foreach ($this->pages as $i => $content) {
            $page = 5 + $i * 2;
            $kids[] = "{$page} 0 R";
            $objects[$page] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents '.($page + 1).' 0 R >>';
            $objects[$page + 1] = '<< /Length '.strlen($content)." >>\nstream\n{$content}endstream";
        }
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($kids).' >>';
        ksort($objects);

        $out = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $n => $body) {
            $offsets[$n] = strlen($out);
            $out .= "{$n} 0 obj\n{$body}\nendobj\n";
        }
        $xref = strlen($out);
        $out .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $out .= sprintf("%010d 00000 n \n", $offset);
        }

        return $out.'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }
}
