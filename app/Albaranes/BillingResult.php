<?php

declare(strict_types=1);

namespace App\Albaranes;

/** Outcome of one call to the billing program, normalised. */
final class BillingResult
{
    /**
     * @param  'ok'|'rejected'|'unconfirmed'|'error'  $outcome
     * @param  array{id?: ?string, number?: ?string, date?: ?string}|null  $invoice  set once the billing program issued it
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $outcome,
        public readonly ?string $id = null,
        public readonly ?string $status = null,
        public readonly ?array $invoice = null,
        public readonly ?string $message = null,
        public readonly ?string $errorCode = null,
        public readonly int $httpStatus = 0,
        public readonly array $raw = [],
    ) {}

    public function ok(): bool
    {
        return $this->outcome === 'ok';
    }

    public function reason(): string
    {
        return trim(($this->errorCode ? "[{$this->errorCode}] " : '').($this->message ?? 'Error'));
    }
}
