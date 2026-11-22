<?php

declare(strict_types=1);

namespace App\Collaborators\Import;

use Illuminate\Support\Str;

/**
 * Everything a collaborator's listing page shows, with the importer's keys (see FeedParser): schema.org JSON-LD
 * first, then OpenGraph / product meta, then the page's own feature lists and «Label: value» rows (definition lists,
 * tables, list items) inside the main content. Photos: every image of the listing at its largest variant (srcset,
 * links to originals, data-* zoom attributes), in page order, without site chrome; plans, videos and virtual tours
 * apart. What is still empty goes to the AI fallback.
 */
final class PageExtractor
{
    /** Only these nodes are read: the agency's Organization, people, breadcrumbs… never feed a listing field. */
    private const LISTING_TYPES = ['RealEstateListing', 'Product', 'Offer', 'Apartment', 'House', 'SingleFamilyResidence', 'Residence', 'Accommodation', 'Room'];

    /** JSON-LD keys read explicitly or never wanted in «otros datos». */
    private const LD_SKIP = ['@context', '@type', '@id', 'name', 'description', 'image', 'photo', 'logo', 'offers', 'itemOffered', 'address', 'geo', 'url', 'sku',
        'productID', 'identifier', 'floorSize', 'numberOfRooms', 'numberOfBedrooms', 'numberOfBathroomsTotal', 'numberOfFullBathrooms', 'amenityFeature', 'additionalProperty',
        'price', 'priceCurrency', 'priceSpecification', 'businessFunction', 'availability', 'yearBuilt', 'floorLevel', 'seller', 'offeredBy',
        'brand', 'provider', 'author', 'publisher', 'mainEntityOfPage', 'potentialAction', 'video', 'subjectOf', 'containedInPlace', 'category', 'about', 'mainEntity'];

    private const CHROME = '~logo|icon|sprite|avatar|banner|flag|placeholder|loading|spinner|pixel|badge|social|whatsapp|facebook|twitter|instagram|linkedin|captcha|gravatar|\.svg(\?|$)~i';

