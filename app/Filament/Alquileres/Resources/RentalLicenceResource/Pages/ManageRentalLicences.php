<?php

declare(strict_types=1);

namespace App\Filament\Alquileres\Resources\RentalLicenceResource\Pages;

use App\Filament\Alquileres\Resources\RentalLicenceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

final class ManageRentalLicences extends ManageRecords
{
    protected static string $resource = RentalLicenceResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
