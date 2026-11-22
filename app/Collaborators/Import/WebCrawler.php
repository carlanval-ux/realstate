<?php

declare(strict_types=1);

namespace App\Collaborators\Import;

use App\Ai\AiQuotaExceeded;
use App\Ai\AiUnavailable;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Throwable;

/**
 * Polite crawl of a collaborator's website: robots.txt respected (group «ClartoBot», else «*», Crawl-delay honoured),
 * one request every importacion.web.pausa seconds, CLARTO User-Agent, at most importacion.web.max_paginas requests
 * per run, same host only. The start URL is a sitemap (urlset / sitemapindex) or a listing page whose links are
 * followed; the optional pattern («*» wildcard) selects listing pages. A run that hits the cap or fails a page is
 * «incomplete»: the importer then retires nothing.
 */
final class WebCrawler
{
    private int $requests = 0;

    private float $pause = 0;

    /** @var array{allow: list<string>, disallow: list<string>} */
    private array $robots = ['allow' => [], 'disallow' => []];

    private bool $aiEnabled = true;

    public function __construct(private readonly ListingAi $ai) {}

    /**
     * @return array{listings: list<array<string, mixed>>, pages: int, complete: bool, ai: int, notes: list<string>}
     */
    public function crawl(string $start, ?string $pattern): array
    {
        $max = (int) config('importacion.web.max_paginas', 150);
        $host = Str::lower((string) parse_url($start, PHP_URL_HOST));
        $notes = [];
        $complete = true;
        $ai = 0;
        $this->pause = (float) config('importacion.web.pausa', 5);
        $this->loadRobots($start);
        if (! $this->allowed($start)) {
            return ['listings' => [], 'pages' => $this->requests, 'complete' => false, 'ai' => 0, 'notes' => [__('importacion.errors.robots', ['url' => $start])]];
        }

        // 1. Candidate listing pages, from the sitemap(s) or the links of the listing page.
        $body = $this->fetch($start);
        $candidates = [];
        if (preg_match('~<(urlset|sitemapindex)\b~i', $body)) {
            $sitemaps = [$body];
            while (($xml = array_shift($sitemaps)) !== null) {
                foreach ($this->locs($xml) as $loc) {
                    if (preg_match('~<sitemapindex\b~i', $xml)) {
                        if ($this->requests < $max && $this->sameHost($loc, $host) && $this->allowed($loc)) {
                            $sitemaps[] = $this->fetch($loc);
                        } else {
                            $complete = false;
                        }
                    } else {
                        $candidates[] = $loc;
                    }
                }
            }
        } else {
            $candidates = PageExtractor::links($body, $start);
        }
        $candidates = array_values(array_unique(array_filter($candidates, fn (string $url): bool => $url !== $start
            && $this->sameHost($url, $host) && ($pattern === null || $pattern === '' || self::matches($url, $pattern)))));

        // 2. Each listing page (robots.txt and the page cap first).
        $listings = [];
        foreach ($candidates as $url) {
            if ($this->requests >= $max) {
                $complete = false;
                $notes[] = __('importacion.errors.page_cap', ['n' => $max]);
                break;
            }
            if (! $this->allowed($url)) {
                continue;
            }
            try {
                $html = $this->fetch($url);
            } catch (Throwable $e) {
                $complete = false;
                $notes[] = Str::limit($url.': '.$e->getMessage(), 200);

                continue;
            }
            $listing = PageExtractor::extract($html, $url);
            $listing = self::guess($listing);
            $missing = array_filter(['precio', 'operacion', 'tipo', 'municipio'], fn (string $k): bool => blank($listing[$k]));
            // Without a pattern every link is a candidate: only pages showing a price go to the AI.
            $looksLikeListing = filled($pattern) || filled($listing['precio']) || preg_match('/\d[\d.]{2,}\s?(€|eur)/iu', PageExtractor::text($html, 20000));
            if ($missing !== [] && $this->aiEnabled && $looksLikeListing) {
                try {
                    $ai++;
                    $read = $this->ai->extract($url, PageExtractor::text($html));
                    if (($read['es_inmueble'] ?? true) === false) {
                        continue;
                    }
                    foreach ($missing as $key) {
                        $listing[$key] = $read[$key] ?? null;
                    }
                    $listing['referencia'] ??= $read['referencia'] ?? null;
                    $listing['habitaciones'] ??= $read['habitaciones'] ?? null;
                    $listing['banos'] ??= $read['banos'] ?? null;
                    $listing['m2'] ??= $read['m2'] ?? null;
                    $listing['zona'] ??= $read['zona'] ?? null;
                } catch (AiQuotaExceeded) {
                    $this->aiEnabled = false;
                    $notes[] = __('importacion.errors.ai_quota');
                } catch (AiUnavailable) {
                    // keeps what the markup gave
                }
            }
            if (blank($listing['precio'])) {
                continue;   // not a listing page (or nothing usable on it)
            }
            $listings[] = $listing;
        }

        return ['listings' => $listings, 'pages' => $this->requests, 'complete' => $complete, 'ai' => $ai, 'notes' => $notes];
    }

