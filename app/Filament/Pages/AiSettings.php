<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Ai\AiClient;
use App\Ai\AiUnavailable;
use App\Models\AiSetting;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Administrators choose the AI provider (OpenRouter or OpenAI directly), the writing and translation models,
 * store the keys on the server (encrypted, never shown again) and test the connection. A paid fallback model
 * only runs when it is explicitly authorised here.
 */
final class AiSettings extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $slug = 'inteligencia-artificial';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('Artificial intelligence');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('filament.nav_groups.platform_settings');
    }

    public function getTitle(): string
    {
        return __('Artificial intelligence');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && method_exists($user, 'isAdmin') && $user->isAdmin();
    }

    public function mount(): void
    {
        $this->form->fill(AiSetting::current()->only(['provider', 'writing_model', 'translation_model', 'paid_fallback_model', 'paid_fallback_authorized']));
    }

    public function form(Schema $schema): Schema
    {
        $settings = AiSetting::current();
        $models = fn (): array => array_keys(AiClient::freeModels());

        return $schema->statePath('data')->components([
            Section::make(__('Provider and keys'))->columns(2)->schema([
                Select::make('provider')->label(__('Provider'))->options(AiSetting::PROVIDERS)->required()->live(),
                TextInput::make('openrouter_api_key')->label(__('OpenRouter API key'))->password()
                    ->placeholder(AiSetting::maskKey($settings->openrouter_api_key))
                    ->helperText(__('Stored encrypted on the server. Leave empty to keep the current key. Create it at openrouter.ai → Keys.'))
                    ->visible(fn (Get $get): bool => $get('provider') === 'openrouter'),
                TextInput::make('openai_api_key')->label(__('OpenAI API key'))->password()
                    ->placeholder(AiSetting::maskKey($settings->openai_api_key ?: config('services.openai.api_key')))
                    ->helperText(__('Optional: if empty, the team OpenAI key from the setup wizard (or OPENAI_API_KEY) is used.'))
                    ->visible(fn (Get $get): bool => $get('provider') === 'openai'),
            ]),
            Section::make(__('Models'))->columns(2)->schema([
                TextInput::make('writing_model')->label(__('Writing model (Spanish)'))->required()->datalist($models)
                    ->helperText(__('OpenRouter free models end in ":free".')),
                TextInput::make('translation_model')->label(__('Translation model (English, Turkish)'))->required()->datalist($models),
            ]),
            Section::make(__('Paid fallback'))->columns(2)
                ->description(__('Only used when the free model has no quota left, and only if an administrator authorises it. It has a cost.'))
                ->schema([
                    TextInput::make('paid_fallback_model')->label(__('Paid fallback model'))->placeholder('openai/gpt-4o-mini'),
                    Toggle::make('paid_fallback_authorized')->label(__('I authorise using the paid fallback model')),
                ]),
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
        $settings = AiSetting::current();

        foreach (['openrouter_api_key', 'openai_api_key'] as $key) {
            if (blank($data[$key] ?? null)) {
                unset($data[$key]);
            }
        }

        if (($data['paid_fallback_authorized'] ?? false) && ! $settings->paid_fallback_authorized) {
            $data['paid_fallback_authorized_by'] = auth()->user()?->email;
            $data['paid_fallback_authorized_at'] = now();
        }

        $settings->fill($data)->save();
        $this->form->fill($settings->only(['provider', 'writing_model', 'translation_model', 'paid_fallback_model', 'paid_fallback_authorized']));

        Notification::make()->title(__('Saved'))->success()->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('test')->label(__('Test connection'))->icon('heroicon-m-signal')
                ->action(function (): void {
                    try {
                        $lines = AiClient::make()->testConnection();
                        Notification::make()->title(__('Connection OK'))->body(implode("\n", $lines))->success()->persistent()->send();
                    } catch (AiUnavailable $e) {
                        Notification::make()->title(__('Connection failed'))->body($e->getMessage())->danger()->persistent()->send();
                    }
                }),
        ];
    }
}
