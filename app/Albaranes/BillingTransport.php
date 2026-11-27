<?php

declare(strict_types=1);

namespace App\Albaranes;

/** Calls to the billing program: Factúralo (FacturaloTransport) or the local simulation. */
interface BillingTransport
{
    /** Client already in the billing program: by our reference first, then by tax id. Null = not found. */
    public function findClient(string $externalRef, ?string $taxId): ?string;

    /** @param array<string, mixed> $client */
    public function createClient(array $client, string $idempotencyKey): BillingResult;

    /** Albarán sent earlier (e.g. after a timeout), found by our number, to avoid a duplicate. */
    public function findDeliveryNote(string $externalRef): ?BillingResult;

    /** @param array<string, mixed> $note  lines pending invoicing; never issues an invoice */
    public function createDeliveryNote(array $note, string $idempotencyKey): BillingResult;

    /** Status of a sent albarán, with the invoice once the billing program issued it. */
    public function getDeliveryNote(string $id): BillingResult;

    /** Keeps a hand-made article with a reference as a product of the billing program; id = its product id. */
    public function ensureProduct(BillingArticle $article): BillingResult;
}
