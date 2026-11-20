<?php

declare(strict_types=1);

namespace App\Acquisition;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Read-only calls to GoHighLevel API v2 (LeadConnector) used to verify a won opportunity. */
final class GhlClient
{
    /** @return array<string, mixed> */
    public function opportunity(string $id): array
    {
        return (array) ($this->get('/opportunities/'.rawurlencode($id))['opportunity'] ?? []);
    }

    /** @return array<string, mixed> */
    public function contact(string $id): array
    {
        return (array) ($this->get('/contacts/'.rawurlencode($id))['contact'] ?? []);
    }

    /** @return array<string, mixed> */
    private function get(string $path): array
    {
        $response = $this->http()->get(config('ghl.api_url').$path);
        if (! $response->successful()) {
            throw new RuntimeException("GHL {$path}: HTTP ".$response->status());
        }

        return (array) $response->json();
    }

    private function http(): PendingRequest
    {
        $token = (string) config('ghl.token');
        if ($token === '') {
            throw new RuntimeException('GHL_API_TOKEN is not configured.');
        }

        return Http::withToken($token)->acceptJson()->timeout(20)->withHeaders(['Version' => (string) config('ghl.api_version')]);
    }
}
