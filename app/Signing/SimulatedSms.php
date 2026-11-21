<?php

declare(strict_types=1);

namespace App\Signing;

use Illuminate\Support\Str;

/** Default SMS driver: nothing is sent, the message is only recorded in clarto_sms_messages. */
final class SimulatedSms implements SmsGateway
{
    public function name(): string
    {
        return 'simulado';
    }

    public function send(string $to, string $text, string $reference): array
    {
        return ['status' => 'aceptado', 'id' => 'SIM-'.Str::upper(Str::random(8)), 'error' => null];
    }
}
