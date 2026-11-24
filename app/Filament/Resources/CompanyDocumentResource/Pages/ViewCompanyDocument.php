<?php

declare(strict_types=1);

namespace App\Filament\Resources\CompanyDocumentResource\Pages;

use App\Documents\CompanyDocument;
use App\Filament\Resources\CompanyDocumentResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

/**
 * A document: data, files (view / download, both logged), approval (status, progress, votes) and the access log.
 * «Pedir aprobación», «Votar» and «Cerrar aprobación» (approved / signed with the signed copy) live here.
 */
final class ViewCompanyDocument extends ViewRecord
{
    protected static string $resource = CompanyDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('request')->label(__('documentacion.request.button'))->icon('heroicon-o-hand-raised')->color('warning')
                ->visible(fn (): bool => $this->document()->status !== CompanyDocument::PENDING)
                ->modalDescription(fn (): string => __('documentacion.request.help', ['voters' => $this->votersFor()]))
                ->schema([
                    TextInput::make('approvals_required')->label(__('documentacion.fields.approvals_required'))->integer()->required()
                        ->minValue(1)->maxValue(fn (): int => max(1, $this->votersFor()))->default(fn (): int => max(1, $this->votersFor())),
                ])
                ->action(function (array $data): void {
                    $this->document()->requestApproval($this->user(), (int) $data['approvals_required']);
                    $this->done(__('documentacion.request.sent'));
                }),
            Action::make('vote')->label(__('documentacion.vote.button'))->icon('heroicon-o-check-badge')
                ->visible(fn (): bool => $this->document()->status === CompanyDocument::PENDING
                    && (string) $this->document()->review_requested_by !== (string) auth()->id())
                ->fillForm(fn (): array => ($v = $this->document()->votes()->where('user_id', auth()->id())->first()) ? ['approved' => $v->approved ? '1' : '0', 'comment' => $v->comment] : [])
                ->schema([
                    Radio::make('approved')->label(__('documentacion.vote.question'))->required()
                        ->options(['1' => __('documentacion.vote_yes'), '0' => __('documentacion.vote_no')]),
                    Textarea::make('comment')->label(__('documentacion.vote.comment'))->maxLength(2000)->rows(3),
                ])
                ->action(function (array $data): void {
                    $this->document()->vote($this->user(), (string) $data['approved'] === '1', $data['comment'] ?? null);
                    $this->done(__('documentacion.vote.saved'));
                }),
            Action::make('close')->label(__('documentacion.close.button'))->icon('heroicon-o-document-check')->color('success')
                ->visible(fn (): bool => in_array($this->document()->status, [CompanyDocument::PENDING, CompanyDocument::APPROVED], true))
                ->modalDescription(fn (): string => (string) $this->document()->approvalProgress())
                ->schema([
                    Select::make('status')->label(__('documentacion.fields.status'))->required()->native(false)->live()
                        ->options(fn (): array => array_filter([
                            CompanyDocument::APPROVED => $this->document()->status === CompanyDocument::PENDING ? CompanyDocument::statusLabel(CompanyDocument::APPROVED) : null,
                            CompanyDocument::SIGNED => CompanyDocument::statusLabel(CompanyDocument::SIGNED),
                        ])),
                    CompanyDocumentResource::filesField()->label(__('documentacion.close.signed_copy'))
                        ->required(fn ($get): bool => $get('status') === CompanyDocument::SIGNED),
                ])
                ->action(function (array $data): void {
                    $this->document()->changeStatus($this->user(), (string) $data['status'], array_values((array) ($data['new_files'] ?? [])), (array) ($data['new_file_names'] ?? []));
                    $this->done(__('documentacion.close.saved'));
                }),
            EditAction::make()->modalWidth('3xl')
                ->using(fn (CompanyDocument $record, array $data): CompanyDocument => CompanyDocument::saveFor($this->user(), $record->team_id, $data, $record))
                ->after(fn () => $this->getRecord()->refresh()),
            DeleteAction::make()->modalDescription(__('documentacion.delete_warning')),
        ];
    }

    private function document(): CompanyDocument
    {
        /** @var CompanyDocument */
        return $this->getRecord();
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /** Responsables who would vote if the current user asked for approval. */
    private function votersFor(): int
    {
        return CompanyDocument::responsables($this->document()->team_id)->reject(fn (User $u): bool => (string) $u->getKey() === (string) auth()->id())->count();
    }

    private function done(string $message): void
    {
        Notification::make()->title($message)->success()->send();
        $this->getRecord()->refresh();
    }
}
