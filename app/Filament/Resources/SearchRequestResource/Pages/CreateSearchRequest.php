<?php

declare(strict_types=1);

namespace App\Filament\Resources\SearchRequestResource\Pages;

use App\Filament\Resources\SearchRequestResource;
use App\SearchRequests\SearchRequests;
use Filament\Resources\Pages\CreateRecord;

/** New request = draft. Preview and sending are on its record page. */
final class CreateSearchRequest extends CreateRecord
{
    protected static string $resource = SearchRequestResource::class;

    protected function afterCreate(): void
    {
        $ids = ($this->data['all_active'] ?? false)
            ? array_keys(SearchRequestResource::activeCollaborators())
            : array_map('intval', (array) ($this->data['collaborators'] ?? []));
        SearchRequests::addRecipients($this->getRecord(), $ids, auth()->user());
    }

    protected function getRedirectUrl(): string
    {
        return SearchRequestResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
