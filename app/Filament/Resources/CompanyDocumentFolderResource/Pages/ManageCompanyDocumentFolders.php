<?php

declare(strict_types=1);

namespace App\Filament\Resources\CompanyDocumentFolderResource\Pages;

use App\Documents\CompanyDocumentFolder;
use App\Filament\Resources\CompanyDocumentFolderResource;
use App\Filament\Resources\CompanyDocumentResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ManageRecords;

final class ManageCompanyDocumentFolders extends ManageRecords
{
    protected static string $resource = CompanyDocumentFolderResource::class;

    public function mount(): void
    {
        parent::mount();
        CompanyDocumentFolder::ensureDefaults(Filament::getTenant()?->getKey());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('documents')->label(__('documentacion.menu'))->icon('heroicon-o-arrow-left')->color('gray')
                ->url(fn (): string => CompanyDocumentResource::getUrl()),
            CreateAction::make()->label(__('documentacion.folders.new'))->icon('heroicon-o-folder-plus')->createAnother(false),
        ];
    }
}
