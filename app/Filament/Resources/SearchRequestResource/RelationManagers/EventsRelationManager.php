<?php

declare(strict_types=1);

namespace App\Filament\Resources\SearchRequestResource\RelationManagers;

use App\SearchRequests\SearchRequestEvent;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Audit trail: who created, assigned, changed, sent, answered, linked or closed (read only). */
final class EventsRelationManager extends RelationManager
{
    protected static string $relationship = 'events';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('solicitudes.seccion.history');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('user:id,name'))
            ->columns([
                TextColumn::make('created_at')->label(__('Date'))->dateTime('d/m/Y H:i', 'Europe/Madrid'),
                TextColumn::make('user.name')->label(__('User'))->placeholder(__('solicitudes.sistema')),
                TextColumn::make('summary')->label(__('solicitudes.campo.message'))->wrap()->state(fn (SearchRequestEvent $record) => $record->summary()),
            ])
            ->defaultSort('id', 'desc');
    }
}
