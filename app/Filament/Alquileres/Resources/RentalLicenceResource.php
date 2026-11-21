<?php

declare(strict_types=1);

namespace App\Filament\Alquileres\Resources;

use App\Filament\Alquileres\RentalUi;
use App\Filament\Alquileres\Resources\RentalLicenceResource\Pages\ManageRentalLicences;
use App\Rentals\Models\RentalLicence;
use App\Rentals\RentalAccess;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Liberu\RealEstate\Properties\Models\Property;

/** «Habilitaciones y registros»: VUT registration (Comunitat Valenciana), SES establishment, certificates… with status and validity. */
final class RentalLicenceResource extends Resource
{
    protected static ?string $model = RentalLicence::class;

    protected static ?string $slug = 'alquileres-habilitaciones';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-identification';

    protected static ?int $navigationSort = 53;

    public static function getModelLabel(): string
    {
        return __('clarto.alquileres.licences.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('clarto.alquileres.licences.plural');
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

    public static function canDelete(Model $record): bool
    {
        return RentalUi::can(RentalAccess::MANAGE);
    }

    public static function form(Schema $schema): Schema
    {
        $f = fn (string $key): string => __('clarto.alquileres.fields.'.$key);

        return $schema->columns(2)->components([
            Select::make('property_id')->label($f('property'))->required()->searchable()->preload()
                ->options(fn (): array => Property::query()->where('team_id', RentalUi::team())->orderBy('title')->get()
                    ->mapWithKeys(fn (Property $p): array => [$p->getKey() => $p->title ?: $p->address])->all()),
            Select::make('type')->label($f('type'))->required()->options(RentalUi::options('licence_types', RentalLicence::TYPES)),
            TextInput::make('number')->label($f('number'))->maxLength(120),
            Select::make('status')->label($f('status'))->required()->default('en_tramite')
                ->options(RentalUi::options('licence_statuses', ['en_tramite', 'vigente', 'suspendida', 'caducada', 'baja'])),
            DatePicker::make('valid_from')->label($f('valid_from')),
            DatePicker::make('valid_until')->label($f('valid_until'))->afterOrEqual('valid_from'),
            TextInput::make('authority')->label($f('authority'))->maxLength(120),
            Textarea::make('notes')->label($f('notes'))->rows(2)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('property.title')->label(__('clarto.alquileres.fields.property'))->placeholder('—'),
                TextColumn::make('type')->label(__('clarto.alquileres.fields.type'))->formatStateUsing(fn (string $state): string => RentalUi::label('licence_types', $state)),
                TextColumn::make('number')->label(__('clarto.alquileres.fields.number'))->searchable(),
                TextColumn::make('status')->label(__('clarto.alquileres.fields.status'))->badge()
                    ->formatStateUsing(fn (string $state): string => RentalUi::label('licence_statuses', $state))
                    ->color(fn (string $state): string => match ($state) { 'vigente' => 'success', 'en_tramite' => 'warning', default => 'danger' }),
                TextColumn::make('valid_until')->label(__('clarto.alquileres.fields.valid_until'))->date('d/m/Y')->placeholder('—')->sortable(),
            ])
            ->filters([SelectFilter::make('type')->label(__('clarto.alquileres.fields.type'))->options(RentalUi::options('licence_types', RentalLicence::TYPES))])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageRentalLicences::route('/')];
    }
}
