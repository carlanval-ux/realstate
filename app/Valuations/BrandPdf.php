<?php

declare(strict_types=1);

namespace App\Valuations;

/**
 * Small PDF canvas for CLARTO reports: A4 pages, Helvetica (regular, bold, oblique, WinAnsi: accents and €), filled
 * rectangles, lines, JPEG images and word-wrapped text measured with the Helvetica metrics. Coordinates are points
 * from the TOP-left corner. Deterministic output (no dates inside), so tests can count pages from the bytes.
 *
 * ponytail: no external PDF library (none is installed and the image build stays as is); the four standard fonts
 * cover the report. Use dompdf if reports ever need HTML layouts, embedded fonts or tables that split across pages.
 */
final class BrandPdf
{
    public const W = 595.0;

    public const H = 842.0;

    /** Helvetica / Helvetica-Bold advance widths (1/1000 em) for ASCII 32–126 (Adobe AFM). */
    private const REGULAR = [278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556, 1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556, 333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556, 556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584];

    private const BOLD = [278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 333, 333, 584, 584, 584, 611, 975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 333, 278, 333, 584, 556, 333, 556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611, 611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500, 389, 280, 389, 584];

    /** Widths of the non-ASCII characters the reports use (others: the width of their ASCII base letter). */
    private const EXTRA = ['€' => 556, '·' => 278, '«' => 556, '»' => 556, '–' => 556, '—' => 1000, '’' => 222, '“' => 333, '”' => 333, 'º' => 365, 'ª' => 370, '²' => 333, '•' => 350];

    private const FONTS = ['regular' => 'F1', 'bold' => 'F2', 'italic' => 'F3'];

    /** @var list<string> content stream of each page */
    private array $pages = [];

    /** @var array<string, array{data: string, w: int, h: int}> */
    private array $images = [];

    public function addPage(): int
    {
        $this->pages[] = '';

        return count($this->pages);
    }

    public function pageCount(): int
    {
        return count($this->pages);
    }

    public function rect(float $x, float $y, float $w, float $h, string $hex): void
    {
        $this->out(sprintf("%s rg %.2F %.2F %.2F %.2F re f\n", self::rgb($hex), $x, self::H - $y - $h, $w, $h));
    }

    public function line(float $x1, float $y1, float $x2, float $y2, string $hex, float $width = 0.6): void
    {
        $this->out(sprintf("%s RG %.2F w %.2F %.2F m %.2F %.2F l S\n", self::rgb($hex), $width, $x1, self::H - $y1, $x2, self::H - $y2));
    }

    /** $y is the text baseline. */
    public function text(float $x, float $y, string $text, string $font = 'regular', float $size = 10, string $hex = '132A27', float $spacing = 0): void
    {
        $this->out(sprintf("BT /%s %.2F Tf %s rg %.2F Tc %.2F %.2F Td (%s) Tj ET\n", self::FONTS[$font], $size, self::rgb($hex), $spacing, $x, self::H - $y, self::escape($text)));
    }

    public function textRight(float $right, float $y, string $text, string $font = 'regular', float $size = 10, string $hex = '132A27'): void
    {
        $this->text($right - $this->width($text, $font, $size), $y, $text, $font, $size, $hex);
    }

    /**
     * Wrapped paragraph from $y (top of the first line). Returns the y below the last line.
     */
    public function paragraph(float $x, float $y, float $width, string $text, string $font = 'regular', float $size = 9.5, string $hex = '132A27', float $leading = 1.38): float
    {
        foreach ($this->wrap($text, $font, $size, $width) as $line) {
            $y += $size * $leading;
            $this->text($x, $y - $size * 0.28, $line, $font, $size, $hex);
        }

        return $y;
    }

    /** @return list<string> */
    public function wrap(string $text, string $font, float $size, float $width): array
    {
        $lines = [];
        foreach (preg_split('/\R/u', trim($text)) ?: [] as $paragraph) {
            $line = '';
            foreach (preg_split('/\s+/u', trim($paragraph)) ?: [] as $word) {
                $try = $line === '' ? $word : $line.' '.$word;
                if ($line !== '' && $this->width($try, $font, $size) > $width) {
                    $lines[] = $line;
                    $line = $word;
                } else {
                    $line = $try;
                }
            }
            $lines[] = $line;
        }

        return $lines;
    }

