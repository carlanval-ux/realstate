<?php

declare(strict_types=1);

namespace App\Filament\Alquileres\Resources;

use App\Filament\Alquileres\RentalUi;
use App\Filament\Alquileres\Resources\RentalUnitResource\Pages\ManageRentalUnits;
use App\Rentals\Models\RentalUnit;
use App\Rentals\RentalAccess;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Liberu\RealEstate\Properties\Models\Property;

/** «Unidades alquilables»: whole dwellings and their rooms, capacity, common areas and holiday seasonal rates. */
final class RentalUnitResource extends Resource
{
    protected static ?string $model = RentalUnit::class;

    protected static ?string $slug = 'alquileres-unidades';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-home-modern';

    protected static ?int $navigationSort = 51;

    public static function getModelLabel(): string
    {
        return __('clarto.alquileres.units.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('clarto.alquileres.units.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('filament.nav_groups.sales_lettings');
    }

    public static function canViewAny(): bool
    {
        return RentalUi::can(RentalAccess::VIEW);
    }

    public static function canCreate(): bool
    {
        return RentalUi::can(RentalAccess::MANAGE);
    }

    public static function canEdit(Model $record): bool
    {
        return RentalUi::can(RentalAccess::MANAGE);
    }

    /** Units are deactivated, never deleted: their history stays. */
    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        $isRoom = fn (Get $get): bool => $get('kind') === RentalUnit::ROOM;
        $f = fn (string $key): string => __('clarto.alquileres.fields.'.$key);

        return $schema->columns(2)->components([
            Select::make('property_id')->label($f('property'))->required()->searchable()->preload()->live()
                ->options(fn (): array => Property::query()->where('team_id', RentalUi::team())->orderBy('title')->get()
                    ->mapWithKeys(fn (Property $p): array => [$p->getKey() => $p->title ?: $p->address])->all()),
            Select::make('kind')->label($f('kind'))->required()->live()->default(RentalUnit::DWELLING)
                ->options(RentalUi::options('unit_kinds', [RentalUnit::DWELLING, RentalUnit::ROOM])),
            Select::make('parent_id')->label($f('parent'))->visible($isRoom)->required($isRoom)
                ->options(fn (Get $get): array => RentalUnit::query()->where('property_id', $get('property_id'))->where('kind', RentalUnit::DWELLING)->pluck('name', 'id')->all()),
            TextInput::make('name')->label($f('name'))->required()->maxLength(120),
            TextInput::make('max_occupants')->label($f('max_occupants'))->numeric()->minValue(1)->default(1)->required(),
            TextInput::make('area_m2')->label($f('area_m2'))->numeric()->minValue(0),
            TextInput::make('reference_rent')->label($f('reference_rent'))->numeric()->minValue(0)->hidden($isRoom),
            TagsInput::make('common_areas')->label($f('common_areas'))->hidden($isRoom),
            Toggle::make('active')->label($f('active'))->default(true),
            Repeater::make('seasons')->label($f('seasons'))->relationship()->hidden($isRoom)->columnSpanFull()->columns(5)->defaultItems(0)
                ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => $data + ['team_id' => RentalUi::team()])
                ->schema([
                    TextInput::make('name')->label($f('season_name'))->required()->maxLength(80),
                    DatePicker::make('starts_on')->label($f('starts_on'))->required(),
                    DatePicker::make('ends_on')->label($f('ends_on'))->required()->afterOrEqual('starts_on'),
                    TextInput::make('nightly_rate')->label($f('nightly_rate'))->numeric()->minValue(0)->suffix('€')->required(),
                    TextInput::make('min_nights')->label($f('min_nights'))->numeric()->minValue(1)->default(1)->required(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['property', 'parent']))
            ->columns([
                TextColumn::make('property.title')->label(__('clarto.alquileres.fields.property'))->searchable()->placeholder('—')
                    ->formatStateUsing(fn (RentalUnit $record): string => $record->property?->title ?: (string) $record->property?->address),
                TextColumn::make('name')->label(__('clarto.alquileres.fields.unit'))->formatStateUsing(fn (RentalUnit $record): string => $record->label())->searchable(),
                TextColumn::make('kind')->label(__('clarto.alquileres.fields.kind'))->badge()->formatStateUsing(fn (string $state): string => RentalUi::label('unit_kinds', $state)),
                TextColumn::make('max_occupants')->label(__('clarto.alquileres.fields.max_occupants')),
                IconColumn::make('active')->label(__('clarto.alquileres.fields.active'))->boolean(),
            ])
            ->filters([
                SelectFilter::make('kind')->label(__('clarto.alquileres.fields.kind'))->options(RentalUi::options('unit_kinds', [RentalUnit::DWELLING, RentalUnit::ROOM])),
            ])
            ->recordActions([EditAction::make()])
            ->defaultSort('property_id');
    }

    public static function getPages(): array
    {
        return ['index' => ManageRentalUnits::route('/')];
    }
}
