<?php

declare(strict_types=1);

namespace App\Processes;

use App\Processes\Models\ProcessVersion;

/**
 * Field-by-field comparison of two versions (diseno.md §6.2): texts by lines, steps and decisions by their stable key
 * (added, removed, changed, moved), attachments by their sha256. Null $from = first version.
 */
final class ProcessDiff
{
    private const TEXTS = ['title', 'summary', 'objective', 'expected_result', 'trigger', 'requirements', 'required_data', 'escalation',
        'evidences', 'deadlines', 'diagram_mermaid', 'change_summary'];

    private const LISTS = ['exceptions', 'tools', 'kpis', 'related', 'blocks', 'keywords'];

    /** @return list<array{field: string, change: string, label: string, removed: list<string>, added: list<string>}> */
    public static function between(?ProcessVersion $from, ProcessVersion $to): array
    {
        if ($from === null) {
            return [];
        }
        $changes = [];
        foreach (self::TEXTS as $field) {
            [$old, $new] = [self::lines($from->{$field}), self::lines($to->{$field})];
            if ($old !== $new) {
                $changes[] = self::change($field, 'cambiado', __("procesos.campos.{$field}"), array_values(array_diff($old, $new)), array_values(array_diff($new, $old)));
            }
        }
        if ((bool) $from->no_decisions !== (bool) $to->no_decisions) {
            $changes[] = self::change('no_decisions', 'cambiado', __('procesos.campos.no_decisions'), [$from->no_decisions ? '✔' : '—'], [$to->no_decisions ? '✔' : '—']);
        }
        foreach (['steps' => fn (array $s, int $i) => __('procesos.ver.paso_n', ['n' => $i + 1]).'. '.($s['title'] ?? ''), 'decisions' => fn (array $d) => (string) ($d['question'] ?? '')] as $field => $title) {
            $old = self::byKey((array) $from->{$field});
            $new = self::byKey((array) $to->{$field});
            foreach ($new as $key => [$i, $item]) {
                if (! isset($old[$key])) {
                    $changes[] = self::change($field, 'anadido', $title($item, $i), [], [self::summary($item)]);
                } elseif (self::json($old[$key][1]) !== self::json($item)) {
                    $changes[] = self::change($field, 'cambiado', $title($item, $i), array_values(array_diff(self::flat($old[$key][1]), self::flat($item))), array_values(array_diff(self::flat($item), self::flat($old[$key][1]))));
                } elseif ($old[$key][0] !== $i) {
                    $changes[] = self::change($field, 'movido', $title($item, $i), [(string) ($old[$key][0] + 1)], [(string) ($i + 1)]);
                }
            }
            foreach ($old as $key => [$i, $item]) {
                if (! isset($new[$key])) {
                    $changes[] = self::change($field, 'quitado', $title($item, $i), [self::summary($item)], []);
                }
            }
        }
        foreach (self::LISTS as $field) {
            [$old, $new] = [array_map(self::json(...), (array) $from->{$field}), array_map(self::json(...), (array) $to->{$field})];
            if ($old !== $new) {
                $changes[] = self::change($field, 'cambiado', __("procesos.campos.{$field}"),
                    array_values(array_map(self::readable(...), array_diff($old, $new))), array_values(array_map(self::readable(...), array_diff($new, $old))));
            }
        }
        $oldFiles = $from->attachments()->pluck('original_name', 'sha256')->all();
        $newFiles = $to->attachments()->pluck('original_name', 'sha256')->all();
        foreach (array_diff_key($newFiles, $oldFiles) as $name) {
            $changes[] = self::change('attachments', 'anadido', __('procesos.campos.attachment'), [], [(string) $name]);
        }
        foreach (array_diff_key($oldFiles, $newFiles) as $name) {
            $changes[] = self::change('attachments', 'quitado', __('procesos.campos.attachment'), [(string) $name], []);
        }

        return $changes;
    }

    /** @return array{field: string, change: string, label: string, removed: list<string>, added: list<string>} */
    private static function change(string $field, string $change, string $label, array $removed, array $added): array
    {
        return ['field' => $field, 'change' => $change, 'label' => $label, 'removed' => $removed, 'added' => $added];
    }

    /** @return list<string> */
    private static function lines(mixed $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) $text) ?: []), fn (string $l): bool => $l !== ''));
    }

    /** @return array<string, array{0: int, 1: array}> */
    private static function byKey(array $items): array
    {
        $out = [];
        foreach (array_values($items) as $i => $item) {
            $out[(string) ($item['key'] ?? 'i'.$i)] = [$i, $item];
        }

        return $out;
    }

    /** @return list<string> «campo: valor» lines of an item, for a readable diff */
    private static function flat(array $item, string $prefix = ''): array
    {
        $out = [];
        foreach ($item as $k => $v) {
            if ($k === 'key') {
                continue;
            }
            $label = $prefix.(is_int($k) ? '#'.($k + 1) : $k);
            if (is_array($v)) {
                array_push($out, ...self::flat($v, $label.'.'));
            } elseif ($v !== null && $v !== '') {
                $out[] = $label.': '.(is_bool($v) ? ($v ? 'sí' : 'no') : $v);
            }
        }

        return $out;
    }

    private static function summary(array $item): string
    {
        return implode(' · ', array_slice(self::flat($item), 0, 4));
    }

    private static function json(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    private static function readable(string $json): string
    {
        $value = json_decode($json, true);

        return is_array($value) ? implode(' · ', self::flat($value)) : (string) $value;
    }
}
