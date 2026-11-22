<?php

declare(strict_types=1);

namespace App\Filament\Resources\CollaboratorResource\Pages;

use App\Auth\AccessLink;
use App\Collaborators\Collaborations;
use App\Collaborators\Collaborator;
use App\Filament\Collaborators\CollaboratorImportUi;
use App\Filament\Collaborators\CollaboratorUi;
use App\Filament\Resources\CollaboratorResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

final class EditCollaborator extends EditRecord
{
    protected static string $resource = CollaboratorResource::class;

    protected function getHeaderActions(): array
    {
        /** @var Collaborator $c */
        $c = $this->getRecord();

        return [
            CollaboratorImportUi::action($c),
            Action::make('invite')->icon('heroicon-o-envelope')
                ->label(fn (): string => $c->invited_at ? __('colaboradores.actions.reinvite') : __('colaboradores.actions.invite'))
                ->visible(fn (): bool => $c->status !== Collaborator::INACTIVE && Collaborations::enabled())
                ->requiresConfirmation()->modalDescription(__('colaboradores.actions.invite_help'))
                ->action(function () use ($c): void {
                    AccessLink::notify(CollaboratorUi::attempt(fn () => Collaborations::invite($c)) ?? ['ok' => false, 'message' => __('colaboradores.module.off')]);
                    $this->refreshFormData(['status']);
                }),
            Action::make('deactivate')->label(__('colaboradores.actions.deactivate'))->icon('heroicon-o-no-symbol')->color('danger')
                ->visible(fn (): bool => $c->status !== Collaborator::INACTIVE)
                ->requiresConfirmation()->modalDescription(__('colaboradores.actions.deactivate_help'))
                ->action(function () use ($c): void {
                    $n = Collaborations::deactivate($c);
                    \Filament\Notifications\Notification::make()->success()->title(__('colaboradores.messages.deactivated', ['n' => $n]))->send();
                    $this->refreshFormData(['status']);
                }),
            Action::make('reactivate')->label(__('colaboradores.actions.reactivate'))->icon('heroicon-o-arrow-uturn-left')->color('gray')
                ->visible(fn (): bool => $c->status === Collaborator::INACTIVE)
                ->action(function () use ($c): void {
                    CollaboratorUi::attempt(fn () => Collaborations::reactivate($c), __('colaboradores.messages.reactivated'));
                    $this->refreshFormData(['status']);
                }),
        ];
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless((int) $record->team_id === CollaboratorUi::teamId(), 404);
        unset($data['import']);   // «Importación de inmuebles» is saved by its own relationship section
        $record->fill($data + ['updated_by' => auth()->id()])->save();
        Collaborator::log($record, 'modificado', ['campos' => array_keys($record->getChanges())]);

        return $record;
    }
}
