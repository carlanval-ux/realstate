<?php

declare(strict_types=1);

namespace App\Filament\Alquileres\Resources;

use App\Filament\Alquileres\RentalUi;
use App\Filament\Alquileres\Resources\OccupancyResource\Pages;
use App\Filament\Alquileres\Resources\OccupancyResource\RelationManagers;
use App\Rentals\Models\Occupancy;
use App\Rentals\Models\RentalSettings;
use App\Rentals\Models\RentalUnit;
use App\Rentals\RentalAccess;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * «Contratos y reservas» of the three rental modules on the shared occupancy base. Created through
 * App\Rentals\Occupancies (lock + overlap check); changed only through the actions of the record page.
 */
final class OccupancyResource extends Resource
{
    protected static ?string $model = Occupancy::class;

    protected static ?string $slug = 'alquileres';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-key';

    protected static ?int $navigationSort = 50;

    public static function getModelLabel(): string
    {
        return __('clarto.alquileres.occupancies.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('clarto.alquileres.occupancies.plural');
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
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        $f = fn (string $key): string => __('clarto.alquileres.fields.'.$key);
        $module = fn (Get $get): ?string => $get('module');
        $holiday = fn (Get $get): bool => $get('module') === 'vacacional';

        return $schema->columns(2)->components([
            Select::make('module')->label($f('module'))->required()->live()
                ->options(RentalUi::options('modules', Occupancy::MODULES))
                ->default(fn (): ?string => request()->query('modulo')),
            Select::make('unit_id')->label($f('unit'))->required()->searchable()
                ->options(fn (Get $get): array => RentalUnit::query()->with(['property', 'parent'])->where('team_id', RentalUi::team())->where('active', true)
                    ->where('kind', $get('module') === 'habitaciones' ? RentalUnit::ROOM : RentalUnit::DWELLING)->get()
                    ->mapWithKeys(fn (RentalUnit $u): array => [$u->getKey() => ($u->property?->title ?: $u->property?->address).' · '.$u->label()])->all()),
            Select::make('kind')->label($f('kind'))->required()->live()
                ->options(fn (Get $get): array => RentalUi::options('kinds', $get('module') === 'vacacional' ? ['reserva', 'bloqueo'] : ['contrato']))
                ->default('contrato'),
            Select::make('purpose')->label($f('purpose'))->required(fn (Get $get): bool => $get('kind') !== 'bloqueo')->live()
                ->hidden(fn (Get $get): bool => $get('kind') === 'bloqueo' || $get('module') === 'vacacional')
                ->options(RentalUi::options('purposes', ['habitual', 'temporal'])),
            Textarea::make('temporal_cause')->label($f('temporal_cause'))->rows(2)->columnSpanFull()
                ->visible(fn (Get $get): bool => $get('purpose') === 'temporal')->required(fn (Get $get): bool => $get('purpose') === 'temporal'),
            DatePicker::make('planned_start')->label($f('planned_start'))->required(),
            DatePicker::make('planned_end')->label($f('planned_end'))->required()->after('planned_start'),
            TextInput::make('occupants')->label($f('occupants'))->numeric()->minValue(1)->default(1)->required(),
            Section::make($f('rent_amount'))->columns(3)->columnSpanFull()->hidden(fn (Get $get): bool => $get('kind') === 'bloqueo' || $holiday($get))->schema([
                TextInput::make('rent_amount')->label($f('rent_amount'))->numeric()->minValue(0)->suffix('€')->required(),
                Select::make('rent_period')->label($f('rent_period'))->default('mes')->required()->options(RentalUi::options('rent_periods', ['mes'])),
                TextInput::make('due_day')->label($f('due_day'))->numeric()->minValue(1)->maxValue(28)->default(1),
                KeyValue::make('utilities')->label($f('utilities'))->helperText($f('utilities_help'))->columnSpanFull(),
                Toggle::make('landlord_is_company')->label($f('landlord_is_company')),
            ]),
            Select::make('channel')->label($f('channel'))->visible(fn (Get $get): bool => $holiday($get) && $get('kind') === 'reserva')->required(fn (Get $get): bool => $holiday($get) && $get('kind') === 'reserva')
                ->options(fn (): array => collect(RentalSettings::forTeam(RentalUi::team())['canales'])->mapWithKeys(fn (string $c): array => [$c => ucfirst($c)])->all()),
            TextInput::make('channel_ref')->label($f('channel_ref'))->visible(fn (Get $get): bool => $holiday($get) && $get('kind') === 'reserva')->maxLength(120),
            Textarea::make('notes')->label($f('notes'))->rows(2)->columnSpanFull(),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        $f = fn (string $key): string => __('clarto.alquileres.fields.'.$key);

        return $schema->columns(3)->components([
            TextEntry::make('module')->label($f('module'))->formatStateUsing(fn (string $state): string => RentalUi::label('modules', $state)),
            TextEntry::make('unit_id')->label($f('unit'))->formatStateUsing(fn (Occupancy $record): string => ($record->property?->title ?: $record->property?->address).' · '.$record->unit?->label()),
            TextEntry::make('status')->label($f('status'))->badge()->formatStateUsing(fn (string $state): string => RentalUi::label('statuses', $state)),
            TextEntry::make('kind')->label($f('kind'))->formatStateUsing(fn (string $state): string => RentalUi::label('kinds', $state)),
            TextEntry::make('purpose')->label($f('purpose'))->formatStateUsing(fn (?string $state): string => RentalUi::label('purposes', $state))->placeholder('—'),
            TextEntry::make('temporal_cause')->label($f('temporal_cause'))->placeholder('—'),
            TextEntry::make('planned_start')->label($f('planned_start'))->date('d/m/Y'),
            TextEntry::make('planned_end')->label($f('planned_end'))->date('d/m/Y'),
            TextEntry::make('occupants')->label($f('occupants')),
            TextEntry::make('actual_start')->label($f('actual_start'))->date('d/m/Y')->placeholder('—'),
            TextEntry::make('actual_end')->label($f('actual_end'))->date('d/m/Y')->placeholder('—'),
            TextEntry::make('renewals')->label(__('clarto.alquileres.actions.extend')),
            TextEntry::make('rent_amount')->label($f('rent_amount'))->money('EUR')->placeholder('—'),
            TextEntry::make('rent_period')->label($f('rent_period'))->formatStateUsing(fn (?string $state): string => RentalUi::label('rent_periods', $state))->placeholder('—'),
            TextEntry::make('channel')->label($f('channel'))->placeholder('—'),
            TextEntry::make('utilities')->label($f('utilities'))->columnSpanFull()->placeholder('—')
                ->formatStateUsing(fn (Occupancy $record): string => collect(RentalUi::utilitiesToForm($record->utilities))->map(fn (string $v, string $k): string => "{$k}: {$v}")->implode(' · ')),
            TextEntry::make('notes')->label($f('notes'))->columnSpanFull()->placeholder('—'),
        ]);
    }

    public static function table(Table $table): Table
    {
        $f = fn (string $key): string => __('clarto.alquileres.fields.'.$key);

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['unit.parent', 'property']))
            ->columns([
                TextColumn::make('module')->label($f('module'))->badge()->formatStateUsing(fn (string $state): string => RentalUi::label('modules', $state)),
                TextColumn::make('unit_id')->label($f('unit'))->formatStateUsing(fn (Occupancy $record): string => ($record->property?->title ?: $record->property?->address).' · '.$record->unit?->label()),
                TextColumn::make('kind')->label($f('kind'))->formatStateUsing(fn (string $state): string => RentalUi::label('kinds', $state))->toggleable(),
                TextColumn::make('purpose')->label($f('purpose'))->formatStateUsing(fn (?string $state): string => RentalUi::label('purposes', $state))->placeholder('—'),
                TextColumn::make('status')->label($f('status'))->badge()->formatStateUsing(fn (string $state): string => RentalUi::label('statuses', $state))
                    ->color(fn (string $state): string => match ($state) { 'confirmada' => 'info', 'en_curso' => 'success', 'cancelada' => 'danger', default => 'gray' }),
                TextColumn::make('planned_start')->label($f('planned_start'))->date('d/m/Y')->sortable(),
                TextColumn::make('planned_end')->label($f('planned_end'))->date('d/m/Y')->sortable(),
                TextColumn::make('rent_amount')->label($f('rent_amount'))->money('EUR')->placeholder('—'),
                TextColumn::make('channel')->label($f('channel'))->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->label($f('status'))->options(RentalUi::options('statuses', Occupancy::STATUSES)),
                SelectFilter::make('purpose')->label($f('purpose'))->options(RentalUi::options('purposes', Occupancy::PURPOSES)),
            ])
            ->recordActions([ViewAction::make()])
            ->defaultSort('planned_start', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\PeopleRelationManager::class,
            RelationManagers\ChargesRelationManager::class,
            RelationManagers\GuaranteesRelationManager::class,
            RelationManagers\IncidentsRelationManager::class,
            RelationManagers\DocumentsRelationManager::class,
            RelationManagers\EventsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOccupancies::route('/'),
            'create' => Pages\CreateOccupancy::route('/create'),
            'view' => Pages\ViewOccupancy::route('/{record}'),
        ];
    }
}
