<?php

declare(strict_types=1);

namespace App\Albaranes;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * HTTP client of the billing program, following the conventions of the DEALER public API v1 (Bearer key,
 * `{data: …}` envelope, `{error: {code, message}}` errors, `Idempotency-Key` on writes, `cursor/limit` lists).
 *
 * DEALER today only reads customers and invoices: the write endpoints below (POST customers, delivery-notes,
 * GET catalog/services, the `document`/`externalRef` filters) are the contract we PROPOSE; docs/clarto/albaranes.md
 * lists them. Nothing here issues an invoice.
 */
final class BillingApiTransport implements BillingTransport
{
    public function findClient(string $externalRef, ?string $taxId): ?string
    {
        foreach (array_filter(['externalRef' => $externalRef, 'document' => $taxId]) as $filter => $value) {
            $result = $this->send(fn (PendingRequest $h) => $h->get($this->url('customers'), [$filter => $value, 'limit' => 1]));
            $first = $result->ok() ? ($result->raw['data'][0] ?? null) : null;
            if (is_array($first) && isset($first['id'])) {
                return (string) $first['id'];
            }
        }

        return null;
    }

    public function createClient(array $client, string $idempotencyKey): BillingResult
    {
        return $this->send(fn (PendingRequest $h) => $h->withHeaders(['Idempotency-Key' => $idempotencyKey])->post($this->url('customers'), $client));
    }

    public function findDeliveryNote(string $externalRef): ?BillingResult
    {
        $result = $this->send(fn (PendingRequest $h) => $h->get($this->url('delivery-notes'), ['externalRef' => $externalRef, 'limit' => 1]));
        $first = $result->ok() ? ($result->raw['data'][0] ?? null) : null;

        return is_array($first) && isset($first['id']) ? self::note($first, $result->httpStatus) : null;
    }

    public function createDeliveryNote(array $note, string $idempotencyKey): BillingResult
    {
        return $this->send(fn (PendingRequest $h) => $h->withHeaders(['Idempotency-Key' => $idempotencyKey])->post($this->url('delivery-notes'), $note));
    }

    public function getDeliveryNote(string $id): BillingResult
    {
        return $this->send(fn (PendingRequest $h) => $h->get($this->url('delivery-notes/'.rawurlencode($id))));
    }

    public function services(): ?array
    {
        $result = $this->send(fn (PendingRequest $h) => $h->get($this->url('catalog/services'), ['limit' => 200]));
        if (! $result->ok() || ! is_array($result->raw['data'] ?? null)) {
            return null;
        }
        $out = [];
        foreach ($result->raw['data'] as $s) {
            if (is_array($s) && filled($s['code'] ?? null)) {
                $out[(string) $s['code']] = ['nombre' => (string) ($s['name'] ?? $s['code']), 'precio' => (float) ($s['unitPrice'] ?? 0), 'conformidad' => (bool) ($s['requiresConformity'] ?? false)];
            }
        }

        return $out;
    }

    private function url(string $path): string
    {
        return rtrim((string) config('facturador.base_url'), '/').'/'.$path;
    }

    /** @param array<string, mixed> $data */
    private static function note(array $data, int $http, array $raw = []): BillingResult
    {
        $invoice = is_array($data['invoice'] ?? null) && filled($data['invoice']['number'] ?? null)
            ? ['id' => isset($data['invoice']['id']) ? (string) $data['invoice']['id'] : null, 'number' => (string) $data['invoice']['number'], 'date' => $data['invoice']['issueDate'] ?? null]
            : null;

        return new BillingResult('ok', isset($data['id']) ? (string) $data['id'] : null, isset($data['status']) ? (string) $data['status'] : null, $invoice, httpStatus: $http, raw: $raw ?: $data);
    }

    /** @param callable(PendingRequest): Response $call */
    private function send(callable $call): BillingResult
    {
        try {
            $response = $call(Http::acceptJson()->asJson()->withToken((string) config('facturador.api_key'))->timeout((int) config('facturador.timeout', 20)));
        } catch (ConnectionException $e) {
            return new BillingResult('unconfirmed', message: 'Sin respuesta del programa de facturación: '.$e->getMessage());
        }

        $body = (array) ($response->json() ?? []);
        $error = (array) ($body['error'] ?? []);
        $message = $error['message'] ?? $body['message'] ?? null;
        $code = isset($error['code']) ? (string) $error['code'] : null;

        if ($response->serverError()) {
            return new BillingResult('error', message: 'El programa de facturación no está disponible (HTTP '.$response->status().')'.($message ? ': '.$message : ''), errorCode: $code, httpStatus: $response->status(), raw: $body);
        }
        if ($response->clientError()) {
            return new BillingResult('rejected', message: (string) ($message ?: 'Rechazado por el programa de facturación (HTTP '.$response->status().')'), errorCode: $code, httpStatus: $response->status(), raw: $body);
        }

        $data = $body['data'] ?? $body;

        return is_array($data) && ! array_is_list($data)
            ? self::note($data, $response->status(), $body)
            : new BillingResult('ok', httpStatus: $response->status(), raw: $body);
    }
}
