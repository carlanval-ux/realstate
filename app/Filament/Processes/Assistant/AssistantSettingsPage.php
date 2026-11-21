<?php

declare(strict_types=1);

namespace App\Filament\Processes\Assistant;

use App\Models\AiSetting;
use App\Modules\ClartoModules;
use App\Processes\Assistant\AssistantSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;

/**
 * Settings of «Preguntar a CLARTO» for the current company: on/off, model (blank = the panel's writing model), limits
 * of what is sent to the provider and how long the query log is kept. procesos.administrar or company administrator.
 */
final class AssistantSettingsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $slug = 'preguntar-a-clarto/ajustes';

    protected static bool $shouldRegisterNavigation = false;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function getTitle(): string
    {
        return __('asistente.ajustes.titulo');
    }

    public static function canAccess(): bool
    {
        return RecommendationsTable::allowed();
    }

    public function mount(): void
    {
        $settings = AssistantSettings::forTeam((int) Filament::getTenant()->getKey());
        $this->form->fill($settings->only(['enabled', 'model', 'max_context_chunks', 'max_context_chars', 'log_retention_days']));
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make()->columns(2)->schema([
                Toggle::make('enabled')->label(__('asistente.ajustes.activado'))->columnSpanFull()
                    ->helperText(ClartoModules::switchedOn(AssistantSettings::MODULE) ? null : __('asistente.ajustes.modulo_apagado')),
                TextInput::make('model')->label(__('asistente.ajustes.modelo'))->maxLength(190)
                    ->placeholder(AiSetting::current()->writing_model)->helperText(__('asistente.ajustes.modelo_ayuda')),
                TextInput::make('max_context_chunks')->label(__('asistente.ajustes.fragmentos'))->integer()->minValue(1)->maxValue(12)->required(),
                TextInput::make('max_context_chars')->label(__('asistente.ajustes.caracteres'))->integer()->minValue(1000)->maxValue(20000)->required(),
                TextInput::make('log_retention_days')->label(__('asistente.ajustes.retencion'))->integer()->minValue(7)->maxValue(730)->required(),
            ]),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('asistente.ajustes.que_se_envia'))->schema([Text::make(__('asistente.ajustes.que_se_envia_texto'))]),
            Form::make([EmbeddedSchema::make('form')])->id('form')->livewireSubmitHandler('save')
                ->footer([Actions::make([Action::make('save')->label(__('Save'))->submit('save')])]),
        ]);
    }

    public function save(): void
    {
        abort_unless(self::canAccess(), 403);
        $state = $this->form->getState();
        AssistantSettings::query()->updateOrCreate(['team_id' => Filament::getTenant()->getKey()], [
            'enabled' => (bool) $state['enabled'],
            'model' => filled($state['model'] ?? null) ? trim((string) $state['model']) : null,
            'max_context_chunks' => (int) $state['max_context_chunks'],
            'max_context_chars' => (int) $state['max_context_chars'],
            'log_retention_days' => (int) $state['log_retention_days'],
        ]);
        Notification::make()->title(__('Saved'))->success()->send();
    }
}
