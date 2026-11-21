<?php

declare(strict_types=1);

namespace App\Valuations;

use Illuminate\Support\Str;

/**
 * Small PDF canvas for CLARTO reports: A4 pages, the standard Helvetica fonts and the CLARTO TrueType fonts
 * (resources/valoraciones/fonts, OFL, embedded whole with WinAnsi encoding: accents and €), filled and stroked shapes,
 * Bézier arcs, opacity, JPEG images (also cropped to a box) and word-wrapped text measured with each font's metrics.
 * Coordinates are points from the TOP-left corner.
 *
 * ponytail: no external PDF library (none is installed and the image build stays as is). Use dompdf/a PDF library if
 * reports ever need HTML layouts or tables that split across pages.
 */
final class BrandPdf
{
    public const W = 595.0;

    public const H = 842.0;

    /** Helvetica / Helvetica-Bold advance widths (1/1000 em) for ASCII 32–126 (Adobe AFM). */
    private const REGULAR = [278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556, 1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556, 333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556, 556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584];

    private const BOLD = [278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 333, 333, 584, 584, 584, 611, 975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 333, 278, 333, 584, 556, 333, 556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611, 611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500, 389, 280, 389, 584];

    /** Widths of the non-ASCII characters (Helvetica) the reports use (others: the width of their ASCII base letter). */
    private const EXTRA = ['€' => 556, '·' => 278, '«' => 556, '»' => 556, '–' => 556, '—' => 1000, '’' => 222, '“' => 333, '”' => 333, 'º' => 365, 'ª' => 370, '²' => 333];

    private const STANDARD = ['regular' => ['F1', 'Helvetica'], 'bold' => ['F2', 'Helvetica-Bold'], 'italic' => ['F3', 'Helvetica-Oblique']];

    /** CLARTO fonts: name => [file, PostScript name, italic]. */
    public const BRAND_FONTS = [
        'display' => ['manrope-800.ttf', 'Manrope-ExtraBold', false],
        'heading' => ['manrope-700.ttf', 'Manrope-Bold', false],
        'text' => ['dmsans-400.ttf', 'DMSans-Regular', false],
        'text-bold' => ['dmsans-700.ttf', 'DMSans-Bold', false],
        'serif-italic' => ['serif-400i.ttf', 'SourceSerif4-Italic', true],
    ];

    /** @var list<string> content stream of each page */
    private array $pages = [];

    /** @var array<string, array{data: string, w: int, h: int}> */
    private array $images = [];

    /** @var array<string, string> opacity => graphics state name */
    private array $alphas = [];

    /** @var array<string, array{widths: array<int, int>, data: string, bbox: list<int>, ascent: int, descent: int, cap: int, italic: bool, ps: string, ref: string}> */
    private static array $ttf = [];

