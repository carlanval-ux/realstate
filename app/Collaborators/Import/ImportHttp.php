<?php

declare(strict_types=1);

namespace App\Collaborators\Import;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;
use RuntimeException;

/**
 * Every request of the importer (feed, robots.txt, pages, photos): identifies CLARTO in the User-Agent, only http(s),
 * never to private/reserved addresses (URLs come from third-party content), redirects checked too.
 */
final class ImportHttp
{
    public static function get(string $url, int $timeout = 30): Response
    {
        self::assertSafe($url);

        return Http::withUserAgent((string) config('importacion.user_agent'))
            ->timeout($timeout)
            ->withOptions(['allow_redirects' => ['max' => 3, 'protocols' => ['http', 'https'],
                'on_redirect' => fn (RequestInterface $request, $response, UriInterface $uri) => self::assertSafe((string) $uri)]])
            ->get($url);
    }

    /** @throws RuntimeException */
    public static function assertSafe(string $url): void
    {
        $parts = parse_url($url);
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        if (! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || $host === '' || $host === 'localhost') {
            throw new RuntimeException(__('importacion.errors.unsafe_url', ['url' => $url]));
        }
        // ponytail: resolves once (no DNS-rebinding defence); hosts that do not resolve fail at the request itself.
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new RuntimeException(__('importacion.errors.unsafe_url', ['url' => $url]));
            }
        }
    }
}
