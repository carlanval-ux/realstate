<?php

declare(strict_types=1);

namespace App\Filament\Resources\SearchRequestResource\Pages;

use App\Filament\Resources\SearchRequestResource;
use Filament\Resources\Pages\EditRecord;

/** Changing a sent request does not send anything: «Enviar actualización» on its page does, keeping the history. */
final class EditSearchRequest extends EditRecord
{
    protected static string $resource = SearchRequestResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $data + ['updated_by' => auth()->id()];
    }

    protected function getRedirectUrl(): string
    {
        return SearchRequestResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
