<?php

declare(strict_types=1);

namespace App\Albaranes;

use Illuminate\Support\Facades\Cache;

/**
 * Local stand-in for the billing program (FACTURADOR_DRIVER=simulado): same contract, state in the cache,
 * honours idempotency keys, never issues invoices (albaranes stay «pending»). Nothing leaves the server.
 */
final class BillingSimulatedTransport implements BillingTransport
{
    public function findClient(string $externalRef, ?string $taxId): ?string
    {
        return Cache::get('facturador-sim:client-ref:'.$externalRef) ?? ($taxId ? Cache::get('facturador-sim:client-doc:'.$taxId) : null);
    }

    public function createClient(array $client, string $idempotencyKey): BillingResult
    {
        if (blank($client['displayName'] ?? null)) {
            return new BillingResult('rejected', message: 'Falta el nombre del cliente (simulado)', errorCode: 'SIM-VALIDACION', httpStatus: 422);
        }
        $id = Cache::rememberForever('facturador-sim:idem:'.$idempotencyKey, fn (): string => 'SIM-C-'.substr(sha1($idempotencyKey), 0, 8));
        Cache::forever('facturador-sim:client-ref:'.$client['externalRef'], $id);
        if (filled($client['document'] ?? null)) {
            Cache::forever('facturador-sim:client-doc:'.$client['document'], $id);
        }

        return new BillingResult('ok', $id, httpStatus: 201);
    }

    public function findDeliveryNote(string $externalRef): ?BillingResult
    {
        $id = Cache::get('facturador-sim:note-ref:'.$externalRef);

        return $id ? $this->getDeliveryNote($id) : null;
    }

    public function createDeliveryNote(array $note, string $idempotencyKey): BillingResult
    {
        if (($note['lines'] ?? []) === []) {
            return new BillingResult('rejected', message: 'El albarán no tiene líneas (simulado)', errorCode: 'SIM-VALIDACION', httpStatus: 422);
        }
        $id = Cache::rememberForever('facturador-sim:idem:'.$idempotencyKey, fn (): string => 'SIM-A-'.substr(sha1($idempotencyKey), 0, 8));
        Cache::forever('facturador-sim:note-ref:'.$note['externalRef'], $id);

        return new BillingResult('ok', $id, 'pending', httpStatus: 201);
    }

    public function getDeliveryNote(string $id): BillingResult
    {
        return new BillingResult('ok', $id, 'pending', httpStatus: 200);
    }

    public function services(): ?array
    {
        return null;
    }
}
