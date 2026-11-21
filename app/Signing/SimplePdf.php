<?php

declare(strict_types=1);

namespace App\Signing;

/**
 * Minimal text-only PDF (A4, Helvetica, WinAnsi) for the preview of a filled template. Deterministic: the same text
 * gives the same bytes, so its SHA-256 identifies the content.
 *
 * ponytail: plain text only (no layout, images or tables); the final Spanish documents are DocuSeal templates or
 * uploaded PDFs, this only renders the preview of the values.
 */
final class SimplePdf
{
    public static function make(string $title, string $text): string
    {
        $lines = [['B', $title], ['', '']];
        foreach (preg_split('/\R/u', $text) ?: [] as $paragraph) {
            foreach (explode("\n", wordwrap($paragraph, 95, "\n", true)) as $line) {
                $lines[] = ['', $line];
            }
        }

        $pages = array_chunk($lines, 56);
        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $kids = [];
        $n = 5;
        foreach ($pages as $pageLines) {
            $stream = "BT\n/F1 10 Tf\n13 TL\n50 792 Td\n";
            foreach ($pageLines as [$style, $line]) {
                $stream .= ($style === 'B' ? '/F2 12 Tf ' : '/F1 10 Tf ').'('.self::escape($line).") Tj T*\n";
            }
            $stream .= 'ET';
            $objects[$n] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ".($n + 1).' 0 R >>';
            $objects[$n + 1] = '<< /Length '.strlen($stream)." >>\nstream\n".$stream."\nendstream";
            $kids[] = $n.' 0 R';
            $n += 2;
        }
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($kids).' >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $body) {
            $offsets[$i] = strlen($pdf);
            $pdf .= $i." 0 obj\n".$body."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    }

    private static function escape(string $line): string
    {
        $line = (string) mb_convert_encoding($line, 'Windows-1252', 'UTF-8');

        return strtr($line, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '']);
    }
}