    /** @var array<string, true> brand fonts used in this document */
    private array $usedTtf = [];

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
        $this->out(sprintf("%s RG %.2F w 1 J %.2F %.2F m %.2F %.2F l S\n", self::rgb($hex), $width, $x1, self::H - $y1, $x2, self::H - $y2));
    }

    /** @param list<array{0: float, 1: float}> $points polyline (stroked) or polygon ($fill) */
    public function path(array $points, string $hex, float $width = 1.2, bool $fill = false, bool $close = false): void
    {
        $ops = [];
        foreach ($points as $i => [$x, $y]) {
            $ops[] = sprintf('%.2F %.2F %s', $x, self::H - $y, $i === 0 ? 'm' : 'l');
        }
        $color = self::rgb($hex);
        $this->out(($fill ? "{$color} rg " : sprintf('%s RG %.2F w 1 J 1 j ', $color, $width)).implode(' ', $ops).($close || $fill ? ' h' : '').($fill ? ' f' : ' S')."\n");
    }

    public function circle(float $cx, float $cy, float $r, string $hex, bool $fill = true, float $width = 1.2): void
    {
        $k = 0.5523 * $r;
        $y = self::H - $cy;
        $d = sprintf('%.2F %.2F m %.2F %.2F %.2F %.2F %.2F %.2F c %.2F %.2F %.2F %.2F %.2F %.2F c %.2F %.2F %.2F %.2F %.2F %.2F c %.2F %.2F %.2F %.2F %.2F %.2F c',
            $cx + $r, $y, $cx + $r, $y + $k, $cx + $k, $y + $r, $cx, $y + $r, $cx - $k, $y + $r, $cx - $r, $y + $k, $cx - $r, $y,
            $cx - $r, $y - $k, $cx - $k, $y - $r, $cx, $y - $r, $cx + $k, $y - $r, $cx + $r, $y - $k, $cx + $r, $y);
        $this->out(($fill ? self::rgb($hex).' rg '.$d.' f' : self::rgb($hex).sprintf(' RG %.2F w ', $width).$d.' S')."\n");
    }

    /** Stroked arc, angles in degrees counter-clockwise from 3 o'clock (PDF convention), round caps. */
    public function arc(float $cx, float $cy, float $r, float $from, float $to, string $hex, float $width): void
    {
        $steps = max(1, (int) ceil(abs($to - $from) / 45));
        $step = deg2rad(($to - $from) / $steps);
        $a = deg2rad($from);
        $y0 = self::H - $cy;
        $d = sprintf('%.2F %.2F m', $cx + $r * cos($a), $y0 + $r * sin($a));
        for ($i = 0; $i < $steps; $i++) {
            $b = $a + $step;
            $k = 4 / 3 * tan(($b - $a) / 4) * $r;
            $d .= sprintf(' %.2F %.2F %.2F %.2F %.2F %.2F c',
                $cx + $r * cos($a) - $k * sin($a), $y0 + $r * sin($a) + $k * cos($a),
                $cx + $r * cos($b) + $k * sin($b), $y0 + $r * sin($b) - $k * cos($b),
                $cx + $r * cos($b), $y0 + $r * sin($b));
            $a = $b;
        }
        $this->out(self::rgb($hex).sprintf(" RG %.2F w 1 J %s S\n", $width, $d));
    }

    /** Draws what $draw paints with this opacity (0–1). */
    public function withAlpha(float $alpha, \Closure $draw): void
    {
        $key = number_format($alpha, 2);
        $this->alphas[$key] ??= 'GS'.(count($this->alphas) + 1);
        $this->out('q /'.$this->alphas[$key]." gs\n");
        $draw();
        $this->out("Q\n");
    }

    /** $y is the text baseline. $font: regular|bold|italic (Helvetica) or a BRAND_FONTS key. */
    public function text(float $x, float $y, string $text, string $font = 'regular', float $size = 10, string $hex = '132A27', float $spacing = 0): void
    {
        $this->out(sprintf("BT /%s %.2F Tf %s rg %.2F Tc %.2F %.2F Td (%s) Tj ET\n", $this->fontRef($font), $size, self::rgb($hex), $spacing, $x, self::H - $y, self::escape($text)));
    }

    public function textRight(float $right, float $y, string $text, string $font = 'regular', float $size = 10, string $hex = '132A27', float $spacing = 0): void
    {
        $this->text($right - $this->width($text, $font, $size, $spacing), $y, $text, $font, $size, $hex, $spacing);
    }

    public function textCenter(float $center, float $y, string $text, string $font = 'regular', float $size = 10, string $hex = '132A27', float $spacing = 0): void
    {
        $this->text($center - $this->width($text, $font, $size, $spacing) / 2, $y, $text, $font, $size, $hex, $spacing);
    }

    /** Wrapped paragraph from $y (top of the first line). Returns the y below the last line. */
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

    public function width(string $text, string $font = 'regular', float $size = 10, float $spacing = 0): float
    {
        $chars = mb_str_split($text);
        $spacingTotal = $spacing * max(0, count($chars) - 1);
        if (isset(self::BRAND_FONTS[$font])) {
            $widths = self::ttf($font)['widths'];
            $bytes = (string) mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
            $units = 0;
            for ($i = 0, $n = strlen($bytes); $i < $n; $i++) {
                $units += $widths[ord($bytes[$i])] ?? 500;
            }

            return $units * $size / 1000 + $spacingTotal;
        }

        $table = $font === 'bold' ? self::BOLD : self::REGULAR;
        $units = 0;
        foreach ($chars as $char) {
            $base = self::EXTRA[$char] ?? null;
            if ($base === null) {
                $code = ord(Str::ascii($char)[0] ?? ' ');
                $base = $code >= 32 && $code <= 126 ? $table[$code - 32] : 556;
            }
            $units += $base;
        }

        return $units * $size / 1000 + $spacingTotal;
    }

    /** JPEG drawn at ($x, $y) top-left with width $w (height keeps the ratio). Returns the height. */
    public function jpeg(string $path, float $x, float $y, float $w): float
    {
        $name = $this->image($path);
        $h = $w * $this->images[$name]['h'] / $this->images[$name]['w'];
        $this->out(sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q\n", $w, $h, $x, self::H - $y - $h, $name));

        return $h;
    }

    /** JPEG covering the box (cropped, centred on $focusX/$focusY 0–1), like CSS object-fit: cover. */
    public function jpegCover(string $path, float $x, float $y, float $w, float $h, float $focusX = 0.5, float $focusY = 0.5): void
    {
        $name = $this->image($path);
        $scale = max($w / $this->images[$name]['w'], $h / $this->images[$name]['h']);
        [$iw, $ih] = [$this->images[$name]['w'] * $scale, $this->images[$name]['h'] * $scale];
        $ix = $x - ($iw - $w) * $focusX;
        $iy = $y - ($ih - $h) * $focusY;
        $this->out(sprintf("q %.2F %.2F %.2F %.2F re W n %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q\n", $x, self::H - $y - $h, $w, $h, $iw, $ih, $ix, self::H - $iy - $ih, $name));
    }

    public function output(string $title, string $subject = ''): string
    {
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Oblique /Encoding /WinAnsiEncoding >>',
            6 => '<< /Title ('.self::escape($title).') /Subject ('.self::escape($subject).') /Producer (CLARTO Gestion Inmobiliaria) >>',
        ];
        $n = 7;
        $fonts = ['/F1 3 0 R', '/F2 4 0 R', '/F3 5 0 R'];
        foreach (array_keys($this->usedTtf) as $font) {
            $f = self::ttf($font);
            $compressed = (string) gzcompress($f['data']);
            $widths = [];
            for ($c = 32; $c <= 255; $c++) {
                $widths[] = $f['widths'][$c] ?? 0;
            }
            $objects[$n] = '<< /Length '.strlen($compressed).' /Length1 '.strlen($f['data'])." /Filter /FlateDecode >>\nstream\n".$compressed."\nendstream";
            $objects[$n + 1] = '<< /Type /FontDescriptor /FontName /'.$f['ps'].' /Flags '.($f['italic'] ? 96 : 32).' /FontBBox ['.implode(' ', $f['bbox']).'] /ItalicAngle '.($f['italic'] ? -12 : 0)
                .' /Ascent '.$f['ascent'].' /Descent '.$f['descent'].' /CapHeight '.$f['cap'].' /StemV 80 /FontFile2 '.$n.' 0 R >>';
            $objects[$n + 2] = '<< /Type /Font /Subtype /TrueType /BaseFont /'.$f['ps'].' /FirstChar 32 /LastChar 255 /Widths ['.implode(' ', $widths).'] /FontDescriptor '.($n + 1).' 0 R /Encoding /WinAnsiEncoding >>';
            $fonts[] = '/'.$f['ref'].' '.($n + 2).' 0 R';
            $n += 3;
        }
        $xobjects = [];
        foreach ($this->images as $name => $image) {
            $objects[$n] = "<< /Type /XObject /Subtype /Image /Width {$image['w']} /Height {$image['h']} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ".strlen($image['data'])." >>\nstream\n".$image['data']."\nendstream";
            $xobjects[] = "/{$name} {$n} 0 R";
            $n++;
        }
        $states = implode(' ', array_map(fn (string $alpha, string $gs): string => "/{$gs} << /ca {$alpha} /CA {$alpha} >>", array_keys($this->alphas), $this->alphas));
        $resources = '<< /Font << '.implode(' ', $fonts).' >> /XObject << '.implode(' ', $xobjects).' >> /ExtGState << '.$states.' >> >>';
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
        $pdf .= "xref\n0 ".$n."\n0000000000 65535 f \n";
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

    private function fontRef(string $font): string
    {
        if (isset(self::BRAND_FONTS[$font])) {
            $this->usedTtf[$font] = true;

            return self::ttf($font)['ref'];
        }

        return (self::STANDARD[$font] ?? self::STANDARD['regular'])[0];
    }

    /**
     * Metrics of a CLARTO TrueType font, read once: advance widths of the WinAnsi codes 32–255 (through the font's
     * Unicode cmap), bounding box, ascent, descent and cap height, all in 1/1000 em.
     *
     * @return array{widths: array<int, int>, data: string, bbox: list<int>, ascent: int, descent: int, cap: int, italic: bool, ps: string, ref: string}
     */
    private static function ttf(string $font): array
    {
        if (isset(self::$ttf[$font])) {
            return self::$ttf[$font];
        }
        [$file, $ps, $italic] = self::BRAND_FONTS[$font];
        $data = (string) file_get_contents(resource_path('valoraciones/fonts/'.$file));
        $tables = [];
        $count = unpack('n', $data, 4)[1];
        for ($i = 0; $i < $count; $i++) {
            $entry = unpack('a4tag/Nsum/Noffset/Nlength', $data, 12 + 16 * $i);
            $tables[$entry['tag']] = $entry['offset'];
        }
        $u16 = fn (int $o): int => unpack('n', $data, $o)[1];
        $s16 = fn (int $o): int => ($v = $u16($o)) >= 0x8000 ? $v - 0x10000 : $v;
        $unitsPerEm = $u16($tables['head'] + 18);
        $scale = fn (int $v): int => (int) round($v * 1000 / $unitsPerEm);
        $bbox = array_map($scale, [$s16($tables['head'] + 36), $s16($tables['head'] + 38), $s16($tables['head'] + 40), $s16($tables['head'] + 42)]);
        $metrics = $u16($tables['hhea'] + 34);
        $advance = fn (int $glyph): int => $u16($tables['hmtx'] + 4 * min($glyph, $metrics - 1));

        // cmap: Unicode BMP subtable, format 4.
        $cmap = $tables['cmap'];
        $sub = null;
        for ($i = 0, $n = $u16($cmap + 2); $i < $n; $i++) {
            [$platform, $encoding] = [$u16($cmap + 4 + 8 * $i), $u16($cmap + 6 + 8 * $i)];
            $offset = unpack('N', $data, $cmap + 8 + 8 * $i)[1];
            if (($platform === 3 && $encoding === 1) || $platform === 0) {
                if ($u16($cmap + $offset) === 4) {
                    $sub = $cmap + $offset;
                }
            }
        }
        $glyphFor = function (int $code) use ($sub, $u16): int {
            if ($sub === null) {
                return 0;
            }
            $segments = $u16($sub + 6) / 2;
            $ends = $sub + 14;
            $starts = $ends + 2 * $segments + 2;
            $deltas = $starts + 2 * $segments;
            $ranges = $deltas + 2 * $segments;
            for ($s = 0; $s < $segments; $s++) {
                if ($code <= $u16($ends + 2 * $s)) {
                    $start = $u16($starts + 2 * $s);
                    if ($code < $start) {
                        return 0;
                    }
                    $delta = $u16($deltas + 2 * $s);
                    $range = $u16($ranges + 2 * $s);
                    if ($range === 0) {
                        return ($code + $delta) & 0xFFFF;
                    }
                    $glyph = $u16($ranges + 2 * $s + $range + 2 * ($code - $start));

                    return $glyph === 0 ? 0 : ($glyph + $delta) & 0xFFFF;
                }
            }

            return 0;
        };
        $widths = [];
        for ($c = 32; $c <= 255; $c++) {
            $char = (string) mb_convert_encoding(chr($c), 'UTF-8', 'Windows-1252');
            $widths[$c] = $scale($advance($glyphFor(mb_ord($char) ?: 32)));
        }
        $os2 = $tables['OS/2'] ?? null;
        $cap = $os2 !== null && $u16($os2) >= 2 ? $scale($s16($os2 + 88)) : (int) round($bbox[3] * 0.7);

        return self::$ttf[$font] = [
            'widths' => $widths, 'data' => $data, 'bbox' => $bbox, 'italic' => $italic, 'ps' => $ps,
            'ascent' => $scale($s16($tables['hhea'] + 4)), 'descent' => $scale($s16($tables['hhea'] + 6)), 'cap' => $cap,
            'ref' => 'T'.(array_search($font, array_keys(self::BRAND_FONTS), true) + 1),
        ];
    }

    private function image(string $path): string
    {
        $name = 'Im'.substr(sha1($path), 0, 6);
        if (! isset($this->images[$name])) {
            $size = getimagesize($path);
            $this->images[$name] = ['data' => (string) file_get_contents($path), 'w' => (int) ($size[0] ?? 1), 'h' => (int) ($size[1] ?? 1)];
        }

        return $name;
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
        $text = (string) mb_convert_encoding($text, 'Windows-1252', 'UTF-8');

        return strtr($text, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '', "\n" => ' ']);
    }
}
