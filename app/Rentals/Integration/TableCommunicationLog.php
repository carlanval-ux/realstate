<?php

declare(strict_types=1);

namespace App\Rentals\Integration;

use App\Rentals\Models\Occupancy;
use App\Rentals\Occupancies;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Local «log communication»: a «recorded» row in Liberu's real_estate_communications (nothing is sent). */
final class TableCommunicationLog implements CommunicationLog
{
    public function log(Occupancy $occupancy, string $channel, string $purpose, string $to, string $subject, string $body): void
    {
        $connection = $occupancy->getConnectionName();
        if (Schema::connection($connection)->hasTable('real_estate_communications')) {
            DB::connection($connection)->table('real_estate_communications')->insert([
                'team_id' => $occupancy->team_id, 'created_by' => auth()->id(), 'related_type' => Occupancy::class, 'related_id' => (string) $occupancy->getKey(),
                'channel' => $channel, 'direction' => 'outbound', 'subject' => $subject, 'body' => $body, 'to_address' => $to,
                'status' => 'recorded', 'metadata' => json_encode(['finalidad' => $purpose, 'modulo' => $occupancy->module]),
                'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        Occupancies::record($occupancy, 'comunicacion', ['canal' => $channel, 'finalidad' => $purpose, 'destinatario' => $to, 'asunto' => $subject]);
    }
}
