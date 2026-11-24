<?php

declare(strict_types=1);

namespace App\Filament\Resources\CompanyDocumentResource\Pages;

use App\Documents\CompanyDocument;
use App\Documents\CompanyDocumentFolder;
use App\Filament\Resources\CompanyDocumentFolderResource;
use App\Filament\Resources\CompanyDocumentResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ManageRecords;

final class ManageCompanyDocuments extends ManageRecords
{
    protected static string $resource = CompanyDocumentResource::class;

    public function mount(): void
    {
        parent::mount();
        CompanyDocumentFolder::ensureDefaults(Filament::getTenant()?->getKey());   // first visit of the company: starting folders
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('documentacion.new'))->icon('heroicon-o-plus')->createAnother(false)->modalWidth('3xl')
                ->using(function (array $data): CompanyDocument {
                    $user = auth()->user();
                    abort_unless($user instanceof User, 403);

                    return CompanyDocument::saveFor($user, Filament::getTenant()?->getKey() ?? $user->current_team_id, $data);
                }),
            Action::make('folders')->label(__('documentacion.folders.menu'))->icon('heroicon-o-folder')->color('gray')
                ->url(fn (): string => CompanyDocumentFolderResource::getUrl()),
        ];
    }
}
