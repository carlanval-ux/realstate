<?php

declare(strict_types=1);

namespace App\Processes;

/**
 * Draws the subset of Mermaid flowcharts the procedures use, as inline SVG on the server (no JavaScript library):
 * `flowchart TD|LR` / `graph TD|LR`, nodes `A[texto]`, `A(texto)`, `A{decisión}`, `A((texto))`, edges `-->`, `---`,
 * `-.->`, `==>`, labels `-- texto -->` and `-->|texto|`, chains `A --> B --> C`. Anything else → null, and the page
 * shows the code instead. Text is escaped: there is no HTML, link or script in the output.
 *
 * ponytail: layered layout (longest path from the roots, order of appearance) with straight edges; enough for
 * process walkthroughs of a few dozen nodes. Swap for mermaid.js (strict) if diagrams get dense.
 */
final class MermaidSvg
{
    private const W = 190;

    private const GAP_X = 40;

    private const GAP_Y = 56;

    private const LINE = 16;

    private const CHARS = 24;

    public static function render(?string $code): ?string
    {
        $lines = preg_split('/\R/u', trim((string) $code)) ?: [];
        $head = array_shift($lines);
        if (! preg_match('/^\s*(flowchart|graph)\s+(TD|TB|LR|RL|BT)\s*;?\s*$/i', (string) $head, $m)) {
            return null;
        }
        $horizontal = in_array(strtoupper($m[2]), ['LR', 'RL'], true);
        $nodes = [];   // id => [label, shape]
        $edges = [];   // [from, to, label]
        $node = '([A-Za-z0-9_]+)\s*(\(\(.*?\)\)|\[.*?\]|\(.*?\)|\{.*?\})?';
        $arrow = '\s*(?:--\s*([^-|>][^>]*?)\s*-->|-->\s*\|([^|]*)\|\s*|-->|---|-\.->|==>)\s*';
        foreach ($lines as $line) {
            $line = trim((string) preg_replace('/%%.*$/', '', $line), " \t;");
            if ($line === '' || preg_match('/^(classDef|class|style|linkStyle|subgraph|end|click)\b/i', $line)) {
                if (preg_match('/^click\b/i', $line)) {
                    return null;   // links are not supported (and not wanted)
                }
                continue;
            }
            if (! preg_match('/^'.$node.'((?:'.$arrow.$node.')*)$/u', $line, $first)) {
                return null;
            }
            $prev = self::addNode($nodes, $first[1], $first[2] ?? '');
            $rest = $first[3] ?? '';
            while ($rest !== '' && preg_match('/^'.$arrow.$node.'/u', $rest, $step)) {
                $to = self::addNode($nodes, $step[3], $step[4] ?? '');
                $edges[] = [$prev, $to, trim(($step[1] ?? '') !== '' ? $step[1] : ($step[2] ?? ''))];
                $prev = $to;
                $rest = substr($rest, strlen($step[0]));
            }
            if (trim($rest) !== '') {
                return null;
            }
        }
        if ($nodes === [] || count($nodes) > 80) {
            return null;
        }

        // Layers: longest path from the roots, ignoring edges that close a cycle.
        $rank = array_fill_keys(array_keys($nodes), 0);
        for ($i = 0; $i < count($nodes); $i++) {
            $changed = false;
            foreach ($edges as [$a, $b]) {
                if ($rank[$b] < $rank[$a] + 1 && $rank[$a] + 1 < count($nodes)) {
                    $rank[$b] = $rank[$a] + 1;
                    $changed = true;
                }
            }
            if (! $changed) {
                break;
            }
        }
        $layers = [];
        foreach ($rank as $id => $r) {
            $layers[$r][] = $id;
        }
        ksort($layers);

        $wrapped = array_map(fn (array $n): array => self::wrap($n[0]), $nodes);
        $height = fn (string $id): int => max(1, count($wrapped[$id])) * self::LINE + 20 + ($nodes[$id][1] === 'diamond' ? 18 : 0);
        $pos = [];
        $maxCount = max(array_map('count', $layers));
        $offset = 10;
        foreach ($layers as $ids) {
            $span = max(array_map($height, $ids));
            foreach ($ids as $i => $id) {
                $slot = ($maxCount - count($ids)) / 2 + $i;
                $pos[$id] = $horizontal
                    ? ['x' => $offset, 'y' => 10 + $slot * (90 + self::GAP_X), 'h' => $height($id)]
                    : ['x' => 10 + $slot * (self::W + self::GAP_X), 'y' => $offset, 'h' => $height($id)];
            }
            $offset += $horizontal ? self::W + self::GAP_Y : $span + self::GAP_Y;
        }
        $width = $horizontal ? $offset : 20 + $maxCount * (self::W + self::GAP_X);
        $total = $horizontal ? 20 + $maxCount * (90 + self::GAP_X) : $offset;

        $svg = [];
        foreach ($edges as [$a, $b, $label]) {
            [$x1, $y1] = $horizontal ? [$pos[$a]['x'] + self::W, $pos[$a]['y'] + $pos[$a]['h'] / 2] : [$pos[$a]['x'] + self::W / 2, $pos[$a]['y'] + $pos[$a]['h']];
            [$x2, $y2] = $horizontal ? [$pos[$b]['x'], $pos[$b]['y'] + $pos[$b]['h'] / 2] : [$pos[$b]['x'] + self::W / 2, $pos[$b]['y']];
            $back = $rank[$b] <= $rank[$a];
            $path = $back
                ? sprintf('M%d %d C%d %d %d %d %d %d', $x1, $y1, $x1 + 120, $y1 + 40, $x2 + 120, $y2 - 40, $x2, $y2)
                : sprintf('M%d %d L%d %d', $x1, $y1, $x2, $y2 - ($horizontal ? 0 : 2));
            $svg[] = '<path d="'.$path.'" class="cl-mm-edge" marker-end="url(#cl-mm-arrow)"/>';
            if ($label !== '') {
                $svg[] = sprintf('<text x="%d" y="%d" class="cl-mm-elabel" text-anchor="middle">%s</text>', ($x1 + $x2) / 2 + ($back ? 60 : 0), ($y1 + $y2) / 2 + 4, e($label));
            }
        }
        foreach ($nodes as $id => [, $shape]) {
            ['x' => $x, 'y' => $y, 'h' => $h] = $pos[$id];
            $svg[] = match ($shape) {
                'diamond' => sprintf('<polygon points="%d,%d %d,%d %d,%d %d,%d" class="cl-mm-node cl-mm-decision"/>', $x + self::W / 2, $y, $x + self::W, $y + $h / 2, $x + self::W / 2, $y + $h, $x, $y + $h / 2),
                'round' => sprintf('<rect x="%d" y="%d" width="%d" height="%d" rx="18" class="cl-mm-node"/>', $x, $y, self::W, $h),
                'circle' => sprintf('<ellipse cx="%d" cy="%d" rx="%d" ry="%d" class="cl-mm-node"/>', $x + self::W / 2, $y + $h / 2, self::W / 2, $h / 2),
                default => sprintf('<rect x="%d" y="%d" width="%d" height="%d" rx="6" class="cl-mm-node"/>', $x, $y, self::W, $h),
            };
            $lines = $wrapped[$id];
            $start = $y + $h / 2 - (count($lines) - 1) * self::LINE / 2 + 5;
            foreach ($lines as $i => $text) {
                $svg[] = sprintf('<text x="%d" y="%d" text-anchor="middle" class="cl-mm-label">%s</text>', $x + self::W / 2, $start + $i * self::LINE, e($text));
            }
        }

        return sprintf('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" width="%d" role="img" class="cl-mm">', $width, $total, $width)
            .'<defs><marker id="cl-mm-arrow" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0 L10 5 L0 10 z" class="cl-mm-arrowhead"/></marker></defs>'
            .implode('', $svg).'</svg>';
    }

    /** @param array<string, array{0: string, 1: string}> $nodes */
    private static function addNode(array &$nodes, string $id, string $shape): string
    {
        if ($shape !== '' || ! isset($nodes[$id])) {
            [$label, $kind] = match (true) {
                str_starts_with($shape, '((') => [substr($shape, 2, -2), 'circle'],
                str_starts_with($shape, '[') => [substr($shape, 1, -1), 'box'],
                str_starts_with($shape, '(') => [substr($shape, 1, -1), 'round'],
                str_starts_with($shape, '{') => [substr($shape, 1, -1), 'diamond'],
                default => [$nodes[$id][0] ?? $id, $nodes[$id][1] ?? 'box'],
            };
            $nodes[$id] = [trim($label, " \"'"), $kind];
        }

        return $id;
    }

    /** @return list<string> */
    private static function wrap(string $text): array
    {
        $out = [];
        $line = '';
        foreach (preg_split('/\s+/u', $text) ?: [] as $word) {
            if ($line !== '' && mb_strlen($line.' '.$word) > self::CHARS) {
                $out[] = $line;
                $line = $word;
            } else {
                $line = trim($line.' '.$word);
            }
        }

        return array_slice([...$out, $line], 0, 5);
    }
}
