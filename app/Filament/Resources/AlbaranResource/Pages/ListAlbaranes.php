<?php

declare(strict_types=1);

namespace App\Filament\Resources\AlbaranResource\Pages;

use App\Filament\Resources\AlbaranResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListAlbaranes extends ListRecords
{
    protected static string $resource = AlbaranResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
