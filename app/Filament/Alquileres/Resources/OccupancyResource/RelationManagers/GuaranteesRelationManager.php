<?php

declare(strict_types=1);

namespace App\Filament\Alquileres\Resources\OccupancyResource\RelationManagers;

use App\Filament\Alquileres\RentalUi;
use App\Rentals\Models\Occupancy;
use App\Rentals\Models\RentalGuarantee;
use App\Rentals\RentalAccess;
use App\Rentals\RentalFlows;
use App\Rentals\RentalRules;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Fianza legal (lodged with the Generalitat in the Comunitat Valenciana), additional guarantee and aval; settlement with justified deductions. */
final class GuaranteesRelationManager extends RelationManager
{
    protected static string $relationship = 'guarantees';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('clarto.alquileres.fields.guarantees');
    }

    public function isReadOnly(): bool
    {
        return ! RentalUi::can(RentalAccess::MANAGE);
    }

    public function table(Table $table): Table
    {
        $f = fn (string $key): string => __('clarto.alquileres.fields.'.$key);
        /** @var Occupancy $occupancy */
        $occupancy = $this->getOwnerRecord();

        return $table
            ->columns([
                TextColumn::make('type')->label($f('type'))->formatStateUsing(fn (string $state): string => RentalUi::label('guarantee_types', $state)),
                TextColumn::make('amount')->label($f('amount'))->money('EUR'),
                TextColumn::make('provider')->label($f('provider'))->placeholder('—'),
                TextColumn::make('status')->label($f('status'))->badge()->formatStateUsing(fn (string $state): string => RentalUi::label('guarantee_statuses', $state)),
                TextColumn::make('deposit_receipt')->label($f('deposit_receipt'))->placeholder('—'),
                TextColumn::make('returned_amount')->label($f('returned_amount'))->money('EUR')->placeholder('—'),
            ])
            ->headerActions([
                Action::make('add')->label(__('clarto.alquileres.actions.add_guarantee'))->icon('heroicon-o-shield-check')
                    ->schema([
                        Select::make('type')->label($f('type'))->required()->live()->options(RentalUi::options('guarantee_types', RentalGuarantee::TYPES)),
                        TextInput::make('amount')->label($f('amount'))->numeric()->minValue(0.01)->suffix('€')->required()
                            ->default(fn (): float => RentalRules::legalDeposit($occupancy)),
                        DatePicker::make('date')->label($f('date'))->required()->default(now()),
                        TextInput::make('provider')->label($f('provider'))->maxLength(255),
                    ])
                    ->action(fn (array $data) => RentalUi::attempt(fn () => RentalFlows::receiveGuarantee($occupancy, $data['type'], (float) $data['amount'], $data['date'], $data['provider'] ?? null))),
            ])
            ->recordActions([
                Action::make('lodge')->label(__('clarto.alquileres.actions.lodge'))->icon('heroicon-o-building-library')
                    ->visible(fn (RentalGuarantee $record): bool => $record->type === 'fianza' && $record->status === 'recibida')
                    ->schema([
                        TextInput::make('receipt')->label($f('deposit_receipt'))->required()->maxLength(120)->helperText(__('clarto.alquileres.fields.deposit_receipt_help')),
                        DatePicker::make('date')->label($f('deposited_on'))->required()->default(now()),
                        TextInput::make('authority')->label($f('authority'))->default('Generalitat Valenciana')->required(),
                    ])
                    ->action(fn (RentalGuarantee $record, array $data) => RentalUi::attempt(fn () => RentalFlows::lodgeDeposit($record, $data['receipt'], $data['date'], $data['authority']))),
                Action::make('settle')->label(__('clarto.alquileres.actions.settle_guarantee'))->icon('heroicon-o-arrow-uturn-left')
                    ->visible(fn (RentalGuarantee $record): bool => in_array($record->status, ['recibida', 'depositada'], true) && $occupancy->status === 'finalizada')
                    ->schema([
                        DatePicker::make('date')->label($f('date'))->required()->default(now()),
                        Repeater::make('deductions')->label($f('deductions'))->defaultItems(0)->columns(3)->schema([
                            TextInput::make('concept')->label($f('concept'))->required(),
                            TextInput::make('amount')->label($f('amount'))->numeric()->minValue(0.01)->required(),
                            TextInput::make('justification')->label($f('justification'))->required(),
                        ]),
                    ])
                    ->action(fn (RentalGuarantee $record, array $data) => RentalUi::attempt(fn () => RentalFlows::settleGuarantee($record, array_values(array_map(
                        fn (array $d): array => ['concept' => $d['concept'], 'amount' => (float) $d['amount'], 'justification' => $d['justification']], $data['deductions'] ?? [])), $data['date']))),
            ]);
    }
}
