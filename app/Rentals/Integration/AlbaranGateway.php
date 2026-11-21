<?php

declare(strict_types=1);

namespace App\Rentals\Integration;

use App\Rentals\Models\Occupancy;

/**
 * Integration point «create albarán» (CLARTO services that must reach invoicing). Local implementation:
 * LocalAlbaranGateway. After merging feat/albaranes-facturacion, bind an adapter that creates an App\Albaranes\Albaran.
 */
interface AlbaranGateway
{
    /**
     * @param  list<array{description: string, quantity: float|int, unit_price: float|int, tipo?: string}>  $lines
     * @return string the albarán reference
     */
    public function request(Occupancy $occupancy, ?int $partyId, array $lines, string $reference): string;
}
