<?php

declare(strict_types=1);

namespace App\Albaranes;

/**
 * Confirm & send an albarán to the billing program and read back the invoice it issues.
 *
 * - send(): links the owner to their billing-program client (found by our reference or tax id, else created once,
 *   with an idempotency key) and transmits the albarán's lines as pending invoicing. It never issues an invoice.
 * - A retry never duplicates: the client link is stored once per owner; the albarán carries the same
 *   Idempotency-Key on every attempt, and after an unconfirmed send it is looked up by our number first.
 * - refresh(): asks for the albarán's status; when the billing program has invoiced it, the invoice number and
 *   date are stored and the albarán becomes «Facturado».
 */
final class BillingConnector
{
    public function __construct(private readonly BillingTransport $transport, private readonly string $driver) {}

    public static function make(): self
    {
        $driver = (string) config('facturador.driver', 'simulado');

        return new self($driver === 'api' ? new BillingApiTransport : new BillingSimulatedTransport, $driver);
    }

    /** Real sends need the switch on and credentials; the simulation always works. */
    public function refusal(): ?string
    {
        if ($this->driver !== 'api') {
            return null;
        }

        return match (true) {
            ! config('facturador.enabled') => __('albaranes.errors.disabled'),
            blank(config('facturador.base_url')) || blank(config('facturador.api_key')) => __('albaranes.errors.credentials'),
            default => null,
        };
    }

    /** What stops the albarán from being sent, in the user's words (empty = can be sent). @return list<string> */
    public function missing(Albaran $albaran): array
    {
        return array_values(array_filter([
            $albaran->lines()->count() === 0 ? __('albaranes.missing.lines') : null,
            blank($albaran->client_name) ? __('albaranes.missing.client') : null,
            $albaran->conformity === Albaran::CONFORMITY_PENDING ? __('albaranes.missing.conformity') : null,
        ]));
    }

    public function send(Albaran $albaran): Albaran
    {
        if ($albaran->status === Albaran::INVOICED || ($albaran->status === Albaran::SENT && filled($albaran->external_id))) {
            return $albaran; // already there: nothing to send again
        }
        if (($reason = $this->refusal() ?? (($m = $this->missing($albaran)) !== [] ? implode(' ', $m) : null)) !== null) {
            return $this->fail($albaran, $reason, keepStatus: true);
        }

        $albaran->forceFill(['status' => Albaran::PENDING, 'driver' => $this->driver, 'last_attempt_at' => now()])->save();

        $clientId = $this->clientId($albaran);
        if (! is_string($clientId)) {
            return $this->fail($albaran, $clientId->reason(), $clientId);
        }

        if (blank($albaran->external_id) && ($found = $this->transport->findDeliveryNote($albaran->number)) !== null) {
            // Sent before without confirmation: link it instead of sending a second one.
            return $this->sent($albaran, $found);
        }

        $result = $this->transport->createDeliveryNote($this->payload($albaran, $clientId), $albaran->idempotency_key);

        return match ($result->outcome) {
            'ok' => $this->sent($albaran, $result),
            // No answer: it may have arrived. Stays «pendiente de envío»; the retry looks it up before sending.
            'unconfirmed' => $this->fail($albaran, $result->reason().' '.__('albaranes.errors.unconfirmed'), $result, keepStatus: true),
            default => $this->fail($albaran, $result->reason(), $result),
        };
    }

    public function refresh(Albaran $albaran): Albaran
    {
        if (($reason = $this->refusal()) !== null || blank($albaran->external_id)) {
            return $this->fail($albaran, $reason ?? __('albaranes.errors.not_sent'), keepStatus: true);
        }

        $result = $this->transport->getDeliveryNote((string) $albaran->external_id);
        if (! $result->ok()) {
            return $this->fail($albaran, $result->reason(), $result, keepStatus: true);
        }

        $albaran->forceFill(['last_error' => null, 'last_response' => $result->raw, 'last_attempt_at' => now()]);
        if ($result->invoice !== null) {
            $albaran->forceFill([
                'status' => Albaran::INVOICED,
                'invoice_number' => $result->invoice['number'],
                'invoice_date' => $result->invoice['date'] ? substr((string) $result->invoice['date'], 0, 10) : null,
                'invoice_external_id' => $result->invoice['id'] ?? null,
            ]);
        }
        $albaran->save();

        return $albaran;
    }