    public function width(string $text, string $font = 'regular', float $size = 10): float
    {
        $table = $font === 'bold' ? self::BOLD : self::REGULAR;
        $units = 0;
        foreach (mb_str_split($text) as $char) {
            $base = self::EXTRA[$char] ?? null;
            if ($base === null) {
                $ascii = $char === '' ? ' ' : (\Illuminate\Support\Str::ascii($char)[0] ?? ' ');
                $code = ord($ascii);
                $base = $code >= 32 && $code <= 126 ? $table[$code - 32] : 556;
            }
            $units += $base;
        }

        return $units * $size / 1000;
    }

    /** JPEG file, drawn at ($x, $y) top-left with width $w (height keeps the ratio). Returns the height. */
    public function jpeg(string $path, float $x, float $y, float $w): float
    {
        $name = 'Im'.substr(sha1($path), 0, 6);
        if (! isset($this->images[$name])) {
            $size = getimagesize($path);
            $this->images[$name] = ['data' => (string) file_get_contents($path), 'w' => (int) ($size[0] ?? 1), 'h' => (int) ($size[1] ?? 1)];
        }
        $h = $w * $this->images[$name]['h'] / $this->images[$name]['w'];
        $this->out(sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q\n", $w, $h, $x, self::H - $y - $h, $name));

        return $h;
    }

    public function output(string $title): string
    {
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Oblique /Encoding /WinAnsiEncoding >>',
            6 => '<< /Title ('.self::escape($title).') /Producer (CLARTO Gestion Inmobiliaria) >>',
        ];
        $n = 7;
        $xobjects = [];
        foreach ($this->images as $name => $image) {
            $objects[$n] = "<< /Type /XObject /Subtype /Image /Width {$image['w']} /Height {$image['h']} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ".strlen($image['data'])." >>\nstream\n".$image['data']."\nendstream";
            $xobjects[] = "/{$name} {$n} 0 R";
            $n++;
        }
        $resources = '<< /Font << /F1 3 0 R /F2 4 0 R /F3 5 0 R >> /XObject << '.implode(' ', $xobjects).' >> >>';
        $kids = [];
        foreach ($this->pages as $stream) {
            $compressed = (string) gzcompress($stream);
            $objects[$n] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '.self::W.' '.self::H.'] /Resources '.$resources.' /Contents '.($n + 1).' 0 R >>';
            $objects[$n + 1] = '<< /Length '.strlen($compressed)." /Filter /FlateDecode >>\nstream\n".$compressed."\nendstream";
            $kids[] = $n.' 0 R';
            $n += 2;
        }
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($kids).' >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $i => $body) {
            $offsets[$i] = strlen($pdf);
            $pdf .= $i." 0 obj\n".$body."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".($n)."\n0000000000 65535 f \n";
        for ($i = 1; $i < $n; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        }

        return $pdf.'trailer << /Size '.$n." /Root 1 0 R /Info 6 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    }

    /** Pages of a PDF, read from its bytes (page objects, not the /Pages tree). */
    public static function countPages(string $pdf): int
    {
        return preg_match_all('#/Type\s*/Page(?![a-zA-Z])#', $pdf);
    }

    private function out(string $operator): void
    {
        if ($this->pages === []) {
            $this->addPage();
        }
        $this->pages[count($this->pages) - 1] .= $operator;
    }

    private static function rgb(string $hex): string
    {
        $hex = ltrim($hex, '#');

        return sprintf('%.3F %.3F %.3F', hexdec(substr($hex, 0, 2)) / 255, hexdec(substr($hex, 2, 2)) / 255, hexdec(substr($hex, 4, 2)) / 255);
    }

    private static function escape(string $text): string
    {
        $text = (string) mb_convert_encoding(str_replace(['•'], ['·'], $text), 'Windows-1252', 'UTF-8');

        return strtr($text, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '', "\n" => ' ']);
    }
}
