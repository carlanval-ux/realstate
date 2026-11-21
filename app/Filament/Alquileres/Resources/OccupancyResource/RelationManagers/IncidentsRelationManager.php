<?php

declare(strict_types=1);

namespace App\Filament\Alquileres\Resources\OccupancyResource\RelationManagers;

use App\Filament\Alquileres\RentalUi;
use App\Rentals\Models\RentalIncident;
use App\Rentals\RentalAccess;
use App\Rentals\RentalFlows;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Breakdowns and cleaning: photos, priority, authorised access, provider and quote, who pays, verified closure. */
final class IncidentsRelationManager extends RelationManager
{
    protected static string $relationship = 'incidents';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('clarto.alquileres.fields.incidents');
    }

    public function isReadOnly(): bool
    {
        return ! RentalUi::can(RentalAccess::MANAGE);
    }

    public function table(Table $table): Table
    {
        $f = fn (string $key): string => __('clarto.alquileres.fields.'.$key);

        return $table
            ->columns([
                TextColumn::make('kind')->label($f('kind'))->formatStateUsing(fn (string $state): string => RentalUi::label('incident_kinds', $state)),
                TextColumn::make('title')->label($f('title')),
                TextColumn::make('priority')->label($f('priority'))->badge()->formatStateUsing(fn (string $state): string => RentalUi::label('priorities', $state))
                    ->color(fn (string $state): string => match ($state) { 'urgente' => 'danger', 'alta' => 'warning', default => 'gray' }),
                IconColumn::make('access_authorized')->label($f('access_authorized'))->boolean(),
                TextColumn::make('provider_name')->label($f('provider_name'))->placeholder('—'),
                TextColumn::make('quote_amount')->label($f('quote_amount'))->money('EUR')->placeholder('—'),
                TextColumn::make('cost_bearer')->label($f('cost_bearer'))->formatStateUsing(fn (string $state): string => RentalUi::label('cost_bearers', $state)),
                TextColumn::make('status')->label($f('status'))->badge()->formatStateUsing(fn (string $state): string => RentalUi::label('incident_statuses', $state)),
            ])
            ->headerActions([
                Action::make('open')->label(__('clarto.alquileres.events.incidencia'))->icon('heroicon-o-wrench-screwdriver')
                    ->schema([
                        Select::make('kind')->label($f('kind'))->required()->default('averia')->options(RentalUi::options('incident_kinds', ['averia', 'mantenimiento', 'limpieza', 'otro'])),
                        TextInput::make('title')->label($f('title'))->required()->maxLength(255),
                        Textarea::make('description')->label($f('description'))->rows(3),
                        Select::make('priority')->label($f('priority'))->required()->default('normal')->options(RentalUi::options('priorities', ['baja', 'normal', 'alta', 'urgente'])),
                        FileUpload::make('photos')->label($f('photos'))->image()->multiple()->directory('alquileres/incidencias')->visibility('private'),
                        Toggle::make('access_authorized')->label($f('access_authorized')),
                        TextInput::make('access_notes')->label($f('access_notes'))->maxLength(255),
                    ])
                    ->action(fn (array $data) => RentalUi::attempt(fn () => RentalFlows::openIncident($this->getOwnerRecord(), $data + ['responsible_user_id' => auth()->id()]))),
            ])
            ->recordActions([
                Action::make('quote')->label(__('clarto.alquileres.actions.quote'))->icon('heroicon-o-document-currency-euro')
                    ->visible(fn (RentalIncident $record): bool => in_array($record->status, ['abierta', 'presupuestada'], true))
                    ->schema([
                        TextInput::make('provider')->label($f('provider_name'))->required()->maxLength(255),
                        TextInput::make('amount')->label($f('quote_amount'))->numeric()->minValue(0)->required(),
                        Select::make('bearer')->label($f('cost_bearer'))->required()->options(RentalUi::options('cost_bearers', ['propietario', 'inquilino', 'clarto', 'seguro'])),
                    ])
                    ->action(fn (RentalIncident $record, array $data) => RentalUi::attempt(fn () => RentalFlows::quoteIncident($record, $data['provider'], (float) $data['amount'], $data['bearer']))),
                Action::make('access')->label($f('access_authorized'))->icon('heroicon-o-lock-open')
                    ->visible(fn (RentalIncident $record): bool => ! $record->access_authorized && $record->status !== 'cerrada')
                    ->schema([TextInput::make('notes')->label($f('access_notes'))->required()->maxLength(255)])
                    ->action(fn (RentalIncident $record, array $data) => RentalUi::attempt(fn () => $record->forceFill(['access_authorized' => true, 'access_notes' => $data['notes']])->save())),
                Action::make('resolve')->label(__('clarto.alquileres.actions.resolve'))->icon('heroicon-o-check')
                    ->visible(fn (RentalIncident $record): bool => in_array($record->status, ['abierta', 'presupuestada', 'en_curso'], true))
                    ->schema([TextInput::make('amount')->label($f('final_amount'))->numeric()->minValue(0)->required()])
                    ->action(fn (RentalIncident $record, array $data) => RentalUi::attempt(fn () => RentalFlows::resolveIncident($record, (float) $data['amount']))),
                Action::make('verify')->label(__('clarto.alquileres.actions.verify'))->icon('heroicon-o-check-badge')->color('success')
                    ->visible(fn (RentalIncident $record): bool => $record->status === 'resuelta')
                    ->requiresConfirmation()
                    ->action(fn (RentalIncident $record) => RentalUi::attempt(fn () => RentalFlows::verifyIncident($record, (int) auth()->id()))),
            ]);
    }
}
