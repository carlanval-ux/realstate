<?php

declare(strict_types=1);

namespace App\Filament\Resources\ImprovementRequestResource\Pages;

use App\Filament\Resources\ImprovementRequestResource;
use App\Models\ImprovementRequest;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

/** A thread: the opening post and every reply (text + photos) and status change, with «Responder». */
final class ViewImprovementRequest extends ViewRecord
{
    protected static string $resource = ImprovementRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reply')->label(__('clarto.mejoras.respond'))->icon('heroicon-o-chat-bubble-left-ellipsis')
                ->visible(fn (): bool => (bool) auth()->user()?->can('reply', $this->getRecord()))
                ->modalWidth('2xl')
                ->fillForm(fn (): array => ['status' => $this->getRecord()->status])
                ->schema([
                    Textarea::make('body')->label(__('clarto.mejoras.fields.reply'))->maxLength(5000)->rows(5),
                    ImprovementRequestResource::photosField(),
                    Select::make('status')->label(__('clarto.mejoras.fields.status'))->options(ImprovementRequest::statusOptions())->required()->native(false)
                        ->helperText(__('clarto.mejoras.status_help'))
                        ->visible(fn (): bool => (bool) auth()->user()?->can('update', $this->getRecord())),
                ])
                ->action(function (array $data): void {
                    /** @var ImprovementRequest $record */
                    $record = $this->getRecord();
                    $event = $record->reply(auth()->user(), $data['body'] ?? null, array_values((array) ($data['attachments'] ?? [])),
                        (array) ($data['attachment_names'] ?? []), $data['status'] ?? null);
                    $event === null
                        ? Notification::make()->title(__('clarto.mejoras.empty'))->warning()->send()
                        : Notification::make()->title(__('clarto.mejoras.saved'))->success()->send();
                    $record->refresh(); // the thread shows the new reply and status
                }),
        ];
    }
}
