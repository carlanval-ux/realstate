<?php

declare(strict_types=1);

namespace App\Filament\Processes\Resources\Pages;

use App\Filament\Processes\Resources\AreaResource;
use App\Filament\Processes\Resources\PositionAssignmentResource;
use App\Filament\Processes\Resources\PositionResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

final class ManageAreas extends ManageRecords
{
    protected static string $resource = AreaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            Action::make('puestos')->label(__('procesos.estructura.puestos'))->color('gray')->url(fn (): string => PositionResource::getUrl()),
            Action::make('asignaciones')->label(__('procesos.estructura.asignaciones'))->color('gray')->url(fn (): string => PositionAssignmentResource::getUrl()),
        ];
    }
}
