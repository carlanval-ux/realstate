<?php

declare(strict_types=1);

namespace App\Filament\Resources\CompanyDocumentResource\Pages;

use App\Documents\CompanyDocument;
use App\Documents\CompanyDocumentAnnotation;
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
use Illuminate\Validation\ValidationException;

/**
 * An expediente: data, described files (view / download, both logged), annotation thread, approval (status, progress,
 * votes) and the access log. «Pedir aprobación», «Votar», «Cerrar aprobación» (approved / signed with the signed copy)
 * and «Añadir anotación» live here.
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
            Action::make('annotate')->label(__('documentacion.annotation.button'))->icon('heroicon-o-chat-bubble-left-ellipsis')->color('gray')
                ->schema([
                    Textarea::make('body')->label(__('documentacion.annotation.body'))->required()->maxLength(CompanyDocumentAnnotation::MAX_LENGTH)->rows(4),
                    CompanyDocumentResource::uploadField('file')->label(__('documentacion.annotation.file'))->storeFileNamesIn('file_name')
                        ->helperText(__('documentacion.annotation.file_help', ['mb' => CompanyDocument::MAX_BYTES / 1024 / 1024])),
                ])
                ->action(function (array $data): void {
                    $this->document()->annotate($this->user(), (string) $data['body'],
                        filled($data['file'] ?? null) ? (string) $data['file'] : null, filled($data['file_name'] ?? null) ? (string) $data['file_name'] : null);
                    $this->done(__('documentacion.annotation.saved'));
                }),
            EditAction::make()->modalWidth('3xl')
                ->mutateRecordDataUsing(fn (array $data, CompanyDocument $record): array => ['items' => CompanyDocumentResource::fileItems($record)] + $data)
                ->using(fn (CompanyDocument $record, array $data): CompanyDocument => CompanyDocument::saveFor($this->user(), $record->team_id, CompanyDocumentResource::fromForm($data, $record), $record))
                ->after(fn () => $this->getRecord()->refresh()),
            DeleteAction::make()->modalDescription(__('documentacion.delete_warning')),
        ];
    }

    /** «Borrar» in the annotation thread (mounted from the infolist with the annotation's id): author only, 15 minutes. */
    public function deleteAnnotationAction(): Action
    {
        return Action::make('deleteAnnotation')->requiresConfirmation()->color('danger')
            ->modalHeading(__('documentacion.annotation.delete_heading'))
            ->action(function (array $arguments): void {
                $annotation = $this->document()->annotations()->whereKey((int) ($arguments['annotation'] ?? 0))->first();
                try {
                    $annotation?->deleteBy($this->user());
                } catch (ValidationException $e) {
                    Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();

                    return;
                }
                $this->done(__('documentacion.annotation.deleted'));
            });
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
