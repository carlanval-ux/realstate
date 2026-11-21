<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\SearchRequests\SearchRequestSettings;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;

/** «Ajustes de solicitudes» of the company: default deadline, reminders, notice to the owner, time zone. */
final class SearchRequestSettingsPage extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $slug = 'solicitudes-ajustes';

    protected static bool $shouldRegisterNavigation = false;   // reached from «Solicitudes de inmuebles»

    /** @var array<string, mixed> */
    public array $data = [];

    public function getTitle(): string
    {
        return __('solicitudes.extra.ajustes');
    }

    public function mount(): void
    {
        $s = SearchRequestSettings::forTeam(Filament::getTenant()?->getKey());
        $this->form->fill(['default_days' => $s->default_days, 'reminder_days' => implode(', ', $s->reminders()), 'owner_notice_days' => $s->owner_notice_days, 'timezone' => $s->tz()]);
    }

    public function form(Schema $schema): Schema
    {
        $x = fn (string $k): string => __('solicitudes.extra.'.$k);

        return $schema->statePath('data')->components([
            TextInput::make('default_days')->label($x('default_days'))->integer()->required()->minValue(1)->maxValue(365),
            TextInput::make('reminder_days')->label($x('reminder_days'))->regex('/^\s*\d{1,3}(\s*,\s*\d{1,3})*\s*$/')->placeholder('7, 2'),
            TextInput::make('owner_notice_days')->label($x('owner_notice_days'))->integer()->required()->minValue(0)->maxValue(60),
            Select::make('timezone')->label($x('timezone'))->required()->searchable()
                ->options(collect(timezone_identifiers_list())->mapWithKeys(fn (string $z) => [$z => $z])->all()),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([\Filament\Schemas\Components\EmbeddedSchema::make('form')])->id('form')->livewireSubmitHandler('save')
                ->footer([Actions::make([Action::make('save')->label(__('Save'))->submit('save')])]),
        ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        SearchRequestSettings::query()->updateOrCreate(['team_id' => Filament::getTenant()?->getKey()], [
            'default_days' => (int) $data['default_days'], 'owner_notice_days' => (int) $data['owner_notice_days'], 'timezone' => $data['timezone'],
            'reminder_days' => array_values(array_map('intval', array_filter(array_map('trim', explode(',', (string) $data['reminder_days'])), 'strlen'))),
        ]);
        Notification::make()->title(__('solicitudes.aviso.saved'))->success()->send();
    }
}
