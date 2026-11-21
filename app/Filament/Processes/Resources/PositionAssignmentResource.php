<?php

declare(strict_types=1);

namespace App\Filament\Processes\Resources;

use App\Filament\Processes\ProcessUi;
use App\Filament\Processes\Resources\Pages\ManagePositionAssignments;
use App\Processes\Models\Position;
use App\Processes\Models\PositionAssignment;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** Who holds which position, in which office, from when to when (an end date in the past removes the access at once). */
final class PositionAssignmentResource extends Resource
{
    protected static ?string $model = PositionAssignment::class;

    protected static ?string $slug = 'procesos-asignaciones';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-plus';

    protected static ?int $navigationSort = 5;

    public static function getModelLabel(): string
    {
        return __('procesos.estructura.asignacion');
    }

    public static function getPluralModelLabel(): string
    {
        return __('procesos.estructura.asignaciones');
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
        return AreaResource::canViewAny();
    }

    public static function form(Schema $schema): Schema
    {
        $team = fn () => ProcessUi::team();

        return $schema->columns(2)->components([
            Select::make('user_id')->label(__('procesos.campos.user'))->required()->searchable()->options(fn (): array => ProcessUi::users($team())),
            Select::make('position_id')->label(__('procesos.estructura.puesto'))->required()->options(fn (): array => Position::options($team())),
            Select::make('branch_id')->label(__('procesos.campos.branch'))
                ->options(fn (): array => DB::getSchemaBuilder()->hasTable('real_estate_branches') ? DB::table('real_estate_branches')->where('team_id', $team())->orderBy('name')->pluck('name', 'id')->all() : []),
            Toggle::make('is_primary')->label(__('procesos.campos.is_primary')),
            DatePicker::make('starts_on')->label(__('procesos.campos.starts_on')),
            DatePicker::make('ends_on')->label(__('procesos.campos.ends_on'))->afterOrEqual('starts_on')->helperText(__('procesos.estructura.ends_ayuda')),
        ]);
    }

    public static function table(Table $table): Table
    {
        $today = now()->toDateString();

        return $table->defaultSort('user_id')->columns([
            TextColumn::make('user.name')->label(__('procesos.campos.user'))->searchable(),
            TextColumn::make('position.name')->label(__('procesos.estructura.puesto')),
            TextColumn::make('branch_id')->label(__('procesos.campos.branch'))->formatStateUsing(fn ($state): string => (string) (DB::table('real_estate_branches')->where('id', $state)->value('name') ?? '—')),
            IconColumn::make('is_primary')->label(__('procesos.campos.is_primary'))->boolean(),
            TextColumn::make('starts_on')->label(__('procesos.campos.starts_on'))->date('d/m/Y'),
            TextColumn::make('ends_on')->label(__('procesos.campos.ends_on'))->date('d/m/Y')
                ->color(fn ($state): ?string => $state !== null && $state->toDateString() < $today ? 'danger' : null),
        ])->filters([
            TernaryFilter::make('activas')->label(__('procesos.estructura.activas'))->default(true)
                ->queries(true: fn (Builder $query) => $query->active(), false: fn (Builder $query) => $query->whereNot(fn (Builder $q) => $q->active())),
            SelectFilter::make('position_id')->label(__('procesos.estructura.puesto'))->options(fn (): array => Position::options(ProcessUi::team())),
        ])->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManagePositionAssignments::route('/')];
    }
}
