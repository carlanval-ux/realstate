<?php

declare(strict_types=1);

namespace App\Filament\Processes\Resources\Pages;

use App\Filament\Processes\Resources\PositionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

final class ManagePositions extends ManageRecords
{
    protected static string $resource = PositionResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
