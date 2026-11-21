<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\InboundMail\InboundMail;
use App\InboundMail\InboundMailAccess;
use App\InboundMail\InboundMails;
use App\InboundMail\InboundMailSettings;
use App\Models\ImprovementRequest;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Throwable;

/** «Ajustes del correo entrante» of the company: polling, interval, AI, owner of each area, retention; mailbox status. */
final class InboundMailSettingsPage extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $slug = 'correo-entrante-ajustes';

    protected static bool $shouldRegisterNavigation = false;   // reached from «Correo entrante» and «Módulos»

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        $teamId = Filament::getTenant()?->getKey();
        $user = auth()->user();

        return InboundMailAccess::canView($user, $teamId)
            && ((method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) || ($teamId !== null && ImprovementRequest::isTeamAdmin($user, $teamId)));
    }

    public function getTitle(): string
    {
        return __('correo.ajustes.titulo');
    }

    public function mount(): void
    {
        $s = InboundMailSettings::forTeam(Filament::getTenant()?->getKey());
        $this->form->fill(['polling' => $s->polling, 'interval_minutes' => $s->interval_minutes, 'ai' => $s->ai, 'ai_model' => $s->ai_model, 'retention_days' => $s->retention_days,
            'owners' => array_merge(array_fill_keys(InboundMail::OWNER_KEYS, null), (array) $s->area_owners)]);
    }

    public function form(Schema $schema): Schema
    {
        $x = fn (string $k): string => __('correo.ajustes.'.$k);
        $s = InboundMailSettings::forTeam(Filament::getTenant()?->getKey());
        $status = InboundMails::mailboxConfigured()
            ? __('correo.ajustes.buzon_ok', ['buzon' => config('correo-entrante.username'), 'host' => config('correo-entrante.host')])
            : __('correo.modulo.falta_buzon');
        $last = $s->last_poll_at ? __('correo.ajustes.ultimo', ['fecha' => $s->last_poll_at->timezone('Europe/Madrid')->format('d/m/Y H:i'), 'n' => $s->last_count]) : __('correo.ajustes.nunca');

        return $schema->statePath('data')->components([
            Section::make($x('buzon'))->schema([
                Text::make($status),
                Text::make($last.($s->last_error ? ' · '.__('correo.ajustes.error', ['e' => $s->last_error]) : '')),
                Text::make($x('solo_lectura')),
            ]),
            Section::make($x('funcionamiento'))->columns(2)->schema([
                Toggle::make('polling')->label($x('polling'))->helperText($x('polling_ayuda')),
                TextInput::make('interval_minutes')->label($x('interval'))->integer()->required()->minValue(1)->maxValue(60),
                Toggle::make('ai')->label($x('ai'))->helperText($x('ai_ayuda'))->live(),
                Select::make('ai_model')->label($x('ai_model'))->searchable()->visible(fn (\Filament\Schemas\Components\Utilities\Get $get) => (bool) $get('ai'))
                    ->placeholder(__('correo.ajustes.ai_model_defecto', ['m' => config('correo-entrante.ai_model')]))
                    ->options(fn (): array => array_filter([$s->ai_model => $s->ai_model]) + \App\Ai\AiClient::freeModels())
                    ->helperText($x('ai_model_ayuda')),
                TextInput::make('retention_days')->label($x('retention'))->integer()->required()->minValue(30)->maxValue(3650)->helperText($x('retention_ayuda')),
            ]),
            Section::make($x('responsables'))->description($x('responsables_ayuda'))->columns(3)->schema(
                collect(InboundMail::OWNER_KEYS)->map(fn (string $k) => TextInput::make('owners.'.$k)->label(__('correo.area.'.$k))->email()->maxLength(320))->all()
            ),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([\Filament\Schemas\Components\EmbeddedSchema::make('form')])->id('form')->livewireSubmitHandler('save')
                ->footer([Actions::make([Action::make('save')->label(__('Save'))->submit('save')])]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('test')->label(__('correo.ajustes.probar'))->icon('heroicon-m-signal')->color('gray')->action(function (): void {
                try {
                    $box = InboundMails::connect();
                    $info = $box->examine((string) config('correo-entrante.folder', 'INBOX'));
                    $box->logout();
                    Notification::make()->title(__('correo.ajustes.probar_ok', ['n' => $info['exists']]))->success()->send();
                } catch (Throwable $e) {
                    Notification::make()->title(__('correo.ajustes.probar_error', ['e' => $e->getMessage()]))->danger()->send();
                }
            }),
            Action::make('poll')->label(__('correo.ajustes.sondear'))->icon('heroicon-m-arrow-path')->color('gray')
                ->visible(fn () => InboundMailSettings::forTeam(Filament::getTenant()?->getKey())->polling)
                ->action(function (): void {
                    $r = InboundMails::poll(force: true);
                    Notification::make()->title(__('correo.sondeo.'.$r['estado'], ['n' => $r['nuevos'] ?? 0, 'e' => $r['error'] ?? '']))->{$r['estado'] === 'ok' ? 'success' : 'warning'}()->send();
                }),
            Action::make('inbox')->label(__('correo.menu'))->color('gray')->url(InboundMailInbox::getUrl()),
        ];
    }

    public function save(): void
    {
        $teamId = Filament::getTenant()?->getKey();
        $data = $this->form->getState();
        if ($data['polling'] && InboundMailSettings::query()->where('polling', true)->where('team_id', '!=', $teamId)->exists()) {
            Notification::make()->title(__('correo.error.otra_empresa'))->danger()->send();

            return;
        }
        InboundMailSettings::query()->updateOrCreate(['team_id' => $teamId], [
            'polling' => (bool) $data['polling'], 'interval_minutes' => (int) $data['interval_minutes'], 'ai' => (bool) $data['ai'],
            'ai_model' => filled($data['ai_model'] ?? null) ? (string) $data['ai_model'] : null, 'retention_days' => (int) $data['retention_days'],
            'area_owners' => array_filter(array_map(fn ($v) => filled($v) ? strtolower(trim((string) $v)) : null, (array) ($data['owners'] ?? [])), fn ($v) => $v !== null),
        ]);
        Notification::make()->title(__('correo.aviso.guardado'))->success()->send();
    }
}
