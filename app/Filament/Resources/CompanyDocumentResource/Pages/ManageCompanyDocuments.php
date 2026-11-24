<?php

declare(strict_types=1);

namespace App\Filament\Resources\CompanyDocumentResource\Pages;

use App\Documents\CompanyDocument;
use App\Filament\Resources\CompanyDocumentResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;

final class ManageCompanyDocuments extends ManageRecords
{
    protected static string $resource = CompanyDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('documentacion.new'))->icon('heroicon-o-plus')->createAnother(false)->modalWidth('3xl')
                ->using(function (array $data): CompanyDocument {
                    $user = auth()->user();
                    abort_unless($user instanceof User, 403);

                    return CompanyDocument::saveFor($user, Filament::getTenant()?->getKey() ?? $user->current_team_id, $data);
                }),
            // Who else (besides the administrators) sees this screen: the «documentacion.ver» permission in this company.
            Action::make('access')->label(__('documentacion.access.button'))->icon('heroicon-o-key')->color('gray')
                ->visible(fn (): bool => CompanyDocument::canManageAccess(auth()->user(), Filament::getTenant()?->getKey()))
                ->modalDescription(__('documentacion.access.help'))
                ->fillForm(fn (): array => ['users' => CompanyDocument::holders(Filament::getTenant()?->getKey() ?? 0)->all()])
                ->schema([
                    CheckboxList::make('users')->label(__('documentacion.access.users'))
                        ->options(fn (): array => CompanyDocument::staffMembers(Filament::getTenant()?->getKey() ?? 0)->all())
                        ->searchable()->columns(2),
                ])
                ->action(function (array $data): void {
                    $user = auth()->user();
                    abort_unless($user instanceof User, 403);
                    CompanyDocument::grantDirectAccess($user, Filament::getTenant()?->getKey() ?? 0, array_values((array) ($data['users'] ?? [])));
                    Notification::make()->title(__('documentacion.access.saved'))->success()->send();
                }),
        ];
    }
}
