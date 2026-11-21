<?php

declare(strict_types=1);

namespace App\Signing;

/** Individual professional SMS (no bulk, no marketing). Today: Red Telecom's dealer API or a simulation. */
interface SmsGateway
{
    public function name(): string;

    /**
     * @param  string  $to  international number without "+" (34600000000)
     * @return array{status: 'aceptado'|'rechazado'|'error', id: ?string, error: ?string}
     */
    public function send(string $to, string $text, string $reference): array;
}
