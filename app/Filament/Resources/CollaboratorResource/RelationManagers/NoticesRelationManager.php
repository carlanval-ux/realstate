<?php

declare(strict_types=1);

namespace App\Filament\Resources\CollaboratorResource\RelationManagers;

use App\Filament\Collaborators\CollaboratorUi;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Notices sent to this collaborator (e-mail + WhatsApp), with the result of each channel and a retry. */
final class NoticesRelationManager extends RelationManager
{
    protected static string $relationship = 'notices';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('colaboradores.fields.notices');
    }

    public function table(Table $table): Table
    {
        $f = fn (string $k): string => __('colaboradores.fields.'.$k);

        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label($f('date'))->dateTime('d/m/Y H:i', 'Europe/Madrid'),
                TextColumn::make('email_subject')->label($f('subject'))->wrap()->limit(80),
                CollaboratorUi::noticeStatusColumn('status', 'notice_statuses')->label($f('status')),
                CollaboratorUi::noticeStatusColumn('email_status', 'channel_statuses')->label(__('colaboradores.channels.email')),
                CollaboratorUi::noticeStatusColumn('whatsapp_status', 'channel_statuses')->label('WhatsApp'),
                TextColumn::make('attempts')->label($f('attempts')),
                TextColumn::make('last_error')->label($f('last_error'))->wrap()->limit(120)->placeholder('—'),
            ])
            ->recordActions([CollaboratorUi::retryAction()]);
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}
