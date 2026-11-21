<?php

declare(strict_types=1);

namespace App\Filament\Alquileres\Resources\OccupancyResource\RelationManagers;

use App\Filament\Alquileres\RentalUi;
use App\Rentals\Models\RentalDocument;
use App\Rentals\RentalAccess;
use App\Rentals\RentalFlows;
use Filament\Actions\Action;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Contracts, annexes, inventories and records prepared in «Formularios» (prefilled) and sent to signature. */
final class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('clarto.alquileres.fields.documents');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return RentalUi::can(RentalAccess::CONTRACTS);
    }

    public function isReadOnly(): bool
    {
        return ! RentalUi::can(RentalAccess::CONTRACTS);
    }

    public function table(Table $table): Table
    {
        $f = fn (string $key): string => __('clarto.alquileres.fields.'.$key);

        return $table
            ->columns([
                TextColumn::make('kind')->label($f('kind'))->formatStateUsing(fn (string $state): string => RentalUi::label('document_kinds', $state)),
                TextColumn::make('title')->label($f('title')),
                TextColumn::make('template')->label($f('template'))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')->label($f('status'))->badge()->formatStateUsing(fn (string $state): string => RentalUi::label('document_statuses', $state)),
                TextColumn::make('external_ref')->label($f('external_ref'))->placeholder('—'),
                TextColumn::make('created_at')->label($f('date'))->date('d/m/Y'),
            ])
            ->headerActions([
                Action::make('prepare')->label(__('clarto.alquileres.actions.prepare_document'))->icon('heroicon-o-document-plus')
                    ->schema([
                        Select::make('kind')->label($f('kind'))->required()->options(RentalUi::options('document_kinds', RentalDocument::KINDS)),
                        KeyValue::make('values')->label($f('data')),
                        Toggle::make('send')->label($f('send_to_signature')),
                    ])
                    ->action(fn (array $data) => RentalUi::attempt(fn () => RentalFlows::prepareDocument($this->getOwnerRecord(), $data['kind'], $data['values'] ?? [], (bool) ($data['send'] ?? false)))),
            ])
            ->defaultSort('id', 'desc');
    }
}
