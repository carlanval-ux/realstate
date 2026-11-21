<?php

declare(strict_types=1);

namespace App\Rentals\Integration;

use App\Rentals\Models\Occupancy;

/**
 * Integration point «log communication» (WhatsApp, SMS or e-mail to tenants, travellers, owners or providers).
 * Local implementation: TableCommunicationLog (Liberu's real_estate_communications, status «recorded»: nothing is
 * sent). After merging feat/whatsapp-waha / formularios (SMS), bind an adapter that sends and logs.
 */
interface CommunicationLog
{
    public function log(Occupancy $occupancy, string $channel, string $purpose, string $to, string $subject, string $body): void;
}
