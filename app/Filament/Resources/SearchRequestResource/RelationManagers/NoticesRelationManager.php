<?php

declare(strict_types=1);

namespace App\Filament\Resources\SearchRequestResource\RelationManagers;

use App\Collaborators\CollaboratorNotice;
use App\SearchRequests\SearchRequests;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Throwable;

/** «Envíos»: copy of every message sent (request, updates, clarifications) and its result per channel; retry failures. */
final class NoticesRelationManager extends RelationManager
{
    protected static string $relationship = 'notices';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('solicitudes.seccion.deliveries');
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        $f = fn (string $k): string => __('solicitudes.campo.'.$k);
        $color = fn (?string $state): string => ['enviado' => 'success', 'error' => 'danger', 'parcial' => 'warning', 'cancelado' => 'gray', 'pendiente' => 'info'][$state] ?? 'gray';

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('collaborator'))
            ->columns([
                TextColumn::make('created_at')->label($f('sent_at'))->dateTime('d/m/Y H:i', 'Europe/Madrid')->sortable(),
                TextColumn::make('collaborator.name')->label($f('collaborator')),
                TextColumn::make('email_subject')->label($f('message'))->limit(60)->wrap(),
                TextColumn::make('status')->label($f('status'))->badge()->color($color),
                TextColumn::make('email_status')->label(__('solicitudes.canal.email'))->badge()->color($color)->placeholder('—'),
                TextColumn::make('whatsapp_status')->label('WhatsApp')->badge()->color($color)->placeholder('—'),
                TextColumn::make('attempts')->label('#'),
                TextColumn::make('last_error')->label(__('Error'))->limit(60)->wrap()->placeholder('—'),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                Action::make('copy')->label(__('View'))->icon('heroicon-o-eye')->color('gray')->modalSubmitAction(false)->modalWidth('4xl')
                    ->modalHeading(fn (CollaboratorNotice $record) => $record->email_subject)
                    ->schema(fn (CollaboratorNotice $record): array => [TextEntry::make('c')->hiddenLabel()->state(new HtmlString(
                        '<div style="white-space:pre-wrap;border:1px solid rgba(127,127,127,.35);border-radius:.5rem;padding:1rem;margin-bottom:1rem">'.e((string) $record->email_body).'</div>'
                        .collect($record->whatsapp_blocks ?? [])->map(fn ($b) => '<div style="white-space:pre-wrap;background:#dcf8c6;color:#111;border-radius:.5rem;padding:.6rem .8rem;margin-bottom:.5rem">'.e($b).'</div>')->implode('')
                    ))]),
                Action::make('retry')->label(__('solicitudes.accion.retry'))->icon('heroicon-o-arrow-path')->color('warning')
                    ->visible(fn (CollaboratorNotice $record): bool => $record->isOpen() && SearchRequests::enabled())
                    ->action(function (CollaboratorNotice $record): void {
                        try {
                            $n = SearchRequests::retry($record, auth()->user());
                            Notification::make()->title(__('solicitudes.aviso.retried', ['status' => $n->status]))->body($n->last_error)->{$n->status === 'enviado' ? 'success' : 'warning'}()->send();
                        } catch (Throwable $e) {
                            report($e);
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),
            ]);
    }
}
