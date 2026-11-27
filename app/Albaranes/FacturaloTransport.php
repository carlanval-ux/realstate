<?php

declare(strict_types=1);

namespace App\Albaranes;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Factúralo (the group's billing program, FacturaScripts 2026) through its REST API `/api/3`, the same way
 * Teleco uses it: header `Token`, form-urlencoded bodies, `lineas` as JSON text. Contract and permissions the key
 * needs: docs/clarto/albaranes.md.
 *
 * - Client: our stable code (codcliente CLP + party id), or the client already there with the same NIF.
 * - Albarán: `crearAlbaranCliente` (customer delivery note, «Abierto»: pending invoicing) with numero2 = our number.
 *   That endpoint has no idempotency, so every send first looks it up by numero2 (BillingConnector does it).
 * - Invoice: when staff invoice the albarán in Factúralo, the link is in `doctransformations`
 *   (AlbaranCliente → FacturaCliente); the invoice gives its code and date. Nothing here issues an invoice.
 * - Article with a reference: kept as a product (`productos`, nostock=1: a service) so lines reuse it.
 */
final class FacturaloTransport implements BillingTransport
{
    public function findClient(string $externalRef, ?string $taxId): ?string
    {
        $own = $this->send('get', 'clientes/'.self::clientCode($externalRef));
        if ($own->ok() && filled($own->raw['codcliente'] ?? null)) {
            return (string) $own->raw['codcliente'];
        }
        if (filled($taxId)) {
            $found = $this->send('get', 'clientes', ['filter' => ['cifnif' => strtoupper((string) $taxId)], 'limit' => 1]);
            $first = $found->ok() ? ($found->raw[0] ?? null) : null;
            if (is_array($first) && filled($first['codcliente'] ?? null)) {
                return (string) $first['codcliente'];
            }
        }

        return null;
    }

    public function createClient(array $client, string $idempotencyKey): BillingResult
    {
        $code = self::clientCode((string) $client['externalRef']);
        $result = $this->send('post', 'clientes', array_filter([
            'codcliente' => $code,
            'nombre' => $client['displayName'] ?? null,
            'razonsocial' => $client['displayName'] ?? null,
            'cifnif' => isset($client['document']) ? strtoupper((string) $client['document']) : null,
            'personafisica' => ($client['kind'] ?? 'individual') === 'business' ? '0' : '1',
            'email' => $client['email'] ?? null,
            'telefono1' => $client['phone'] ?? null,
            'observaciones' => isset($client['address']) ? 'Dirección fiscal: '.$client['address'] : null,
        ], fn ($v): bool => filled($v)));

        return $result->ok() ? new BillingResult('ok', $code, httpStatus: $result->httpStatus, raw: $result->raw) : $result;
    }

    public function findDeliveryNote(string $externalRef): ?BillingResult
    {
        $found = $this->send('get', 'albaranclientes', ['filter' => ['numero2' => $externalRef], 'limit' => 1]);
        $first = $found->ok() ? ($found->raw[0] ?? null) : null;

        return is_array($first) && isset($first['idalbaran']) ? $this->getDeliveryNote((string) $first['idalbaran']) : null;
    }

    public function createDeliveryNote(array $note, string $idempotencyKey): BillingResult
    {
        // Factúralo refuses future dates on sales documents: a service dated ahead goes in with today's date.
        $date = min((string) ($note['serviceDate'] ?? today()->toDateString()), today()->toDateString());
        $context = array_filter(['Inmueble' => $note['context']['property'] ?? null, 'Encargo' => $note['context']['mandateId'] ?? null]);
        $notes = trim(implode("\n", array_filter([
            $note['notes'] ?? null,
            $context === [] ? null : implode(' · ', array_map(fn ($k, $v): string => $k.': '.$v, array_keys($context), $context)),
        ])));

        $result = $this->send('post', 'crearAlbaranCliente', array_filter([
            'codcliente' => $note['customerId'],
            'fecha' => $date,
            'numero2' => $note['externalRef'],
            'observaciones' => $notes,
            'codserie' => config('facturador.facturalo.codserie'),
            'codpago' => config('facturador.facturalo.codpago'),
            'codalmacen' => config('facturador.facturalo.codalmacen'),
            'coddivisa' => $note['currency'] ?? 'EUR',
            'lineas' => json_encode(array_map(fn (array $l): array => array_filter([
                'referencia' => $l['code'] ?? null,
                'descripcion' => $l['description'],
                'cantidad' => $l['quantity'],
                'pvpunitario' => number_format((float) $l['unitPrice'], 2, '.', ''),
                'dtopor' => $l['discountPct'] ?? 0,
                'codimpuesto' => self::taxCode((float) ($l['vatPct'] ?? 21)),
            ], fn ($v): bool => $v !== null), $note['lines']), JSON_UNESCAPED_UNICODE),
        ], fn ($v): bool => filled($v)), $idempotencyKey);
        if (! $result->ok()) {
            return $result;
        }
        $doc = (array) ($result->raw['doc'] ?? []);

        return isset($doc['idalbaran'])
            ? new BillingResult('ok', (string) $doc['idalbaran'], 'pending', httpStatus: $result->httpStatus, raw: ['doc' => array_intersect_key($doc, array_flip(['idalbaran', 'codigo', 'numero2', 'fecha', 'neto', 'totaliva', 'total', 'idestado']))])
            : new BillingResult('error', message: __('albaranes.errors.no_note_id'), httpStatus: $result->httpStatus, raw: $result->raw);
    }

    public function getDeliveryNote(string $id): BillingResult
    {
        $note = $this->send('get', 'albaranclientes/'.rawurlencode($id));
        if (! $note->ok()) {
            return $note;
        }
        $raw = ['albaran' => array_intersect_key($note->raw, array_flip(['idalbaran', 'codigo', 'numero2', 'idestado', 'editable', 'total']))];

        // AlbaranCliente → FacturaCliente: the invoice the albarán went into (also when several albaranes were grouped).
        $links = $this->send('get', 'doctransformations', ['filter' => ['model1' => 'AlbaranCliente', 'iddoc1' => $id, 'model2' => 'FacturaCliente'], 'limit' => 1]);
        if (! $links->ok()) {
            return $links;
        }
        $invoiceId = $links->raw[0]['iddoc2'] ?? null;
        if ($invoiceId === null) {
            return new BillingResult('ok', $id, 'pending', httpStatus: $note->httpStatus, raw: $raw);
        }

        $invoice = $this->send('get', 'facturaclientes/'.rawurlencode((string) $invoiceId));
        if (! $invoice->ok()) {
            return $invoice;
        }
        $raw['factura'] = array_intersect_key($invoice->raw, array_flip(['idfactura', 'codigo', 'fecha', 'total', 'pagada', 'editable', 'idestado']));

        return new BillingResult('ok', $id, 'invoiced', [
            'id' => (string) $invoiceId,
            'number' => (string) ($invoice->raw['codigo'] ?? ''),
            'date' => self::isoDate($invoice->raw['fecha'] ?? null),
        ], httpStatus: $invoice->httpStatus, raw: $raw);
    }

    public function ensureProduct(BillingArticle $article): BillingResult
    {
        $fields = [
            'descripcion' => $article->name,
            'precio' => number_format((float) $article->unit_price, 2, '.', ''),
            'codimpuesto' => self::taxCode((float) $article->vat_pct),
            // A service: without stock control Factúralo cannot compute the totals of the line.
            'nostock' => '1',
        ];
        $found = $this->send('get', 'productos', ['filter' => ['referencia' => $article->reference], 'limit' => 1]);
        if (! $found->ok()) {
            return $found;
        }
        $existing = $found->raw[0]['idproducto'] ?? null;
        $result = $existing !== null
            ? $this->send('put', 'productos/'.rawurlencode((string) $existing), $fields)
            : $this->send('post', 'productos', ['referencia' => $article->reference] + $fields);

        $id = $existing ?? ($result->raw['data']['idproducto'] ?? $result->raw['idproducto'] ?? null);

        return $result->ok() ? new BillingResult('ok', $id !== null ? (string) $id : null, httpStatus: $result->httpStatus) : $result;
    }

    /** Stable Factúralo client code from our reference (`clarto-party-12` → `CLP0000012`); codcliente allows 10 alphanumerics. */
    public static function clientCode(string $externalRef): string
    {
        return 'CLP'.str_pad(substr(preg_replace('/\D/', '', $externalRef) ?? '', -7), 7, '0', STR_PAD_LEFT);
    }

    /** VAT % → Factúralo tax code (IVA21, IVA10, IVA4, IVA0). */
    public static function taxCode(float $vatPct): string
    {
        return 'IVA'.rtrim(rtrim(number_format($vatPct, 2, '.', ''), '0'), '.');
    }

    private static function isoDate(mixed $date): ?string
    {
        if (blank($date)) {
            return null;
        }
        // FacturaScripts answers dd-mm-YYYY.
        return preg_match('/^(\d{2})-(\d{2})-(\d{4})/', (string) $date, $m) ? "{$m[3]}-{$m[2]}-{$m[1]}" : substr((string) $date, 0, 10);
    }

    /** @param array<string, mixed> $data */
    private function send(string $method, string $resource, array $data = [], ?string $idempotencyKey = null): BillingResult
    {
        $request = Http::acceptJson()->asForm()
            ->withHeaders(array_filter(['Token' => (string) config('facturador.api_key'), 'Idempotency-Key' => $idempotencyKey]))
            ->timeout((int) config('facturador.timeout', 20));
        $url = rtrim((string) config('facturador.base_url'), '/').'/api/3/'.$resource;

        try {
            /** @var Response $response */
            $response = $method === 'get' ? $request->get($url, $data) : $request->{$method}($url, $data);
        } catch (ConnectionException $e) {
            return new BillingResult('unconfirmed', message: 'Sin respuesta de Factúralo: '.$e->getMessage());
        }

        $body = (array) ($response->json() ?? []);
        $message = $body['message'] ?? $body['error'] ?? null;
        $message = is_string($message) ? $message : null;

        return match (true) {
            $response->serverError() => new BillingResult('error', message: 'Factúralo no está disponible (HTTP '.$response->status().')'.($message ? ': '.$message : ''), httpStatus: $response->status(), raw: $body),
            $response->status() === 404 => new BillingResult('not_found', message: $message ?? 'No encontrado en Factúralo', httpStatus: 404, raw: $body),
            $response->clientError() => new BillingResult('rejected', message: (string) ($message ?: 'Rechazado por Factúralo (HTTP '.$response->status().')'), httpStatus: $response->status(), raw: $body),
            default => new BillingResult('ok', httpStatus: $response->status(), raw: $body),
        };
    }
}
