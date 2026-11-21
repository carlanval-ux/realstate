<?php

declare(strict_types=1);

namespace App\Filament\Resources\CollaboratorResource\Pages;

use App\Filament\Resources\CollaboratorResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListCollaborators extends ListRecords
{
    protected static string $resource = CollaboratorResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
