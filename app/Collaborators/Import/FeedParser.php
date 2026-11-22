<?php

declare(strict_types=1);

namespace App\Collaborators\Import;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RuntimeException;
use SimpleXMLElement;

/**
 * Collaborator feeds → listings with the importer's keys (ListingFields::KEYS) plus caracteristicas[], descripciones
 * [lang => text], titulos [lang => text], fotos[], planos[], videos[], tours[] and otros [label => value].
 * Everything the feed carries is kept: what has no field becomes a feature or goes to «otros datos»; personal keys
 * (owner, agent, contact) are dropped.
 *
 * - kyero: Kyero XML v3 (<root><property>…), the usual Spanish portal export.
 * - xml / json: any feed, with «elementos» (dot path to the list of listings, from the XML root element's children)
 *   and dot paths for the main fields (CollaboratorImport::MAPPABLE); unmapped fields are read by their names.
 *
 * ponytail: idealista/OpenNavigator XML not shipped; its export maps fine with the generic «xml» format.
 */
final class FeedParser
{
    /** Kyero v3 elements read explicitly (the rest of each <property> is absorbed by name). */
    private const KYERO_KNOWN = ['id', 'date', 'ref', 'price', 'currency', 'price_freq', 'type', 'town', 'province', 'country', 'location_detail', 'location',
        'beds', 'baths', 'pool', 'surface_area', 'energy_rating', 'url', 'desc', 'title', 'features', 'images', 'videos', 'video', 'virtual_tour', 'plans', 'floorplans'];

