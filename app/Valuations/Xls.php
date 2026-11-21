<?php

declare(strict_types=1);

namespace App\Valuations;

use RuntimeException;

/**
 * Minimal reader of legacy Excel 97-2003 files (BIFF8 inside an OLE2 compound file): cell values only — numbers,
 * text (shared strings, labels) and formula results; no formats, dates or merged cells. The MIVAU publishes its price
 * tables only as .xls and the image has no spreadsheet library that reads them (openspout: xlsx/ods/csv only).
 *
 * ponytail: no mini-stream (workbooks < 4 KB) and no encrypted files; phpoffice/phpspreadsheet if that ever matters.
 */
final class Xls
{
    private const END_OF_CHAIN = 0xFFFFFFFA;

    /**
     * @param  list<string>|null  $only  sheet names (trimmed) to keep; null = all
     * @return array<string, array<int, array<int, string|float|int|bool>>> sheet name (trimmed) => row => column => value
     */
    public static function read(string $bytes, ?array $only = null): array
    {
        if (! str_starts_with($bytes, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1")) {
            throw new RuntimeException('No es un fichero Excel 97-2003 (.xls).');
        }

        return self::workbook(self::stream($bytes), $only);
    }

    /** The «Workbook» stream of the compound file. */
    private static function stream(string $bytes): string
    {
        $shift = self::u16($bytes, 0x1E);
        $size = 1 << $shift;
        $sector = fn (int $id): string => substr($bytes, ($id + 1) << $shift, $size);

        // FAT sectors: 109 in the header, the rest in the DIFAT chain.
        $difat = array_values((array) unpack('V109', substr($bytes, 0x4C, 436)));
        for ($id = self::u32($bytes, 0x44), $n = self::u32($bytes, 0x48); $n > 0 && $id < self::END_OF_CHAIN; $n--) {
            $ids = array_values((array) unpack('V*', $sector($id)));
            $id = (int) array_pop($ids);
            array_push($difat, ...$ids);
        }
        $fat = [];
        foreach (array_slice($difat, 0, self::u32($bytes, 0x2C)) as $id) {
            array_push($fat, ...array_values((array) unpack('V*', $sector((int) $id))));
        }
        $chain = function (int $id) use ($fat, $sector): string {
            $out = '';
            for ($guard = 0; $id < self::END_OF_CHAIN && $guard < count($fat); $guard++) {
                $out .= $sector($id);
                $id = (int) $fat[$id];
            }

            return $out;
        };

        $directory = $chain(self::u32($bytes, 0x30));
        for ($p = 0; $p + 128 <= strlen($directory); $p += 128) {
            $name = (string) mb_convert_encoding(substr($directory, $p, max(0, self::u16($directory, $p + 64) - 2)), 'UTF-8', 'UTF-16LE');
            if (in_array($name, ['Workbook', 'Book'], true)) {
                $length = self::u32($directory, $p + 120);
                if ($length < 4096) {
                    throw new RuntimeException('Libro .xls demasiado pequeño (mini-stream no soportado).');
                }

                return substr($chain(self::u32($directory, $p + 116)), 0, $length);
            }
        }

        throw new RuntimeException('El .xls no contiene el flujo «Workbook».');
    }

    /** @return array<string, array<int, array<int, string|float|int|bool>>> */
    private static function workbook(string $wb, ?array $only): array
    {
        $offsets = [];     // BOF position => sheet name
        $strings = [];
        $sheets = [];
        $current = null;
        $pendingFormula = null;
        $length = strlen($wb);

        for ($pos = 0; $pos + 4 <= $length;) {
            $type = self::u16($wb, $pos);
            $size = self::u16($wb, $pos + 2);
            $data = substr($wb, $pos + 4, $size);
            $start = $pos;
            $pos += 4 + $size;

            switch ($type) {
                case 0x0085:   // BOUNDSHEET
                    $offsets[self::u32($data, 0)] = trim(self::string($data, 6, true));
                    break;
                case 0x00FC:   // SST, continued in CONTINUE records
                    $bounds = [];
                    while ($pos + 4 <= $length && self::u16($wb, $pos) === 0x003C) {
                        $bounds[] = strlen($data);
                        $continue = self::u16($wb, $pos + 2);
                        $data .= substr($wb, $pos + 4, $continue);
                        $pos += 4 + $continue;
                    }
                    $strings = self::sst($data, $bounds);
                    break;
                case 0x0809:   // BOF
                    $current = $offsets[$start] ?? null;
                    if ($current !== null && $only !== null && ! in_array($current, $only, true)) {
                        $current = null;
                    }
                    break;
                case 0x000A:   // EOF
                    $current = null;
                    break;
            }
            if ($current === null) {
                continue;
            }

            $row = $size >= 4 ? self::u16($data, 0) : 0;
            $col = $size >= 4 ? self::u16($data, 2) : 0;
            match ($type) {
                0x00FD => $sheets[$current][$row][$col] = $strings[self::u32($data, 6)] ?? '',                 // LABELSST
                0x0204 => $sheets[$current][$row][$col] = self::string($data, 6),                               // LABEL
                0x0203 => $sheets[$current][$row][$col] = self::double(substr($data, 6, 8)),                    // NUMBER
                0x027E => $sheets[$current][$row][$col] = self::rk(self::u32($data, 6)),                        // RK
                0x00BD => self::mulrk($sheets[$current], $data),                                                // MULRK
                0x0006 => $pendingFormula = self::formula($sheets[$current], $data),                            // FORMULA
                0x0207 => $pendingFormula === null ? null : ($sheets[$current][$pendingFormula[0]][$pendingFormula[1]] = self::string($data, 0)),   // STRING
                default => null,
            };
            if ($type !== 0x0006 && $type !== 0x0207) {
                $pendingFormula = null;
            }
        }

        return $sheets;
    }

    /**
     * Shared strings; a string's characters may continue in the next CONTINUE record, which then starts with a new
     * option byte (compressed or UTF-16), while rich-text runs and phonetic data just carry on.
     *
     * @param  list<int>  $bounds  offsets in $data where each CONTINUE record began
     * @return list<string>
     */
    private static function sst(string $data, array $bounds): array
    {
        $out = [];
        $unique = self::u32($data, 4);
        $boundSet = array_flip($bounds);
        $p = 8;
        for ($i = 0; $i < $unique && $p < strlen($data); $i++) {
            $left = self::u16($data, $p);
            $flags = ord($data[$p + 2]);
            $p += 3;
            $runs = $flags & 0x08 ? self::u16($data, $p) : 0;
            $p += $flags & 0x08 ? 2 : 0;
            $ext = $flags & 0x04 ? self::u32($data, $p) : 0;
            $p += $flags & 0x04 ? 4 : 0;
            $wide = (bool) ($flags & 0x01);
            if ($left > 0 && isset($boundSet[$p])) {   // characters start right at a CONTINUE
                $wide = (bool) (ord($data[$p]) & 0x01);
                $p++;
            }
            $text = '';
            while ($left > 0) {
                $next = strlen($data);
                foreach ($bounds as $b) {
                    if ($b > $p) {
                        $next = $b;
                        break;
                    }
                }
                $take = min($left, intdiv($next - $p, $wide ? 2 : 1));
                $text .= self::chars(substr($data, $p, $take * ($wide ? 2 : 1)), $wide);
                $p += $take * ($wide ? 2 : 1);
                $left -= $take;
                if ($left > 0) {
                    $wide = (bool) (ord($data[$p]) & 0x01);
                    $p++;
                }
            }
            $p += $runs * 4 + $ext;
            $out[] = $text;
        }

        return $out;
    }

    /** XLUnicodeString (16-bit length) or ShortXLUnicodeString (8-bit) at $offset. */
    private static function string(string $data, int $offset, bool $short = false): string
    {
        $count = $short ? ord($data[$offset] ?? "\0") : self::u16($data, $offset);
        $p = $offset + ($short ? 1 : 2);
        $flags = ord($data[$p] ?? "\0");
        $p += 1 + ($flags & 0x08 ? 2 : 0) + ($flags & 0x04 ? 4 : 0);
        $wide = (bool) ($flags & 0x01);

        return self::chars(substr($data, $p, $count * ($wide ? 2 : 1)), $wide);
    }

    private static function chars(string $raw, bool $wide): string
    {
        return (string) mb_convert_encoding($raw, 'UTF-8', $wide ? 'UTF-16LE' : 'ISO-8859-1');
    }

    /** @param array<int, array<int, mixed>>|null $sheet */
    private static function mulrk(?array &$sheet, string $data): void
    {
        $row = self::u16($data, 0);
        $col = self::u16($data, 2);
        for ($p = 4; $p + 6 <= strlen($data) - 2; $p += 6, $col++) {
            $sheet[$row][$col] = self::rk(self::u32($data, $p + 2));
        }
    }

    /**
     * A number result is stored in place; a text result follows in a STRING record.
     *
     * @param  array<int, array<int, mixed>>|null  $sheet
     * @return array{0: int, 1: int}|null the cell waiting for its STRING
     */
    private static function formula(?array &$sheet, string $data): ?array
    {
        $row = self::u16($data, 0);
        $col = self::u16($data, 2);
        $result = substr($data, 6, 8);
        if (substr($result, 6, 2) !== "\xFF\xFF") {
            $sheet[$row][$col] = self::double($result);

            return null;
        }
        match (ord($result[0])) {
            1 => $sheet[$row][$col] = (bool) ord($result[2]),
            default => null,   // 0 text (STRING follows), 2 error, 3 empty
        };

        return ord($result[0]) === 0 ? [$row, $col] : null;
    }

    private static function rk(int $rk): float|int
    {
        if ($rk & 0x02) {
            $signed = $rk >= 0x80000000 ? $rk - 0x100000000 : $rk;
            $value = $signed >> 2;
        } else {
            $value = self::double(pack('VV', 0, $rk & 0xFFFFFFFC));
        }

        return $rk & 0x01 ? $value / 100 : $value;
    }

    private static function double(string $eight): float
    {
        return (float) unpack('e', $eight)[1];
    }

    private static function u16(string $s, int $p): int
    {
        return (int) unpack('v', substr($s, $p, 2) ?: "\0\0")[1];
    }

    private static function u32(string $s, int $p): int
    {
        return (int) unpack('V', substr($s, $p, 4) ?: "\0\0\0\0")[1];
    }
}