    /** Scheduled: reads back invoices for every sent albarán (only when real sends are on). Returns how many got invoiced. */
    public static function syncSent(): int
    {
        $connector = self::make();
        if ($connector->driver !== 'api' || $connector->refusal() !== null) {
            return 0;
        }

        return Albaran::query()->where('status', Albaran::SENT)->whereNotNull('external_id')->get()
            ->filter(fn (Albaran $a): bool => $connector->refresh($a)->status === Albaran::INVOICED)->count();
    }

    /** @return string|BillingResult  the client's id in the billing program, or the failed result */
    private function clientId(Albaran $albaran): string|BillingResult
    {
        $link = BillingClient::query()->where('party_id', $albaran->party_id)->first();
        if ($link !== null && $link->driver === $this->driver) {
            return (string) $link->external_id;
        }

        $ref = 'clarto-party-'.$albaran->party_id;
        $id = $this->transport->findClient($ref, $albaran->client_tax_id);
        if ($id === null) {
            $result = $this->transport->createClient(array_filter([
                'externalRef' => $ref,
                'kind' => self::isCompany($albaran->client_tax_id) ? 'business' : 'individual',
                'displayName' => $albaran->client_name,
                'document' => $albaran->client_tax_id,
                'email' => $albaran->client_email,
                'phone' => $albaran->client_phone,
                'address' => $albaran->client_address,
            ], fn ($v): bool => filled($v)), $ref);
            if (! $result->ok() || blank($result->id)) {
                return $result->ok() ? new BillingResult('error', message: __('albaranes.errors.no_client_id')) : $result;
            }
            $id = (string) $result->id;
        }

        BillingClient::query()->updateOrCreate(['party_id' => $albaran->party_id], ['external_id' => $id, 'driver' => $this->driver]);

        return $id;
    }

    /** @return array<string, mixed> */
    private function payload(Albaran $albaran, string $clientId): array
    {
        return array_filter([
            'customerId' => $clientId,
            'externalRef' => $albaran->number,
            'serviceDate' => $albaran->service_date?->toDateString(),
            'currency' => 'EUR',
            'notes' => $albaran->notes,
            // Amounts before taxes: the billing program decides tax treatment and numbering when it invoices.
            'lines' => $albaran->lines()->get()->map(fn (AlbaranLine $l): array => array_filter([
                'code' => $l->service_code,
                'description' => $l->description,
                'quantity' => (float) $l->quantity,
                'unitPrice' => (float) $l->unit_price,
                'discountPct' => (float) $l->discount_pct,
            ], fn ($v): bool => $v !== null))->all(),
            'context' => array_filter([
                'property' => $albaran->property?->reference(),
                'mandateId' => $albaran->instruction_id,
            ]),
        ], fn ($v): bool => $v !== null && $v !== []);
    }

    private function sent(Albaran $albaran, BillingResult $result): Albaran
    {
        $albaran->forceFill([
            'status' => Albaran::SENT, 'external_id' => $result->id, 'sent_at' => $albaran->sent_at ?? now(),
            'last_error' => null, 'last_response' => $result->raw ?: ['outcome' => $result->outcome, 'http' => $result->httpStatus],
            'last_attempt_at' => now(), 'driver' => $this->driver,
        ])->save();

        return $result->invoice !== null ? $this->refresh($albaran) : $albaran;
    }

    private function fail(Albaran $albaran, string $reason, ?BillingResult $result = null, bool $keepStatus = false): Albaran
    {
        $albaran->forceFill(array_filter([
            'status' => $keepStatus ? null : Albaran::ERROR,
            'last_error' => $reason,
            'last_response' => $result ? ($result->raw ?: ['outcome' => $result->outcome, 'http' => $result->httpStatus, 'message' => $result->message]) : null,
            'last_attempt_at' => now(),
            'driver' => $this->driver,
        ], fn ($v): bool => $v !== null))->save();

        return $albaran;
    }

    private static function isCompany(?string $taxId): bool
    {
        return (bool) preg_match('/^[ABCDEFGHJNPQRSUVW]/', strtoupper((string) $taxId));
    }
}