    /**
     * @param  array<string, string>  $mapping
     * @return list<array<string, mixed>>
     */
    public static function parse(string $body, string $format, array $mapping = []): array
    {
        if ($format === 'kyero') {
            return array_map(fn (SimpleXMLElement $p): array => self::finish(self::kyero($p)), iterator_to_array(self::xml($body)->property, false));
        }
        $tree = $format === 'json' ? json_decode($body, true, 512, JSON_THROW_ON_ERROR) : self::toArray(self::xml($body));
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
            $rest = $item;
            foreach (CollaboratorImport::MAPPABLE as $field) {
                if ($field !== 'elementos' && filled($mapping[$field] ?? null)) {
                    $row[$field] = data_get($item, $mapping[$field]);
                    Arr::forget($rest, $mapping[$field]);
                }
            }
            $lists = ['fotos' => self::urls($row['fotos'] ?? null), 'planos' => self::urls($row['planos'] ?? null), 'videos' => self::urls($row['videos'] ?? null)];
            $features = (array) ($row['caracteristicas'] ?? []);
            $texts = $row['descripcion'] ?? null;
            unset($row['fotos'], $row['planos'], $row['videos'], $row['caracteristicas']);
            $row = array_map(fn ($v) => is_array($v) && $v === [] ? null : $v, $row) + $lists;
            if (is_array($texts)) {   // {"es": "...", "en": "..."}
                $row['descripciones'] = array_filter(array_map(fn ($t) => is_scalar($t) ? (string) $t : null, $texts));
                $row['descripcion'] = $row['descripciones']['es'] ?? null;
            }
            $row = self::absorbTree($row, $rest);
            foreach (ListingFields::flatten($features) as $f) {
                $row['caracteristicas'][] = is_scalar($f) ? (ListingFields::feature((string) $f) ?? Str::ucfirst(trim((string) $f))) : null;
            }
            $out[] = self::finish($row);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private static function kyero(SimpleXMLElement $p): array
    {
        $text = fn (?SimpleXMLElement $node): ?string => $node === null || trim((string) $node) === '' ? null : trim((string) $node);
        $byLang = function (?SimpleXMLElement $node) use ($text): array {
            $out = [];
            foreach ($node?->children() ?? [] as $lang => $child) {
                if (($t = $text($child)) !== null) {
                    $out[Str::lower((string) $lang)] = $t;
                }
            }

            return $out;
        };
        $list = fn (?SimpleXMLElement $node): array => $node === null ? [] : self::urls(self::toArray($node));
        $descriptions = $byLang($p->desc);
        $urls = $byLang($p->url);
        [$consumption, $consumptionValue] = ListingFields::energy($text($p->energy_rating->consumption ?? null));
        [$emissions, $emissionsValue] = ListingFields::energy($text($p->energy_rating->emissions ?? null));
        $features = [];
        foreach ($p->features->feature ?? [] as $feature) {
            $features[] = ListingFields::feature((string) $feature) ?? Str::ucfirst(trim((string) $feature));
        }
        if ((string) $p->pool === '1') {
            $features[] = 'Piscina';
        }

        $listing = [
            'referencia' => $text($p->ref) ?? $text($p->id),
            'url' => $urls['es'] ?? (reset($urls) ?: $text($p->url)),
            'precio' => $text($p->price),
            'operacion' => match (strtolower((string) $p->price_freq)) {
                'month' => 'alquiler', 'week', 'night' => 'alquiler-temporal', default => 'venta',
            },
            'tipo' => $text($p->type),
            'm2' => $text($p->surface_area->built ?? null),
            'm2_parcela' => $text($p->surface_area->plot ?? null),
            'habitaciones' => $text($p->beds),
            'banos' => $text($p->baths),
            'municipio' => $text($p->town),
            'provincia' => $text($p->province),
            'zona' => $text($p->location_detail),
            'lat' => $text($p->location->latitude ?? null),
            'lon' => $text($p->location->longitude ?? null),
            'energia_letra' => $consumption,
            'energia_consumo' => $consumptionValue !== null && $consumption === null ? $consumptionValue : null,
            'emisiones_letra' => $emissions,
            'emisiones' => $emissionsValue !== null && $emissions === null ? $emissionsValue : null,
            'titulos' => $byLang($p->title),
            'descripciones' => $descriptions,
            'descripcion' => $descriptions['es'] ?? null,
            'caracteristicas' => $features,
            'fotos' => array_values(array_filter(array_map(fn ($i) => trim((string) $i->url), $p->images->image ? iterator_to_array($p->images->image, false) : []))),
            'planos' => [...$list($p->plans), ...$list($p->floorplans)],
            'videos' => [...$list($p->videos), ...$list($p->video)],
            'tours' => $list($p->virtual_tour),
            'otros' => array_filter(['Obra nueva' => (string) $p->new_build === '1' ? 'Sí' : null, 'Leasehold' => (string) $p->leasehold === '1' ? 'Sí' : null,
                'Copropiedad' => (string) $p->part_ownership === '1' ? 'Sí' : null, 'País' => $text($p->country), 'Fecha en el feed' => $text($p->date)]),
        ];
        $listing['titulo'] = $listing['titulos']['es'] ?? null;
        $rest = self::toArray($p);
        foreach (['new_build', 'leasehold', 'part_ownership', ...self::KYERO_KNOWN] as $known) {
            unset($rest[$known]);
        }

        return self::absorbTree($listing, $rest);
    }

    /**
     * Unmapped fields, read by their own names: known labels fill fields, feature lists become features, the rest
     * goes to «otros». A personal key drops its whole subtree.
     *
     * @param  array<string, mixed>  $listing
     * @param  array<array-key, mixed>  $tree
     * @return array<string, mixed>
     */
    public static function absorbTree(array $listing, array $tree, string $prefix = ''): array
    {
        foreach ($tree as $key => $value) {
            $label = is_int($key) ? $prefix : (string) $key;
            if ($label === '@attributes' || ($label !== '' && ListingFields::isPersonal($label))) {
                continue;
            }
            if (is_array($value) && $value !== [] && ! array_is_list($value)) {
                $listing = self::absorbTree($listing, $value, $label);
            } elseif (is_array($value)) {
                $scalars = array_filter(ListingFields::flatten($value), 'is_scalar');
                if (preg_match('/caracter|feature|extra|equipamiento|amenit|servicios|equipment/i', $label)) {
                    foreach ($scalars as $s) {
                        $listing['caracteristicas'][] = ListingFields::feature((string) $s) ?? Str::ucfirst(trim((string) $s));
                    }
                } elseif ($scalars !== []) {
                    $listing = ListingFields::absorb($listing, [$label => implode(', ', $scalars)]);
                }
            } elseif (is_scalar($value) && $label !== '') {
                $listing = ListingFields::absorb($listing, [$label => $value]);
            }
        }

        return $listing;
    }

    /** Videos / tours hidden among «otros» values, empty lists filled, features unique. */
    public static function finish(array $listing): array
    {
        foreach ($listing['otros'] ?? [] as $label => $value) {
            if (is_string($value) && preg_match('~^https?://\S+$~', $value)) {
                if (preg_match('~youtube\.com|youtu\.be|vimeo\.com~i', $value)) {
                    $listing['videos'][] = $value;
                    unset($listing['otros'][$label]);
                } elseif (preg_match('~matterport|kuula|tour|360|visita~i', $value.' '.$label)) {
                    $listing['tours'][] = $value;
                    unset($listing['otros'][$label]);
                }
            }
        }
        foreach (['caracteristicas', 'fotos', 'planos', 'videos', 'tours'] as $k) {
            $listing[$k] = array_values(array_unique(array_filter($listing[$k] ?? [], fn ($v) => is_string($v) && trim($v) !== '')));
        }

        return $listing;
    }

    /** @return list<string> http(s) URLs found in a value (string list, «a, b», [{url: …}], …) in order */
    private static function urls(mixed $value): array
    {
        if (is_string($value)) {
            return array_values(array_filter(preg_split('/[\s,;|]+/', trim($value)) ?: [], fn ($u) => preg_match('~^https?://~i', $u)));
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

    /** @return array<array-key, mixed> */
    private static function toArray(SimpleXMLElement $xml): array
    {
        return (array) json_decode((string) json_encode($xml), true);
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
