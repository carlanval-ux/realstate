<?php

declare(strict_types=1);

namespace App\Filament\Processes\Resources;

use App\Filament\Processes\ProcessUi;
use App\Filament\Processes\Resources\Pages\ManageAreas;
use App\Processes\Models\Area;
use App\Processes\ProcessAccess;
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
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Unique;

/** «Áreas» of the company (code prefixes the process codes). «procesos.administrar» or company administrator. */
final class AreaResource extends Resource
{
    protected static ?string $model = Area::class;

    protected static ?string $slug = 'procesos-areas';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?int $navigationSort = 3;

    public static function getModelLabel(): string
    {
        return __('procesos.estructura.area');
    }

    public static function getPluralModelLabel(): string
    {
        return __('procesos.estructura.areas');
    }

    public static function getNavigationGroup(): ?string
    {
        return ProcessUi::group();
    }

    public static function getNavigationLabel(): string
    {
        return __('procesos.estructura.menu');
    }

    public static function canViewAny(): bool
    {
        return ProcessUi::team() !== null && ProcessAccess::canAdminister(ProcessUi::user(), ProcessUi::team());
    }

    public static function canCreate(): bool
    {
        return self::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return self::canViewAny();
    }

    public static function canDelete(Model $record): bool
    {
        return self::canViewAny() && ! $record->positions()->exists() && ! \App\Processes\Models\Process::query()->where('area_id', $record->getKey())->exists();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('code')->label(__('procesos.campos.code'))->required()->length(3)->alpha()
                ->dehydrateStateUsing(fn (?string $state): string => strtoupper((string) $state))
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('team_id', ProcessUi::team()))
                ->disabled(fn (?Area $record): bool => $record !== null)->helperText(__('procesos.estructura.code_ayuda')),
            TextInput::make('name')->label(__('procesos.campos.name'))->required()->maxLength(120),
            Select::make('business_line')->label(__('procesos.campos.business_line'))->required()->default('transversal')->options(ProcessUi::options('lineas', Area::BUSINESS_LINES)),
            Select::make('manager_user_id')->label(__('procesos.campos.manager'))->searchable()->options(fn (): array => ProcessUi::users(ProcessUi::team())),
            Textarea::make('description')->label(__('procesos.campos.description'))->rows(2)->columnSpanFull(),
            TextInput::make('sort')->label(__('procesos.campos.sort'))->numeric()->default(100),
            Toggle::make('proposal')->label(__('procesos.campos.proposal')),
            Toggle::make('active')->label(__('procesos.campos.active'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('sort')->columns([
            TextColumn::make('code')->label(__('procesos.campos.code'))->sortable(),
            TextColumn::make('name')->label(__('procesos.campos.name'))->searchable(),
            TextColumn::make('business_line')->label(__('procesos.campos.business_line'))->formatStateUsing(fn (?string $state): string => $state ? __('procesos.lineas.'.$state) : '—'),
            TextColumn::make('manager.name')->label(__('procesos.campos.manager')),
            IconColumn::make('proposal')->label(__('procesos.campos.proposal'))->boolean(),
            IconColumn::make('active')->label(__('procesos.campos.active'))->boolean(),
        ])->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageAreas::route('/')];
    }
}
