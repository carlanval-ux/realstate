<?php

declare(strict_types=1);

namespace App\Rentals\Integration;

use App\Rentals\Models\Occupancy;
use App\Rentals\Models\OccupancyPerson;

/**
 * SES.HOSPEDAJES (Ministerio del Interior, RD 933/2021): bookings, cancellations and check-ins are communicated within
 * 24 h. Only a SIMULATED driver exists (SimulatedSesHospedajes); the real web service needs the landlord code, the
 * establishment code and the web-service user and password (see docs/clarto/alquileres.md).
 */
interface TravellerRegistry
{
    /**
     * Check-in: travellers' data.
     *
     * @param  iterable<OccupancyPerson>  $travellers
     * @return array{driver: string, status: string, reference: ?string, missing: list<string>}
     */
    public function submit(Occupancy $occupancy, iterable $travellers): array;

    /**
     * Booking made or cancelled (event «reserva» or «anulacion»).
     *
     * @return array{driver: string, status: string, reference: ?string}
     */
    public function notify(Occupancy $occupancy, string $event): array;
}
