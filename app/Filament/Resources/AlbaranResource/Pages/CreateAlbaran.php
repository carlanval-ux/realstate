<?php

declare(strict_types=1);

namespace App\Filament\Resources\AlbaranResource\Pages;

use App\Filament\Resources\AlbaranResource;
use Filament\Resources\Pages\CreateRecord;

/** Saved as «Borrador»: sending to the billing program is a separate, explicit «Confirm and send». */
final class CreateAlbaran extends CreateRecord
{
    protected static string $resource = AlbaranResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $data + ['created_by' => auth()->id(), 'team_id' => \Filament\Facades\Filament::getTenant()?->getKey() ?? auth()->user()?->current_team_id];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
