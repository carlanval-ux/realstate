<?php

declare(strict_types=1);

namespace App\Filament\Alquileres\Resources\OccupancyResource\Pages;

use App\Filament\Alquileres\RentalUi;
use App\Filament\Alquileres\Resources\OccupancyResource;
use App\Rentals\Models\RentalUnit;
use App\Rentals\Occupancies;
use App\Rentals\RentalFlows;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/** New contract, booking or block: confirmed at once through the occupancy lock (dates taken → refused). */
final class CreateOccupancy extends CreateRecord
{
    protected static string $resource = OccupancyResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $unit = RentalUnit::query()->where('team_id', RentalUi::team())->findOrFail($data['unit_id']);
        $data['utilities'] = RentalUi::utilitiesFromForm($data['utilities'] ?? null) ?: null;
        if ($data['module'] === 'vacacional') {
            $data['purpose'] = $data['kind'] === 'bloqueo' ? null : 'turistica';
        }

        return RentalUi::attempt(fn () => $data['module'] === 'vacacional' && $data['kind'] === 'reserva'
            ? RentalFlows::bookHoliday($unit, $data['planned_start'], $data['planned_end'], $data['channel'], [], $data['channel_ref'] ?? null, (int) $data['occupants'])
            : Occupancies::book(array_filter($data, fn ($v): bool => $v !== null), [], null, $unit));
    }

    protected function getRedirectUrl(): string
    {
        return OccupancyResource::getUrl('view', ['record' => $this->getRecord()]);
    }

    protected function getCreatedNotification(): ?\Filament\Notifications\Notification
    {
        return null; // RentalUi::attempt already said it
    }
}