    /** @return array<string, mixed> listing (+ «_alternates» [lang => url], «_lang») */
    public static function extract(string $html, string $url): array
    {
        $ld = self::jsonLd($html);
        $og = self::meta($html);
        $main = self::mainContent($html);
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
        $business = Str::lower((string) $first(['offers.businessFunction', 'offers.0.businessFunction', 'businessFunction']));
        $types = collect($ld)->pluck('@type')->flatten()->map(fn ($t) => (string) $t)->all();
        $lang = preg_match('~<html\b[^>]*\blang\s*=\s*["\']([a-z]{2})~i', $html, $m) ? Str::lower($m[1]) : 'es';
        $description = $first(['description']) ?? ($og['og:description'][0] ?? null);
        $title = $first(['name']) ?? ($og['og:title'][0] ?? null);

        $listing = [
            'referencia' => $first(['sku', 'productID', 'identifier', 'offers.sku']),
            'url' => $url,
            'precio' => $first(['offers.price', 'offers.0.price', 'offers.priceSpecification.price', 'price']) ?? ($og['product:price:amount'][0] ?? $og['og:price:amount'][0] ?? null),
            'operacion' => str_contains($business, 'lease') ? 'alquiler' : (str_contains($business, 'sell') ? 'venta' : null),
            'tipo' => collect($types)->first(fn (string $t) => in_array($t, ['Apartment', 'House', 'SingleFamilyResidence', 'Room'], true))
                ?? $first(['itemOffered.@type', 'category']),
            'm2' => $first(['floorSize', 'floorSize.value', 'itemOffered.floorSize.value']),
            'habitaciones' => $first(['numberOfBedrooms', 'itemOffered.numberOfBedrooms', 'numberOfRooms', 'itemOffered.numberOfRooms']),
            'banos' => $first(['numberOfBathroomsTotal', 'numberOfFullBathrooms', 'itemOffered.numberOfBathroomsTotal']),
            'anio' => $first(['yearBuilt', 'itemOffered.yearBuilt']),
            'planta' => $first(['floorLevel', 'itemOffered.floorLevel']),
            'municipio' => $first(['address.addressLocality', 'itemOffered.address.addressLocality', 'location.address.addressLocality']),
            'provincia' => $first(['address.addressRegion', 'itemOffered.address.addressRegion']),
            'direccion' => $first(['address.streetAddress', 'itemOffered.address.streetAddress']),
            'cp' => $first(['address.postalCode', 'itemOffered.address.postalCode']),
            'lat' => $first(['geo.latitude', 'itemOffered.geo.latitude']),
            'lon' => $first(['geo.longitude', 'itemOffered.geo.longitude']),
            'zona' => null,
            'titulo' => $title,
            'descripcion' => $lang === 'es' ? $description : null,
            'titulos' => $title ? [$lang => $title] : [],
            'descripciones' => $description ? [$lang => $description] : [],
            'caracteristicas' => [],
            'otros' => [],
            '_lang' => $lang,
            '_alternates' => self::alternates($html, $url),
        ];

        // Rest of the listing's JSON-LD: amenities become features, every other property goes to «otros datos».
        foreach ($ld as $node) {
            foreach ([...(array) ($node['amenityFeature'] ?? []), ...(array) ($node['additionalProperty'] ?? [])] as $amenity) {
                if (is_array($amenity) && filled($amenity['name'] ?? null)) {
                    $value = $amenity['value'] ?? true;
                    $listing = ListingFields::absorb($listing, [(string) $amenity['name'] => is_bool($value) ? ($value ? 'Sí' : 'No') : $value]);
                }
            }
            $listing = FeedParser::absorbTree($listing, array_diff_key($node, array_flip(self::LD_SKIP)));
        }
        $listing = ListingFields::absorb($listing, self::rows($main));
        foreach (self::listItems($main) as $item) {
            if (($feature = ListingFields::feature($item)) !== null) {
                $listing['caracteristicas'][] = $feature;
            }
        }

        // Media: structured data first; the gallery in the page adds what it lacks and the largest variants.
        $media = self::images($ld, $og, $main, $url);
        $listing['fotos'] = array_values(array_filter($media, fn (string $u) => ! self::isPlan($u)));
        $listing['planos'] = array_values(array_filter($media, fn (string $u) => self::isPlan($u)));
        [$listing['videos'], $listing['tours']] = self::videos($ld, $main, $url);

        return FeedParser::finish($listing);
    }

