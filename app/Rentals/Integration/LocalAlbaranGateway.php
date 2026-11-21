<?php

declare(strict_types=1);

namespace App\Rentals\Integration;

use App\Rentals\Models\Occupancy;
use App\Rentals\Occupancies;

/** Local «create albarán»: the request is kept in the occupancy history with its lines and total. */
final class LocalAlbaranGateway implements AlbaranGateway
{
    public function request(Occupancy $occupancy, ?int $partyId, array $lines, string $reference): string
    {
        $total = round(array_sum(array_map(fn (array $l): float => (float) $l['quantity'] * (float) $l['unit_price'], $lines)), 2);
        $number = 'ALB-ALQ-'.$occupancy->getKey().'-'.substr(sha1($reference), 0, 8);
        Occupancies::record($occupancy, 'albaran_solicitado', ['numero' => $number, 'referencia' => $reference, 'party_id' => $partyId, 'lineas' => $lines, 'total' => $total, 'driver' => 'local']);

        return $number;
    }
}
