<?php

declare(strict_types=1);

namespace App\Collaborators\Import;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Cheap «possible watermark» check on a listing's photos (it only flags for review, never hides anything):
 * 1. the collaborator's name/brand inside the image metadata (EXIF/XMP/IPTC/comments are plain text in the file);
 * 2. the same edges in the same place of most photos — what a logo or watermark stamped on every picture looks like.
 *    Each photo is reduced to 192×192, cut into a 6×6 grid, and the edge pixels of each cell are compared between
 *    photos (overlap / union). Real photos of a home do not repeat edges pixel for pixel; a stamp does.
 *
 * ponytail: heuristic, not vision AI; misses a watermark on a single photo, can flag a fixed frame or border.
 * Upgrade path: a vision model through ListingAi if staff report misses.
 */
final class WatermarkCheck
{
    private const SIZE = 192;

    private const GRID = 6;

    /**
     * @param  list<string>  $paths  public-disk paths of the listing's photos
     * @param  list<string>  $brands  lowercase brand words (PublicTextCleaner::brands)
     * @return ?string reason, or null when nothing suspicious was found
     */
    public static function suspect(array $paths, array $brands): ?string
    {
        $disk = Storage::disk('public');
        $maps = [];
        foreach (array_slice($paths, 0, 12) as $path) {
            $bytes = $disk->exists($path) ? (string) $disk->get($path) : '';
            if ($bytes === '') {
                continue;
            }
            $head = Str::lower(Str::ascii(substr($bytes, 0, 65536)));   // metadata lives at the start of the file
            foreach ($brands as $brand) {
                if (mb_strlen($brand) >= 4 && str_contains($head, $brand)) {
                    return __('importacion.review.photos_metadata', ['brand' => $brand]);
                }
            }
            if (($map = self::edges($bytes)) !== null) {
                $maps[] = $map;
            }
        }

        return count($maps) >= 3 && self::repeatedCell($maps) ? __('importacion.review.photos_repeated') : null;
    }

    /**
     * Where the repeated mark is, for inpainting: the edge pixels present in ≥ 60 % of the photos, grouped per grid
     * cell into padded boxes and merged when they touch. Proportions (0–1) of the photo, so they fit any photo size.
     *
     * @param  list<string>  $paths  public-disk paths of the listing's photos
     * @return ?list<array{0: float, 1: float, 2: float, 3: float}> [x0, y0, x1, y1] boxes, or null if nothing repeats
     */
    public static function mask(array $paths): ?array
    {
        $disk = Storage::disk('public');
        $maps = array_values(array_filter(array_map(fn (string $p): ?array => $disk->exists($p) ? self::edges((string) $disk->get($p)) : null, array_slice($paths, 0, 12))));
        if (count($maps) < 3 || ! self::repeatedCell($maps)) {
            return null;
        }
        $need = (int) ceil(count($maps) * 0.6);
        $boxes = [];
        foreach (array_keys(array_fill(0, self::GRID * self::GRID, true)) as $cell) {
            $counts = [];
            foreach ($maps as $map) {
                foreach (array_keys($map[$cell] ?? []) as $pixel) {
                    $counts[$pixel] = ($counts[$pixel] ?? 0) + 1;
                }
            }
            $pixels = array_keys(array_filter($counts, fn (int $c): bool => $c >= $need));
            if (count($pixels) < 6) {
                continue;
            }
            $xs = array_map(fn (int $p): int => $p % self::SIZE, $pixels);
            $ys = array_map(fn (int $p): int => intdiv($p, self::SIZE), $pixels);
            $pad = 4;
            $boxes[] = [max(0, min($xs) - $pad) / self::SIZE, max(0, min($ys) - $pad) / self::SIZE,
                min(self::SIZE, max($xs) + 1 + $pad) / self::SIZE, min(self::SIZE, max($ys) + 1 + $pad) / self::SIZE];
        }
        // Merge boxes that touch (a logo spanning two cells becomes one box).
        do {
            $merged = false;
            foreach ($boxes as $i => $a) {
                foreach ($boxes as $j => $b) {
                    if ($i < $j && $a[0] <= $b[2] && $b[0] <= $a[2] && $a[1] <= $b[3] && $b[1] <= $a[3]) {
                        $boxes[$i] = [min($a[0], $b[0]), min($a[1], $b[1]), max($a[2], $b[2]), max($a[3], $b[3])];
                        unset($boxes[$j]);
                        $merged = true;
                        break 2;
                    }
                }
            }
        } while ($merged);

        return $boxes === [] ? null : array_values($boxes);
    }

    /** @param list<array<int, array<int, true>>> $maps */
    public static function repeatedCell(array $maps): bool
    {
        $cellArea = (self::SIZE / self::GRID) ** 2;
        foreach (array_keys(array_fill(0, self::GRID * self::GRID, true)) as $cell) {
            $sets = array_map(fn (array $m): array => $m[$cell] ?? [], $maps);
            $density = array_sum(array_map('count', $sets)) / count($sets) / $cellArea;
            if ($density < 0.03 || $density > 0.6) {
                continue;   // empty (sky, wall) or noise
            }
            $sum = 0.0;
            $pairs = 0;
            for ($i = 0; $i < count($sets); $i++) {
                for ($j = $i + 1; $j < count($sets); $j++) {
                    $union = count($sets[$i] + $sets[$j]);
                    $sum += $union > 0 ? count(array_intersect_key($sets[$i], $sets[$j])) / $union : 0;
                    $pairs++;
                }
            }
            if ($pairs > 0 && $sum / $pairs >= 0.45) {
                return true;
            }
        }

        return false;
    }

    /** @return ?array<int, array<int, true>> cell => set of edge pixel indices */
    private static function edges(string $bytes): ?array
    {
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            return null;
        }
        $n = self::SIZE;
        $small = imagecreatetruecolor($n, $n);
        imagecopyresampled($small, $image, 0, 0, 0, 0, $n, $n, imagesx($image), imagesy($image));
        imagedestroy($image);
        $gray = [];
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                $rgb = imagecolorat($small, $x, $y);
                $gray[$y][$x] = (0.299 * (($rgb >> 16) & 255) + 0.587 * (($rgb >> 8) & 255) + 0.114 * ($rgb & 255)) / 255;
            }
        }
        imagedestroy($small);
        $cell = intdiv($n, self::GRID);
        $cells = [];
        for ($y = 0; $y < $n - 1; $y++) {
            for ($x = 0; $x < $n - 1; $x++) {
                if (abs($gray[$y][$x + 1] - $gray[$y][$x]) + abs($gray[$y + 1][$x] - $gray[$y][$x]) > 0.08) {
                    $cells[intdiv($y, $cell) * self::GRID + intdiv($x, $cell)][$y * $n + $x] = true;
                }
            }
        }

        return $cells;
    }
}