    /** Visible text for the AI: no scripts, styles or markup, whitespace collapsed, capped. */
    public static function text(string $html, int $max = 8000): string
    {
        $html = (string) preg_replace('~<(script|style|noscript|svg|nav|footer)\b.*?</\1>~is', ' ', $html);
        $text = html_entity_decode(strip_tags((string) preg_replace('~<(br|/p|/div|/li|/h\d|/dd|/tr)\b[^>]*>~i', "\n", $html)), ENT_QUOTES | ENT_HTML5);

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

    /**
     * Every image of the listing once, at its largest variant, in order of first appearance. With 3+ images in the
     * JSON-LD those are the gallery (the page only upgrades them to larger variants): related-listing thumbnails stay out.
     *
     * @return list<string>
     */
    private static function images(array $ld, array $og, string $main, string $base): array
    {
        $candidates = [];   // [url, weight]
        $structured = [];
        foreach ($ld as $node) {
            foreach ((array) ($node['image'] ?? []) as $key => $image) {
                $u = is_array($image) ? ($image['contentUrl'] ?? $image['url'] ?? null) : ($key === 'url' || is_int($key) ? $image : null);
                if (is_string($u)) {
                    $structured[] = [$u, 1000];
                }
            }
        }
        foreach ($og['og:image'] ?? [] as $u) {
            $structured[] = [$u, 1000];
        }
        $candidates = $structured;
        preg_match_all('~<(a|img|source)\b[^>]*>~i', $main, $tags, PREG_SET_ORDER);
        foreach ($tags as [$tag, $name]) {
            $name = Str::lower($name);
            if ($name === 'a') {
                if (preg_match('~\bhref\s*=\s*["\']([^"\']+\.(?:jpe?g|png|webp)(?:\?[^"\']*)?)["\']~i', $tag, $m)) {
                    $candidates[] = [$m[1], 100000];   // link to the original (lightbox)
                }

                continue;
            }
            $width = preg_match('~\bwidth\s*=\s*["\']?(\d+)~i', $tag, $w) ? (int) $w[1] : null;
            $height = preg_match('~\bheight\s*=\s*["\']?(\d+)~i', $tag, $h) ? (int) $h[1] : null;
            if (($width !== null && $width < 150) || ($height !== null && $height < 100)) {   // icons; chrome is filtered by URL below
                continue;
            }
            foreach (['data-zoom-image' => 60000, 'data-full' => 60000, 'data-large' => 50000, 'data-original' => 50000, 'data-big' => 50000, 'data-src' => 800, 'data-lazy' => 800, 'src' => $width ?? 500] as $attr => $weight) {
                if (preg_match('~\b'.preg_quote($attr, '~').'\s*=\s*["\']([^"\']+)["\']~i', $tag, $m) && ! str_starts_with($m[1], 'data:')) {
                    $candidates[] = [$m[1], $weight];
                }
            }
            foreach (['srcset', 'data-srcset'] as $attr) {
                if (preg_match('~\b'.$attr.'\s*=\s*["\']([^"\']+)["\']~i', $tag, $m)) {
                    foreach (explode(',', $m[1]) as $part) {
                        if (preg_match('~^\s*(\S+)(?:\s+(\d+(?:\.\d+)?)([wx]))?~', $part, $p)) {
                            $candidates[] = [$p[1], isset($p[2]) ? (int) ((float) $p[2] * ($p[3] === 'x' ? 1000 : 1)) : 500];
                        }
                    }
                }
            }
        }

        $best = [];   // key => [url, weight], insertion order = first appearance
        $allowed = count($structured) >= 3 ? array_flip(array_map(fn ($c) => self::imageKey((string) self::absolute($c[0], $base)), $structured)) : null;
        foreach ($candidates as [$u, $weight]) {
            $abs = self::absolute(html_entity_decode((string) $u), $base);
            if ($abs === null || preg_match(self::CHROME, $abs)) {
                continue;
            }
            $key = self::imageKey($abs);
            if ($allowed !== null && ! isset($allowed[$key]) && ! self::isPlan($abs)) {
                continue;
            }
            $weight = max($weight, preg_match('~(\d{3,4})x\d{3,4}~', $abs, $s) ? (int) $s[1] : 0, preg_match('~[?&](?:w|width)=(\d{3,4})~i', $abs, $q) ? (int) $q[1] : 0);
            if (! isset($best[$key]) || $weight > $best[$key][1]) {
                $best[$key] = [$abs, $weight, $best[$key][2] ?? count($best)];
            }
        }
        uasort($best, fn ($a, $b) => $a[2] <=> $b[2]);

        return array_values(array_map(fn ($b) => $b[0], $best));
    }

    /** Same image in another size → same key (size folders, «-300x200», «_thumb», size query parameters dropped). */
    public static function imageKey(string $url): string
    {
        $p = parse_url(Str::lower($url));
        $segments = array_values(array_filter(explode('/', $p['path'] ?? ''), fn (string $s) => $s !== ''
            && ! preg_match('/^(thumbs?|thumbnails?|small|medium|large|big|original|originals|originales|full|fullsize|th|mini|miniaturas?|medianas?|grandes?|pequenas?|xl|lg|md|sm|xs|\d+x\d+|[wh]\d+|\d+)$/', $s)));
        $file = (string) array_pop($segments);
        $file = (string) preg_replace(['/\.(jpe?g|png|webp|gif)$/', '/([-_@](\d+x\d+|\d+w|2x|3x|thumb|thumbnail|small|medium|large|big|th|mini|xl|lg|md|sm|xs|full|original))+$/'], '', $file);
        parse_str($p['query'] ?? '', $query);
        $query = array_diff_key($query, array_flip(['w', 'h', 'width', 'height', 'size', 'quality', 'q', 'fit', 'crop', 'resize', 'format', 'auto', 'dpr', 'fm', 'v']));
        ksort($query);

        return ($p['host'] ?? '').'/'.implode('/', $segments).'/'.$file.($query ? '?'.http_build_query($query) : '');
    }

    private static function isPlan(string $url): bool
    {
        return (bool) preg_match('~plano|floor-?plan|/plans?/|[-_]plan[-_.]~i', $url);
    }

    /** @return array{0: list<string>, 1: list<string>} videos (YouTube/Vimeo/files) and virtual tours */
    private static function videos(array $ld, string $main, string $base): array
    {
        $urls = [];
        foreach ($ld as $node) {
            foreach ((array) ($node['video'] ?? []) as $video) {
                $urls[] = is_array($video) ? ($video['embedUrl'] ?? $video['contentUrl'] ?? null) : $video;
            }
        }
        preg_match_all('~<(?:iframe|a|source|video)\b[^>]*\b(?:src|href|data-src)\s*=\s*["\']([^"\']+)["\']~i', $main, $m);
        $urls = array_merge($urls, $m[1]);
        $videos = $tours = [];
        foreach (array_filter($urls, 'is_string') as $u) {
            $abs = self::absolute(html_entity_decode($u), $base);
            if ($abs === null) {
                continue;
            }
            if (preg_match('~youtube\.com/(watch|embed)|youtu\.be/|vimeo\.com/|\.mp4(\?|$)~i', $abs)) {
                $videos[] = $abs;
            } elseif (preg_match('~matterport\.com|kuula\.co|my\.matterport|tour|visita-?virtual|recorrido-?virtual|/360/~i', $abs)) {
                $tours[] = $abs;
            }
        }

        return [array_values(array_unique($videos)), array_values(array_unique($tours))];
    }

    /** @return array<string, string> «label => value» of definition lists, two-column table rows and «Label: value» items */
    private static function rows(string $main): array
    {
        $clean = fn (string $s): string => trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5)));
        $rows = [];
        preg_match_all('~<dt\b[^>]*>(.*?)</dt>\s*<dd\b[^>]*>(.*?)</dd>~is', $main, $dl, PREG_SET_ORDER);
        preg_match_all('~<tr\b[^>]*>\s*<t[hd]\b[^>]*>(.*?)</t[hd]>\s*<td\b[^>]*>(.*?)</td>\s*</tr>~is', $main, $tr, PREG_SET_ORDER);
        foreach ([...$dl, ...$tr] as [, $label, $value]) {
            if (($l = rtrim($clean($label), ':')) !== '' && mb_strlen($l) <= 60) {
                $rows[$l] ??= $clean($value);
            }
        }
        foreach (self::listItems($main) as $item) {
            if (preg_match('/^([^:]{2,40}):\s*(.+)$/u', $item, $m)) {
                $rows[trim($m[1])] ??= trim($m[2]);
            }
        }

        return $rows;
    }

    /** @return list<string> */
    private static function listItems(string $main): array
    {
        preg_match_all('~<li\b[^>]*>(.*?)</li>~is', $main, $m);

        return array_values(array_filter(array_map(fn (string $s) => trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5))), $m[1]),
            fn (string $s) => $s !== '' && mb_strlen($s) <= 120));
    }

    /** The listing itself: <main>/<article> when present, never header, nav, footer, aside, forms or scripts. */
    private static function mainContent(string $html): string
    {
        $body = preg_match('~<main\b[^>]*>(.*?)</main>~is', $html, $m) ? $m[1] : (preg_match('~<article\b[^>]*>(.*?)</article>~is', $html, $a) ? $a[1] : $html);

        return (string) preg_replace('~<(header|nav|footer|aside|form|script|style|noscript)\b.*?</\1>~is', ' ', $body);
    }

    /** @return array<string, string> lang => URL of the same listing in other languages (hreflang) */
    private static function alternates(string $html, string $url): array
    {
        preg_match_all('~<link\b[^>]*>~i', $html, $tags);
        $out = [];
        foreach ($tags[0] as $tag) {
            if (preg_match('~rel\s*=\s*["\']alternate~i', $tag) && preg_match('~hreflang\s*=\s*["\']([a-z]{2})~i', $tag, $l) && preg_match('~href\s*=\s*["\']([^"\']+)~i', $tag, $h)) {
                $abs = self::absolute(html_entity_decode($h[1]), $url);
                if ($abs !== null && rtrim($abs, '/') !== rtrim($url, '/')) {
                    $out[Str::lower($l[1])] = $abs;
                }
            }
        }

        return $out;
    }

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
