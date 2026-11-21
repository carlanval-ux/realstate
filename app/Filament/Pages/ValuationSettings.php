<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Valuations\GhlValuationSync;
use App\Valuations\InHouseEstimator;
use App\Valuations\ValuationSetting;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;

/**
 * Platform settings → Valoraciones automáticas: AVM providers (off / simulated / HTTP) and their credentials, stored
 * encrypted and never shown back (a blank field keeps the saved value), plus the state of the API key, GoHighLevel
 * and the price data. Administrators only.
 */
final class ValuationSettings extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calculator';

    protected static ?string $slug = 'ajustes-valoraciones';

    protected static ?int $navigationSort = 45;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('clarto.valoraciones.ajustes.titulo');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('filament.nav_groups.platform_settings');
    }

    public function getTitle(): string
    {
        return __('clarto.valoraciones.ajustes.titulo');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && method_exists($user, 'isAdmin') && $user->isAdmin();
    }

    public function mount(): void
    {
        $settings = ValuationSetting::current();
        $this->form->fill(collect(ValuationSetting::PROVIDERS)->flatMap(fn (string $p): array => [
            "{$p}_driver" => $settings->getAttribute("{$p}_driver"), "{$p}_url" => $settings->getAttribute("{$p}_url"), "{$p}_token_url" => $settings->getAttribute("{$p}_token_url"),
        ])->all());
    }

    public function form(Schema $schema): Schema
    {
        $settings = ValuationSetting::current();
        $sections = [];
        foreach (ValuationSetting::PROVIDERS as $p) {
            $effective = $settings->provider($p);
            $stored = fn (string $field): string => filled($settings->getAttribute("{$p}_{$field}")) ? __('clarto.valoraciones.ajustes.guardada') : (filled(config("valoraciones.proveedores.{$p}.{$field}")) ? __('clarto.valoraciones.ajustes.en_servidor') : __('clarto.valoraciones.ajustes.sin_valor'));
            $sections[] = Section::make($effective['nombre'])
                ->description(__('clarto.valoraciones.ajustes.efectivo', ['driver' => __('clarto.valoraciones.ajustes.drivers.'.$effective['driver'])]).($settings->missing($p) ? ' · '.__('clarto.valoraciones.ajustes.falta', ['que' => $settings->missing($p)]) : ''))
                ->columns(2)->schema([
                    Select::make("{$p}_driver")->label(__('clarto.valoraciones.ajustes.modo'))->placeholder(__('clarto.valoraciones.ajustes.segun_servidor'))
                        ->options(collect(ValuationSetting::DRIVERS)->mapWithKeys(fn ($d) => [$d => __('clarto.valoraciones.ajustes.drivers.'.$d)])->all()),
                    TextInput::make("{$p}_url")->label(__('clarto.valoraciones.ajustes.url'))->url()->maxLength(250),
                    TextInput::make("{$p}_token_url")->label(__('clarto.valoraciones.ajustes.token_url'))->url()->maxLength(250),
                    TextInput::make("{$p}_client_id")->label(__('clarto.valoraciones.ajustes.client_id'))->password()->autocomplete('off')->maxLength(250)->helperText($stored('client_id')),
                    TextInput::make("{$p}_secret")->label(__('clarto.valoraciones.ajustes.secret'))->password()->autocomplete('new-password')->maxLength(500)->helperText($stored('secret')),
                ]);
        }

        return $schema->statePath('data')->components($sections);
    }

    public function content(Schema $schema): Schema
    {
        $data = InHouseEstimator::data();

        return $schema->components([
            Section::make(__('clarto.valoraciones.ajustes.estado'))->schema([
                Text::make(__('clarto.valoraciones.ajustes.api', ['estado' => filled(config('valoraciones.api_key')) ? __('clarto.valoraciones.ajustes.configurada') : __('clarto.valoraciones.ajustes.no_configurada')])),
                Text::make(__('clarto.valoraciones.ajustes.ghl_estado', ['estado' => GhlValuationSync::configured() ? __('clarto.valoraciones.ajustes.configurada') : __('clarto.valoraciones.ajustes.no_configurada')])),
                Text::make(__('clarto.valoraciones.ajustes.datos', ['version' => $data['version'] ?? '—', 'motor' => InHouseEstimator::VERSION, 'n' => count((array) ($data['ambitos'] ?? []))])),
            ]),
            Form::make([EmbeddedSchema::make('form')])->id('form')->livewireSubmitHandler('save')
                ->footer([Actions::make([Action::make('save')->label(__('Save'))->submit('save')])]),
        ]);
    }

    public function save(): void
    {
        abort_unless(self::canAccess(), 403);
        $state = $this->form->getState();
        $settings = ValuationSetting::current();
        foreach (ValuationSetting::PROVIDERS as $p) {
            $settings->setAttribute("{$p}_driver", $state["{$p}_driver"] ?? null);
            $settings->setAttribute("{$p}_url", $state["{$p}_url"] ?? null);
            $settings->setAttribute("{$p}_token_url", $state["{$p}_token_url"] ?? null);
            foreach (['client_id', 'secret'] as $secret) {
                if (filled($state["{$p}_{$secret}"] ?? null)) {   // blank keeps what is stored
                    $settings->setAttribute("{$p}_{$secret}", $state["{$p}_{$secret}"]);
                }
            }
        }
        $settings->save();
        $this->mount();
        Notification::make()->title(__('Saved'))->success()->send();
    }

    protected function getHeaderActions(): array
    {
        return collect(ValuationSetting::PROVIDERS)->map(fn (string $p): Action => Action::make("borrar_{$p}")
            ->label(__('clarto.valoraciones.ajustes.borrar', ['p' => config("valoraciones.proveedores.{$p}.nombre")]))->color('danger')->outlined()->requiresConfirmation()
            ->visible(fn (): bool => filled(ValuationSetting::current()->getAttribute("{$p}_secret")) || filled(ValuationSetting::current()->getAttribute("{$p}_client_id")))
            ->action(function () use ($p): void {
                $settings = ValuationSetting::current();
                $settings->forceFill(["{$p}_client_id" => null, "{$p}_secret" => null])->save();
                Notification::make()->title(__('Saved'))->success()->send();
            }))->all();
    }
}
