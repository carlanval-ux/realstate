<?php

declare(strict_types=1);

namespace App\Filament\Alquileres\Pages;

use App\Filament\Alquileres\RentalUi;
use App\Rentals\Models\RentalSettings;
use App\Rentals\RentalAccess;
use Filament\Actions\Action;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;

/** «Ajustes de alquileres» of the current company: booking channels, document templates and rules. */
final class RentalSettingsPage extends Page
{
    protected static ?string $slug = 'alquileres-ajustes';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?int $navigationSort = 54;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function getNavigationGroup(): ?string
    {
        return __('filament.nav_groups.sales_lettings');
    }

    public static function getNavigationLabel(): string
    {
        return __('clarto.alquileres.settings.title');
    }

    public function getTitle(): string
    {
        return __('clarto.alquileres.settings.title');
    }

    public static function canAccess(): bool
    {
        return RentalUi::can(RentalAccess::MANAGE);
    }

    public function mount(): void
    {
        $this->form->fill(RentalSettings::forTeam(RentalUi::team()));
    }

    public function form(Schema $schema): Schema
    {
        $f = fn (string $key): string => __('clarto.alquileres.fields.'.$key);

        return $schema->statePath('data')->columns(2)->components([
            Select::make('region')->label($f('region'))->required()->options(['CV' => 'Comunitat Valenciana', 'otra' => '—']),
            TagsInput::make('canales')->label($f('channels'))->required(),
            TextInput::make('comision_vacacional_pct')->label($f('commission'))->numeric()->minValue(0)->maxValue(100)->required(),
            TextInput::make('limpieza_vacacional')->label($f('cleaning_fee'))->numeric()->minValue(0)->required(),
            KeyValue::make('plantillas')->label($f('templates'))->columnSpanFull()->addable(false)->deletable(false)->editableKeys(false),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])->id('form')->livewireSubmitHandler('save')
                ->footer([Actions::make([Action::make('save')->label(__('Save'))->submit('save')])]),
        ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $data['plantillas'] = array_replace(RentalSettings::DEFAULTS['plantillas'], array_filter((array) ($data['plantillas'] ?? []), 'filled'));
        RentalSettings::query()->updateOrCreate(['team_id' => RentalUi::team()], ['data' => $data]);
        Notification::make()->success()->title(__('clarto.alquileres.settings.saved'))->send();
    }
}
