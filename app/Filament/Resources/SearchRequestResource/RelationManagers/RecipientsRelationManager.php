<?php

declare(strict_types=1);

namespace App\Filament\Resources\SearchRequestResource\RelationManagers;

use App\Models\Communication;
use App\SearchRequests\SearchRequest;
use App\SearchRequests\SearchRequestRecipient;
use App\SearchRequests\SearchRequests;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/** Individual follow-up: what each collaborator received and answered. Receipts are shown apart: they are not answers. */
final class RecipientsRelationManager extends RelationManager
{
    protected static string $relationship = 'recipients';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('solicitudes.seccion.recipients');
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        $f = fn (string $k): string => __('solicitudes.campo.'.$k);

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('collaborator'))
            ->description(__('solicitudes.ayuda.receipts'))
            ->columns([
                TextColumn::make('collaborator.name')->label($f('collaborator'))->weight('bold'),
                TextColumn::make('status')->label($f('status'))->badge()
                    ->formatStateUsing(fn (string $state) => SearchRequest::label('destinatario', $state))
                    ->color(fn (string $state) => ['respondida' => 'success', 'enviada' => 'info', 'retirada' => 'danger'][$state] ?? 'gray'),
                TextColumn::make('last_response')->label($f('kind'))->formatStateUsing(fn (?string $state) => SearchRequest::label('respuesta', $state))->placeholder('—'),
                TextColumn::make('receipt')->label($f('receipt'))->state(fn (SearchRequestRecipient $record): string => self::receipt($record)),
                TextColumn::make('opens')->label(__('solicitudes.extra.opens'))
                    ->state(fn (SearchRequestRecipient $record): int => (int) \App\SearchRequests\SearchRequestLink::query()->where('recipient_id', $record->getKey())->sum('opens')),
                TextColumn::make('sent_version')->label($f('version'))->placeholder('—'),
                TextColumn::make('sent_at')->label($f('sent_at'))->dateTime('d/m/Y H:i', 'Europe/Madrid')->placeholder('—'),
                TextColumn::make('responded_at')->label($f('received'))->dateTime('d/m/Y H:i', 'Europe/Madrid')->placeholder('—'),
            ])
            ->recordActions([
                Action::make('ask')->label(__('solicitudes.accion.ask'))->icon('heroicon-o-question-mark-circle')
                    ->visible(fn (SearchRequestRecipient $record): bool => $record->wasSent() && SearchRequests::enabled())
                    ->schema([Textarea::make('question')->label($f('question'))->required()->rows(4)->maxLength(2000)])
                    ->action(fn (SearchRequestRecipient $record, array $data) => self::attempt(fn () => SearchRequests::send($this->getOwnerRecord(), 'aclaracion', [$record->getKey()], auth()->user(), $data['question']))),
                Action::make('revoke')->label(__('solicitudes.extra.revoke'))->icon('heroicon-o-link-slash')->color('gray')
                    ->visible(fn (SearchRequestRecipient $record): bool => \App\SearchRequests\SearchRequestLink::query()->where('recipient_id', $record->getKey())->whereNull('revoked_at')->exists())
                    ->requiresConfirmation()
                    ->action(fn (SearchRequestRecipient $record) => self::attempt(fn () => Notification::make()
                        ->title(__('solicitudes.aviso.links_revoked', ['n' => SearchRequests::revokeLinks($record, auth()->user())]))->success()->send())),
                Action::make('withdraw')->label(__('solicitudes.accion.withdraw'))->icon('heroicon-o-user-minus')->color('danger')
                    ->visible(fn (SearchRequestRecipient $record): bool => $record->status !== 'retirada')
                    ->requiresConfirmation()
                    ->action(fn (SearchRequestRecipient $record) => self::attempt(fn () => SearchRequests::withdraw($record, auth()->user()))),
            ]);
    }

    /** E-mail and WhatsApp result of the last message to this collaborator (sent / delivered / read): informative only. */
    private static function receipt(SearchRequestRecipient $recipient): string
    {
        $notice = $recipient->notices()->latest('id')->first();
        if ($notice === null) {
            return '—';
        }
        $wa = Communication::query()->where('related_type', $notice->getMorphClass())->where('related_id', (string) $notice->getKey())
            ->where('channel', 'whatsapp')->latest('id')->first();

        return __('solicitudes.canal.email').': '.($notice->email_status ?? '—').' · WhatsApp: '.($wa?->statusLabel() ?? $notice->whatsapp_status ?? '—');
    }

    private static function attempt(callable $operation): void
    {
        try {
            $operation();
            Notification::make()->title(__('solicitudes.aviso.saved'))->success()->send();
        } catch (Throwable $e) {
            report($e);
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }
}
