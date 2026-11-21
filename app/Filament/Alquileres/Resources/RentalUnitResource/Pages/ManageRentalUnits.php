<?php

declare(strict_types=1);

namespace App\Filament\Alquileres\Resources\RentalUnitResource\Pages;

use App\Filament\Alquileres\Resources\RentalUnitResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

final class ManageRentalUnits extends ManageRecords
{
    protected static string $resource = RentalUnitResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
