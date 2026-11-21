<?php

declare(strict_types=1);

namespace App\Filament\Processes\Resources\Pages;

use App\Filament\Processes\Resources\PositionAssignmentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

final class ManagePositionAssignments extends ManageRecords
{
    protected static string $resource = PositionAssignmentResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
