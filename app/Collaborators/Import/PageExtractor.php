<?php

declare(strict_types=1);

namespace App\Collaborators\Import;

use Illuminate\Support\Str;

/**
 * Listing fields from a collaborator's page markup: schema.org JSON-LD first, then OpenGraph / product meta tags.
 * Returns the importer's field names (see FeedParser); what the markup does not carry stays null for the AI fallback.
 */
final class PageExtractor
{
    /** @return array<string, mixed> */
    public static function extract(string $html, string $url): array
    {
        $ld = self::jsonLd($html);
        $og = self::meta($html);
        $first = function (array $paths) use ($ld): mixed {
            foreach ($ld as $node) {
                foreach ($paths as $path) {
                    $value = data_get($node, $path);
                    if (is_array($value) && isset($value['value'])) {
                        $value = $value['value'];   // PropertyValue / QuantitativeValue
                    }
                    if (is_scalar($value) && trim((string) $value) !== '') {
                        return trim((string) $value);
                    }
                }
            }

            return null;
        };

        $photos = [];
        foreach ($ld as $node) {
            foreach ((array) ($node['image'] ?? []) as $key => $image) {
                $photos[] = is_array($image) ? ($image['url'] ?? $image['contentUrl'] ?? null) : ($key === 'url' || is_int($key) ? $image : null);
            }
        }
        $photos = array_merge($photos, $og['og:image'] ?? []);
        $photos = array_values(array_unique(array_filter(array_map(fn ($p) => is_string($p) ? self::absolute($p, $url) : null, $photos))));

        $business = Str::lower((string) $first(['offers.businessFunction', 'offers.0.businessFunction', 'businessFunction']));
        $types = collect($ld)->pluck('@type')->flatten()->map(fn ($t) => (string) $t)->all();

        return [
            'referencia' => $first(['sku', 'productID', 'identifier', 'offers.sku']),
            'url' => $url,
            'precio' => $first(['offers.price', 'offers.0.price', 'offers.priceSpecification.price', 'price']) ?? ($og['product:price:amount'][0] ?? $og['og:price:amount'][0] ?? null),
            'operacion' => str_contains($business, 'lease') ? 'alquiler' : (str_contains($business, 'sell') ? 'venta' : null),
            'tipo' => collect($types)->first(fn (string $t) => in_array($t, ['Apartment', 'House', 'SingleFamilyResidence', 'Room'], true))
                ?? $first(['itemOffered.@type', 'category']),
            'm2' => $first(['floorSize', 'floorSize.value', 'itemOffered.floorSize.value']),
            'habitaciones' => $first(['numberOfBedrooms', 'itemOffered.numberOfBedrooms', 'numberOfRooms', 'itemOffered.numberOfRooms']),
            'banos' => $first(['numberOfBathroomsTotal', 'numberOfFullBathrooms', 'itemOffered.numberOfBathroomsTotal']),
            'municipio' => $first(['address.addressLocality', 'itemOffered.address.addressLocality', 'location.address.addressLocality']),
            'zona' => null,
            'titulo' => $first(['name']) ?? ($og['og:title'][0] ?? null),
            'descripcion' => $first(['description']) ?? ($og['og:description'][0] ?? null),
            'fotos' => $photos,
        ];
    }

    /** Visible text for the AI: no scripts, styles or markup, whitespace collapsed, capped. */
    public static function text(string $html, int $max = 8000): string
    {
        $html = (string) preg_replace('~<(script|style|noscript|svg|nav|footer)\b.*?</\1>~is', ' ', $html);
        $text = html_entity_decode(strip_tags((string) preg_replace('~<(br|/p|/div|/li|/h\d)\b[^>]*>~i', "\n", $html)), ENT_QUOTES | ENT_HTML5);

        return Str::limit(trim((string) preg_replace('/[ \t\r]+/', ' ', (string) preg_replace('/\n\s*\n+/', "\n", $text))), $max, '');
    }

    /** @return list<string> absolute links of the page */
    public static function links(string $html, string $base): array
    {
        preg_match_all('~<a\b[^>]*\bhref\s*=\s*["\']([^"\'#]+)~i', $html, $m);

        return array_values(array_unique(array_filter(array_map(fn (string $href) => self::absolute(html_entity_decode($href), $base), $m[1]))));
    }

    public static function absolute(string $href, string $base): ?string
    {
        $href = trim($href);
        if ($href === '' || preg_match('~^(mailto|tel|javascript|data):~i', $href)) {
            return null;
        }
        if (preg_match('~^https?://~i', $href)) {
            return $href;
        }
        $b = parse_url($base);
        $origin = ($b['scheme'] ?? 'https').'://'.($b['host'] ?? '').(isset($b['port']) ? ':'.$b['port'] : '');
        if (str_starts_with($href, '//')) {
            return ($b['scheme'] ?? 'https').':'.$href;
        }
        if (str_starts_with($href, '/')) {
            return $origin.$href;
        }
        $dir = preg_replace('~/[^/]*$~', '/', $b['path'] ?? '/');

        return $origin.$dir.$href;
    }

    /** Only these nodes are read: the agency's Organization, people, breadcrumbs… never feed a listing field. */
    private const LISTING_TYPES = ['RealEstateListing', 'Product', 'Offer', 'Apartment', 'House', 'SingleFamilyResidence', 'Residence', 'Accommodation', 'Room'];

    /** @return list<array<string, mixed>> every listing-like JSON-LD node (including @graph and nested ones) */
    private static function jsonLd(string $html): array
    {
        preg_match_all('~<script[^>]+type\s*=\s*["\']application/ld\+json["\'][^>]*>(.*?)</script>~is', $html, $m);
        $nodes = [];
        $walk = function (mixed $value) use (&$walk, &$nodes): void {
            if (! is_array($value)) {
                return;
            }
            if (array_intersect((array) ($value['@type'] ?? []), self::LISTING_TYPES) !== []) {
                $nodes[] = $value;
            }
            foreach ($value as $child) {
                $walk($child);
            }
        };
        foreach ($m[1] as $json) {
            $walk(json_decode(html_entity_decode(trim($json)), true));
        }

        return $nodes;
    }

    /** @return array<string, list<string>> meta property/name => contents */
    private static function meta(string $html): array
    {
        preg_match_all('~<meta\b[^>]*>~i', $html, $tags);
        $out = [];
        foreach ($tags[0] as $tag) {
            if (preg_match('~\b(?:property|name)\s*=\s*["\']([^"\']+)~i', $tag, $k) && preg_match('~\bcontent\s*=\s*["\']([^"\']*)~i', $tag, $v)) {
                $out[Str::lower($k[1])][] = html_entity_decode($v[1], ENT_QUOTES | ENT_HTML5);
            }
        }

        return $out;
    }
}
