<?php

declare(strict_types=1);

namespace App\Filament\Resources\CompanyDocumentResource\Pages;

use App\Documents\CompanyDocument;
use App\Filament\Resources\CompanyDocumentResource;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/** A document: data, files (view / download, both logged) and the access log; «Editar» adds or removes files. */
final class ViewCompanyDocument extends ViewRecord
{
    protected static string $resource = CompanyDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->modalWidth('3xl')
                ->using(function (CompanyDocument $record, array $data): CompanyDocument {
                    $user = auth()->user();
                    abort_unless($user instanceof User, 403);

                    return CompanyDocument::saveFor($user, $record->team_id, $data, $record);
                })
                ->after(fn () => $this->getRecord()->refresh()),
            DeleteAction::make()->modalDescription(__('documentacion.delete_warning')),
        ];
    }
}
