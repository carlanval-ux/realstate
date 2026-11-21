<?php

declare(strict_types=1);

namespace App\Filament\Alquileres\Resources\OccupancyResource\RelationManagers;

use App\Filament\Alquileres\RentalUi;
use App\Rentals\Models\RentalCharge;
use App\Rentals\RentalAccess;
use App\Rentals\RentalFlows;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Rent, utilities, stays, cleaning, CLARTO services and settlements of one occupancy; collect, flag non-payment, albarán. */
final class ChargesRelationManager extends RelationManager
{
    protected static string $relationship = 'charges';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('clarto.alquileres.fields.charges');
    }

    public function isReadOnly(): bool
    {
        return ! RentalUi::can(RentalAccess::MANAGE);
    }

    public function table(Table $table): Table
    {
        $f = fn (string $key): string => __('clarto.alquileres.fields.'.$key);
        $statuses = ['pendiente', 'cobrado', 'impagado', 'devuelto', 'anulado'];

        return $table
            ->columns([
                TextColumn::make('concept')->label($f('concept'))->formatStateUsing(fn (RentalCharge $record): string => $record->description ?: RentalUi::label('concepts', $record->concept)),
                TextColumn::make('period')->label($f('period'))->placeholder('—'),
                TextColumn::make('amount')->label($f('amount'))->money('EUR'),
                TextColumn::make('payer')->label($f('payer'))->formatStateUsing(fn (string $state): string => RentalUi::label('payers', $state)),
                TextColumn::make('due_on')->label($f('due_on'))->date('d/m/Y')->sortable(),
                TextColumn::make('status')->label($f('status'))->badge()->formatStateUsing(fn (string $state): string => RentalUi::label('charge_statuses', $state))
                    ->color(fn (string $state): string => match ($state) { 'cobrado' => 'success', 'impagado' => 'danger', 'pendiente' => 'warning', default => 'gray' }),
                TextColumn::make('albaran_ref')->label($f('albaran_ref'))->placeholder('—'),
            ])
            ->filters([SelectFilter::make('status')->label($f('status'))->options(RentalUi::options('charge_statuses', $statuses))])
            ->headerActions([
                Action::make('albaran')->label(__('clarto.alquileres.concepts.servicio_clarto'))->icon('heroicon-o-clipboard-document-list')
                    ->schema([
                        TextInput::make('description')->label(__('clarto.alquileres.fields.description'))->required()->maxLength(255),
                        TextInput::make('unit_price')->label($f('amount'))->numeric()->minValue(0)->suffix('€')->required(),
                        Select::make('payer')->label($f('payer'))->required()->options(RentalUi::options('payers', ['propietario', 'inquilino', 'viajero'])),
                        Select::make('tipo')->label($f('type'))->required()->default('servicio')
                            ->options(RentalUi::options('fee_types', ['servicio', 'opcional', 'gestion', 'renovacion'])),
                    ])
                    ->action(fn (array $data) => RentalUi::attempt(fn () => RentalFlows::requestAlbaran($this->getOwnerRecord(), [[
                        'description' => $data['description'], 'quantity' => 1, 'unit_price' => (float) $data['unit_price'], 'tipo' => $data['tipo'], 'solicitud_escrita' => $data['tipo'] === 'opcional',
                    ]], $data['payer']))),
            ])
            ->recordActions([
                Action::make('pay')->label(__('clarto.alquileres.actions.pay'))->icon('heroicon-o-check')->color('success')
                    ->visible(fn (RentalCharge $record): bool => in_array($record->status, ['pendiente', 'impagado'], true) && ! in_array($record->concept, ['devolucion', 'liquidacion_propietario'], true))
                    ->schema([DatePicker::make('date')->label($f('date'))->required()->default(now())])
                    ->action(fn (RentalCharge $record, array $data) => RentalUi::attempt(fn () => RentalFlows::pay($record, $data['date']))),
                Action::make('unpaid')->label(__('clarto.alquileres.actions.mark_unpaid'))->icon('heroicon-o-exclamation-triangle')->color('danger')
                    ->visible(fn (RentalCharge $record): bool => $record->status === 'pendiente' && $record->due_on->isPast())
                    ->requiresConfirmation()
                    ->action(fn (RentalCharge $record) => RentalUi::attempt(fn () => RentalFlows::markUnpaid($record, now()))),
            ])
            ->defaultSort('due_on', 'desc');
    }
}
