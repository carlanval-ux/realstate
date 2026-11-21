<?php

declare(strict_types=1);

namespace App\Filament\Processes\Resources;

use App\Filament\Processes\ProcessUi;
use App\Filament\Processes\Resources\Pages\ManagePositions;
use App\Processes\Models\Area;
use App\Processes\Models\Position;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Unique;

/** «Puestos» (jobs, not roles): each one in an area. */
final class PositionResource extends Resource
{
    protected static ?string $model = Position::class;

    protected static ?string $slug = 'procesos-puestos';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-briefcase';

    protected static ?int $navigationSort = 4;

    public static function getModelLabel(): string
    {
        return __('procesos.estructura.puesto');
    }

    public static function getPluralModelLabel(): string
    {
        return __('procesos.estructura.puestos');
    }

    public static function getNavigationGroup(): ?string
    {
        return ProcessUi::group();
    }

    public static function canViewAny(): bool
    {
        return AreaResource::canViewAny();
    }

    public static function canCreate(): bool
    {
        return AreaResource::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return AreaResource::canViewAny();
    }

    public static function canDelete(Model $record): bool
    {
        return AreaResource::canViewAny() && ! $record->assignments()->exists();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->label(__('procesos.campos.name'))->required()->maxLength(120),
            TextInput::make('key')->label(__('procesos.campos.key'))->required()->maxLength(40)->regex('/^[a-z0-9_]+$/')
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('team_id', ProcessUi::team()))
                ->disabled(fn (?Position $record): bool => $record !== null)->helperText(__('procesos.estructura.key_ayuda')),
            Select::make('area_id')->label(__('procesos.campos.area'))->required()
                ->options(fn (): array => Area::query()->where('team_id', ProcessUi::team())->orderBy('sort')->get()->mapWithKeys(fn (Area $a): array => [$a->id => $a->label()])->all()),
            TextInput::make('sort')->label(__('procesos.campos.sort'))->numeric()->default(100),
            Textarea::make('description')->label(__('procesos.campos.description'))->rows(2)->columnSpanFull(),
            Toggle::make('proposal')->label(__('procesos.campos.proposal')),
            Toggle::make('active')->label(__('procesos.campos.active'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('sort')->columns([
            TextColumn::make('name')->label(__('procesos.campos.name'))->searchable(),
            TextColumn::make('area.code')->label(__('procesos.campos.area')),
            TextColumn::make('assignments_count')->label(__('procesos.estructura.personas'))->counts(['assignments' => fn ($query) => $query->active()]),
            IconColumn::make('proposal')->label(__('procesos.campos.proposal'))->boolean(),
            IconColumn::make('active')->label(__('procesos.campos.active'))->boolean(),
        ])->filters([
            SelectFilter::make('area_id')->label(__('procesos.campos.area'))->options(fn (): array => Area::query()->where('team_id', ProcessUi::team())->pluck('name', 'id')->all()),
        ])->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManagePositions::route('/')];
    }
}
