<?php

declare(strict_types=1);

namespace App\Filament\Alquileres\Resources\OccupancyResource\RelationManagers;

use App\Filament\Alquileres\RentalUi;
use App\Rentals\Models\RentalEvent;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Append-only history of the occupancy (read only). */
final class EventsRelationManager extends RelationManager
{
    protected static string $relationship = 'events';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('clarto.alquileres.fields.history');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        $f = fn (string $key): string => __('clarto.alquileres.fields.'.$key);

        return $table
            ->columns([
                TextColumn::make('occurred_on')->label($f('occurred_on'))->date('d/m/Y'),
                TextColumn::make('type')->label($f('event'))->formatStateUsing(fn (string $state): string => __('clarto.alquileres.events.'.$state) === 'clarto.alquileres.events.'.$state ? $state : __('clarto.alquileres.events.'.$state)),
                TextColumn::make('data')->label($f('data'))->wrap()
                    ->formatStateUsing(fn (RentalEvent $record): string => collect($record->data ?? [])->except(['lineas', 'noches', 'firmantes'])
                        ->map(fn ($v, string $k): string => $k.': '.(is_array($v) ? implode('; ', array_map(fn ($x) => is_scalar($x) ? (string) $x : json_encode($x, JSON_UNESCAPED_UNICODE), $v)) : (string) $v))->implode(' · ')),
            ])
            ->defaultSort('id', 'desc');
    }
}
