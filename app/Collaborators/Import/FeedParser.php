<?php

declare(strict_types=1);

namespace App\Collaborators\Import;

use Illuminate\Support\Arr;
use RuntimeException;
use SimpleXMLElement;

/**
 * Collaborator feeds → raw listings with the importer's field names:
 * referencia, url, precio, operacion, tipo, m2, habitaciones, banos, municipio, zona, titulo, descripcion, fotos[].
 *
 * - kyero: Kyero XML v3 (<root><property>…), the usual Spanish portal export.
 * - xml / json: any feed, with «elementos» (dot path to the list of listings, from the XML root element's children)
 *   and one dot path per field inside each listing (CollaboratorImport::MAPPABLE).
 *
 * ponytail: idealista/OpenNavigator XML not shipped; its export maps fine with the generic «xml» format.
 */
final class FeedParser
{
    /**
     * @param  array<string, string>  $mapping
     * @return list<array<string, mixed>>
     */
    public static function parse(string $body, string $format, array $mapping = []): array
    {
        if ($format === 'kyero') {
            return self::kyero(self::xml($body));
        }
        $tree = $format === 'json' ? json_decode($body, true, 512, JSON_THROW_ON_ERROR) : json_decode((string) json_encode(self::xml($body)), true);
        $items = blank($mapping['elementos'] ?? null) ? $tree : data_get($tree, $mapping['elementos']);
        if (! is_array($items)) {
            throw new RuntimeException(__('importacion.errors.no_items'));
        }
        $items = Arr::isAssoc($items) ? [$items] : $items;   // a single <listing> is not wrapped in a list

        $out = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $row = [];
            foreach (CollaboratorImport::MAPPABLE as $field) {
                if ($field !== 'elementos' && filled($mapping[$field] ?? null)) {
                    $row[$field] = data_get($item, $mapping[$field]);
                }
            }
            $row['fotos'] = self::photoList($row['fotos'] ?? null);
            $out[] = array_map(fn ($v) => is_array($v) && $v === [] ? null : $v, $row);   // empty XML element → null
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private static function kyero(SimpleXMLElement $root): array
    {
        $out = [];
        foreach ($root->property as $p) {
            $desc = $p->desc;
            $text = fn (?SimpleXMLElement $node): ?string => $node === null || trim((string) $node) === '' ? null : trim((string) $node);
            $out[] = [
                'referencia' => $text($p->ref) ?? $text($p->id),
                'url' => $text($p->url->es ?? null) ?? $text($p->url->en ?? null) ?? $text($p->url),
                'precio' => $text($p->price),
                'operacion' => match (strtolower((string) $p->price_freq)) {
                    'month' => 'alquiler', 'week', 'night' => 'alquiler-temporal', default => 'venta',
                },
                'tipo' => $text($p->type),
                'm2' => $text($p->surface_area->built ?? null),
                'habitaciones' => $text($p->beds),
                'banos' => $text($p->baths),
                'municipio' => $text($p->town),
                'zona' => $text($p->location_detail),
                'titulo' => null,
                'descripcion' => $text($desc->es ?? null) ?? $text($desc->en ?? null) ?? (isset($desc) ? $text($desc->children()[0] ?? null) : null),
                'fotos' => array_values(array_filter(array_map(fn ($i) => trim((string) $i->url), $p->images->image ? iterator_to_array($p->images->image, false) : []))),
            ];
        }

        return $out;
    }

    /** @return list<string> */
    private static function photoList(mixed $value): array
    {
        if (is_string($value)) {
            return array_values(array_filter(preg_split('/[\s,;|]+/', trim($value)) ?: []));
        }
        if (! is_array($value)) {
            return [];
        }
        $urls = [];
        array_walk_recursive($value, function ($leaf) use (&$urls): void {
            if (is_string($leaf) && preg_match('~^https?://~i', trim($leaf))) {
                $urls[] = trim($leaf);
            }
        });

        return array_values(array_unique($urls));
    }

    private static function xml(string $body): SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if ($xml === false) {
            throw new RuntimeException(__('importacion.errors.bad_feed'));
        }

        return $xml;
    }
}
