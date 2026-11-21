<?php

declare(strict_types=1);

namespace App\Filament\Alquileres\Resources\OccupancyResource\RelationManagers;

use App\Filament\Alquileres\RentalUi;
use App\Rentals\Models\OccupancyPerson;
use App\Rentals\RentalAccess;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Liberu\RealEstate\Parties\Models\Party;

/**
 * Holder, signatories, tenants, occupants, guarantors, owner and travellers, each with its own role. Identity and
 * solvency are only shown and edited with their permissions (travellers: «alquileres.viajeros»).
 */
final class PeopleRelationManager extends RelationManager
{
    protected static string $relationship = 'people';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('clarto.alquileres.fields.people');
    }

    public function isReadOnly(): bool
    {
        return ! RentalUi::can(RentalAccess::MANAGE);
    }

    public function form(Schema $schema): Schema
    {
        $f = fn (string $key): string => __('clarto.alquileres.fields.'.$key);
        $identity = fn (Get $get): bool => RentalUi::can($get('role') === 'viajero' ? RentalAccess::TRAVELLERS : RentalAccess::IDENTITY);

        return $schema->columns(2)->components([
            Select::make('role')->label($f('role'))->required()->live()->options(RentalUi::options('roles', OccupancyPerson::ROLES)),
            Select::make('party_id')->label($f('party'))->searchable()->preload()
                ->options(fn (): array => Party::query()->where('team_id', RentalUi::team())->orderBy('name')->pluck('name', 'id')->all()),
            TextInput::make('name')->label($f('name'))->required()->maxLength(255),
            TextInput::make('email')->label($f('email'))->email()->maxLength(255),
            TextInput::make('phone')->label($f('phone'))->tel()->maxLength(40),
            KeyValue::make('identity')->label($f('identity'))->columnSpanFull()->visible($identity)
                ->helperText('nombre, apellido1, apellido2, sexo, tipo_documento, numero_documento, soporte_documento, nacionalidad, fecha_nacimiento, direccion, contacto, parentesco'),
            KeyValue::make('solvency')->label($f('solvency'))->columnSpanFull()->visible(fn (): bool => RentalUi::can(RentalAccess::SOLVENCY)),
        ]);
    }

    public function table(Table $table): Table
    {
        $f = fn (string $key): string => __('clarto.alquileres.fields.'.$key);

        return $table
            ->columns([
                TextColumn::make('role')->label($f('role'))->badge()->formatStateUsing(fn (string $state): string => RentalUi::label('roles', $state)),
                TextColumn::make('name')->label($f('name')),
                TextColumn::make('email')->label($f('email'))->placeholder('—'),
                TextColumn::make('phone')->label($f('phone'))->placeholder('—'),
                TextColumn::make('id')->label($f('identity'))
                    ->formatStateUsing(fn (OccupancyPerson $record): string => ($data = $record->identityFor(auth()->user())) === null
                        ? __('clarto.alquileres.fields.restricted')
                        : (trim(($data['tipo_documento'] ?? '').' '.($data['numero_documento'] ?? '')) ?: '—')),
            ])
            ->headerActions([
                CreateAction::make()->mutateDataUsing(fn (array $data): array => $data + ['team_id' => $this->getOwnerRecord()->team_id]),
            ])
            ->recordActions([
                EditAction::make()->mutateRecordDataUsing(fn (array $data, OccupancyPerson $record): array => [
                    ...$data,
                    'identity' => $record->identityFor(auth()->user()) ?? [],
                    'solvency' => $record->solvencyFor(auth()->user()) ?? [],
                ]),
            ]);
    }
}