    /** Operation and type from the page title / URL when the markup does not say (saves AI calls). */
    private static function guess(array $listing): array
    {
        $haystack = Str::lower(Str::ascii(($listing['titulo'] ?? '').' '.($listing['url'] ?? '')));
        $listing['operacion'] ??= match (true) {
            (bool) preg_match('/vacacion|temporada|holiday/', $haystack) => 'alquiler-temporal',
            (bool) preg_match('/alquil|arrend|for-rent|to-rent|\brent\b/', $haystack) => 'alquiler',
            (bool) preg_match('/venta|vende|for-sale|\bsale\b|comprar/', $haystack) => 'venta',
            default => null,
        };
        if (blank($listing['tipo']) && preg_match('/\b(piso|apartamento|atico|duplex|estudio|chalet|villa|adosado|pareado|casa|finca|local|oficina|nave|terreno|solar|parcela|garaje|edificio)\b/', $haystack, $m)) {
            $listing['tipo'] = $m[1];
        }

        return $listing;
    }

    /** «*» = anything. A pattern without scheme is matched against the path (+ query). */
    public static function matches(string $url, string $pattern): bool
    {
        $subject = preg_match('~^https?://~i', $pattern) ? $url : (parse_url($url, PHP_URL_PATH) ?? '/').(($q = parse_url($url, PHP_URL_QUERY)) ? '?'.$q : '');
        $regex = '~^'.str_replace('\*', '.*', preg_quote(trim($pattern), '~')).'$~i';

        return (bool) preg_match($regex, $subject);
    }

    private function fetch(string $url): string
    {
        if ($this->requests > 0 && $this->pause > 0) {
            Sleep::for($this->pause)->seconds();
        }
        $this->requests++;
        $response = ImportHttp::get($url, 20)->throw();
        $body = $response->body();
        throw_if(strlen($body) > 5_000_000, new \RuntimeException(__('importacion.errors.too_big', ['url' => $url])));

        return $body;
    }

    /** @return list<string> */
    private function locs(string $xml): array
    {
        preg_match_all('~<loc>\s*(?:<!\[CDATA\[)?\s*([^<\]]+?)\s*(?:\]\]>)?\s*</loc>~i', $xml, $m);

        return array_map(fn (string $u) => html_entity_decode($u, ENT_QUOTES | ENT_XML1), $m[1]);
    }

    private function sameHost(string $url, string $host): bool
    {
        $h = Str::lower((string) parse_url($url, PHP_URL_HOST));

        return $h === $host || 'www.'.$h === $host || $h === 'www.'.$host;
    }

    private function loadRobots(string $start): void
    {
        $p = parse_url($start);
        try {
            $response = ImportHttp::get(($p['scheme'] ?? 'https').'://'.($p['host'] ?? '').(isset($p['port']) ? ':'.$p['port'] : '').'/robots.txt', 15);
            $this->requests++;
        } catch (Throwable) {
            $this->requests++;

            return;   // unreachable robots.txt = no rules
        }
        if (! $response->successful()) {
            return;
        }
        $groups = [];
        $agents = [];
        $inRules = false;
        foreach (preg_split('/\R/', $response->body()) ?: [] as $line) {
            $line = trim((string) preg_replace('/#.*/', '', $line));
            if (! str_contains($line, ':')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode(':', $line, 2));
            $key = Str::lower($key);
            if ($key === 'user-agent') {
                if ($inRules) {
                    $agents = [];
                    $inRules = false;
                }
                $agents[] = Str::lower($value);
                foreach ($agents as $a) {
                    $groups[$a] ??= ['allow' => [], 'disallow' => [], 'delay' => null];
                }

                continue;
            }
            $inRules = true;
            foreach ($agents as $a) {
                match ($key) {
                    'allow' => $value !== '' ? $groups[$a]['allow'][] = $value : null,
                    'disallow' => $value !== '' ? $groups[$a]['disallow'][] = $value : null,
                    'crawl-delay' => $groups[$a]['delay'] = is_numeric($value) ? (float) $value : null,
                    default => null,
                };
            }
        }
        $group = $groups['clartobot'] ?? $groups['*'] ?? null;
        if ($group !== null) {
            $this->robots = ['allow' => $group['allow'], 'disallow' => $group['disallow']];
            $this->pause = max($this->pause, (float) ($group['delay'] ?? 0));
        }
    }

    /** Longest matching rule wins; Allow wins a tie. */
    private function allowed(string $url): bool
    {
        $path = (parse_url($url, PHP_URL_PATH) ?: '/').(($q = parse_url($url, PHP_URL_QUERY)) ? '?'.$q : '');
        $best = ['len' => -1, 'allow' => true];
        foreach (['allow' => true, 'disallow' => false] as $kind => $allow) {
            foreach ($this->robots[$kind] as $rule) {
                $regex = '~^'.str_replace(['\*', '\$'], ['.*', '$'], preg_quote($rule, '~')).'~';
                if (preg_match($regex, $path) && (strlen($rule) > $best['len'] || (strlen($rule) === $best['len'] && $allow))) {
                    $best = ['len' => strlen($rule), 'allow' => $allow];
                }
            }
        }

        return $best['allow'];
    }
}
