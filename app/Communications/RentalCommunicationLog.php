<?php

declare(strict_types=1);

namespace App\Communications;

use App\Rentals\Integration\CommunicationLog;
use App\Rentals\Models\Occupancy;
use App\Rentals\Occupancies;
use App\Rentals\RentalRefused;
use App\WhatsApp\Phone;
use Illuminate\Support\Str;
use Liberu\RealEstate\Parties\Models\Party;
use RuntimeException;

/**
 * Alquileres' «log communication» integration point, now real: the message is SENT on its channel and lands in the
 * client's timeline (clarto_communications), and the occupancy history keeps its «comunicacion» event. A delivery
 * problem (line not connected, provider error) is logged as «Not sent» and never breaks the rental flow.
 */
final class RentalCommunicationLog implements CommunicationLog
{
    public function log(Occupancy $occupancy, string $channel, string $purpose, string $to, string $subject, string $body): void
    {
        try {
            $message = Communications::send($occupancy->team_id, $this->party($occupancy, $to), $channel, $to, $body, [
                'subject' => $subject, 'purpose' => $purpose, 'source' => 'alquileres', 'related' => $occupancy, 'user_id' => auth()->id(),
            ]);
        } catch (RuntimeException $e) {
            throw new RentalRefused($e->getMessage(), previous: $e);
        }

        Occupancies::record($occupancy, 'comunicacion', [
            'canal' => $channel, 'finalidad' => $purpose, 'destinatario' => $to, 'asunto' => $subject,
            'estado' => $message->status, 'comunicacion' => $message->getKey(),
        ]);
    }

    /** The client: the occupancy person with that phone/e-mail linked to a contact, else the team's only contact with it. */
    private function party(Occupancy $occupancy, string $to): ?Party
    {
        $phone = Phone::e164($to);
        $email = str_contains($to, '@') ? Str::lower(trim($to)) : null;
        $person = $occupancy->people()->whereNotNull('party_id')->get()
            ->first(fn ($p): bool => ($phone !== null && Phone::e164($p->phone) === $phone) || ($email !== null && Str::lower((string) $p->email) === $email));
        $partyId = $person?->party_id ?? Communications::partyFor($occupancy->team_id, $phone, $email);

        return $partyId ? Party::query()->forTeam($occupancy->team_id)->find($partyId) : null;
    }
}
