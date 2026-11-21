<?php

declare(strict_types=1);

namespace App\Acquisition;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * GoHighLevel API v2 (LeadConnector) for the CLARTO sub-account: contacts, tags, notes, opportunities and custom
 * fields. Custom fields are addressed by the annex-A key (opportunity.<key> / contact.<key>) and resolved to their id
 * through GET /locations/{id}/customFields (cached), unless config ghl.fields / ghl.contact_fields give the id.
 */
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
    public function get(string $path): array
    {
        return $this->call('GET', $path);
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     *
     * @throws GhlError on any non-2xx answer (status and body kept), RuntimeException when GHL does not answer
     */
    public function call(string $method, string $path, ?array $body = null): array
    {
        try {
            $response = $this->http()->send($method, config('ghl.api_url').$path, $body === null ? [] : ['json' => $body]);
        } catch (ConnectionException $e) {
            throw new RuntimeException("GHL {$method} ".strtok($path, '?').': sin respuesta', previous: $e);
        }
        if (! $response->successful()) {
            throw new GhlError("GHL {$method} ".strtok($path, '?').': HTTP '.$response->status(), $response);
        }

        return (array) $response->json();
    }

    /** @param list<string> $tags  added without touching the contact's other tags (upsert/PUT would overwrite them) */
    public function addTags(string $contactId, array $tags): void
    {
        if ($tags !== []) {
            $this->call('POST', '/contacts/'.rawurlencode($contactId).'/tags', ['tags' => array_values($tags)]);
        }
    }

    /** @param list<string> $tags */
    public function removeTags(string $contactId, array $tags): void
    {
        if ($tags !== []) {
            $this->call('DELETE', '/contacts/'.rawurlencode($contactId).'/tags', ['tags' => array_values($tags)]);
        }
    }

    public function note(string $contactId, string $body): void
    {
        $this->call('POST', '/contacts/'.rawurlencode($contactId).'/notes', ['body' => mb_substr($body, 0, 5000)]);
    }

    /**
     * Custom field values for a create/update body. Unknown keys (field not created in GHL) and empty values are left
     * out. Both the current (fieldValue) and the legacy (field_value) property are sent: the API v2 documentation
     * differs between contacts and opportunities, and a value in the wrong property is ignored silently (L-0).
     *
     * @param  array<string, mixed>  $values  key => value (string, number, list for multi-select)
     * @return list<array<string, mixed>>
     */
    public function fieldsPayload(string $model, array $values): array
    {
        $ids = $this->fieldIds($model, strict: true);
        $out = [];
        foreach ($values as $key => $value) {
            if (! isset($ids[$key]) || $value === null || $value === '' || $value === []) {
                continue;
            }
            $value = is_array($value) ? array_values(array_map('strval', $value)) : (is_bool($value) ? ($value ? 'Sí' : 'No') : (string) $value);
            $out[] = ['id' => $ids[$key], 'fieldValue' => $value, 'field_value' => $value];
        }

        return $out;
    }

    /**
     * A custom field value of a contact or opportunity read from GHL, by key (null when empty or unknown).
     *
     * @param  array<string, mixed>  $entity
     */
    public function value(array $entity, string $model, string $key): ?string
    {
        $id = $this->fieldIds($model)[$key] ?? null;
        if ($id === null) {
            return null;
        }
        foreach ((array) ($entity['customFields'] ?? $entity['customField'] ?? []) as $field) {
            if ((string) ($field['id'] ?? '') === $id) {
                $value = $field['value'] ?? $field['fieldValue'] ?? $field['field_value'] ?? $field['fieldValueString'] ?? $field['fieldValueNumber'] ?? $field['fieldValueArray'] ?? null;   // GET vs search formats
                $value = trim(is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value);

                return $value === '' ? null : $value;
            }
        }

        return null;
    }

    /**
     * key => field id for «contact» or «opportunity». Explicit ids from config win. $strict: a failed lookup throws
     * (writes must not drop fields silently); otherwise it returns only the configured ids (reads degrade gracefully).
     *
     * @return array<string, string>
     */
    public function fieldIds(string $model, bool $strict = false): array
    {
        $configured = $model === 'contact'
            ? (array) config('ghl.contact_fields')
            : [...(array) config('ghl.fields'), ...array_filter((array) config('valoraciones.ghl.fields'))];
        try {
            $discovered = Cache::remember('ghl:campos:'.config('ghl.location_id'), now()->addHours(max(1, (int) config('ghl.fields_cache_hours', 6))), function (): array {
                $map = ['contact' => [], 'opportunity' => []];
                foreach ((array) ($this->get('/locations/'.rawurlencode((string) config('ghl.location_id')).'/customFields?model=all')['customFields'] ?? []) as $f) {
                    [$prefix, $key] = array_pad(explode('.', (string) ($f['fieldKey'] ?? ''), 2), 2, '');
                    if (isset($map[$prefix]) && $key !== '' && filled($f['id'] ?? null)) {
                        $map[$prefix][$key] = (string) $f['id'];
                    }
                }

                return $map;
            });
        } catch (Throwable $e) {
            if ($strict) {
                throw $e;
            }
            $discovered = [];
        }

        return array_map('strval', array_filter([...(array) ($discovered[$model] ?? []), ...$configured]));
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

