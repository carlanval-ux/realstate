<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\WhatsAppLine;
use App\WhatsApp\Phone;
use App\WhatsApp\Waha;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Image;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Throwable;

/**
 * Platform settings → Communications → WhatsApp: the company line of the current team. Link it by QR (fetched
 * server-side from WAHA and refreshed while waiting), see number and status, reconnect, test, disconnect, and edit
 * the reusable templates. Administrators only.
 */
final class WhatsAppSettings extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $slug = 'whatsapp';

    protected static ?int $navigationSort = 30;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    #[\Livewire\Attributes\Locked]
    public ?string $qr = null;

    public static function getNavigationLabel(): string
    {
        return 'WhatsApp';
    }

    public static function getNavigationGroup(): ?string
    {
        return __('filament.nav_groups.platform_settings');
    }

    public function getTitle(): string
    {
        return __('Communications').' · WhatsApp';
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && method_exists($user, 'isAdmin') && $user->isAdmin() && filled($user->current_team_id);
    }

    public function line(): WhatsAppLine
    {
        return WhatsAppLine::forTeam(auth()->user()->current_team_id);
    }

    public function mount(): void
    {
        $this->form->fill(['templates' => $this->line()->templates ?? WhatsAppLine::DEFAULT_TEMPLATES]);
        $this->pollLine(fromPoll: false);
    }

    /** Called on load and every few seconds while the line is starting or waiting for the QR scan. */
    public function pollLine(bool $fromPoll = true): void
    {
        $line = $this->line();
        $was = $line->status;
        try {
            $line->refreshFromWaha();
            $this->qr = $line->status === 'SCAN_QR_CODE' ? (new Waha)->qr($line->session) : null;
        } catch (Throwable $e) {
            $this->qr = null;
            report($e);
            if (! $fromPoll) {
                Notification::make()->title(__('The WhatsApp service does not answer'))->body(__('Check that the waha service is running on the server.'))->danger()->send();
            }

            return;
        }
        if ($fromPoll && $was !== WhatsAppLine::WORKING && $line->connected()) {
            Notification::make()->title(__('WhatsApp line connected: :phone', ['phone' => $line->phone]))->success()->send();
        }
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make(__('Reusable texts'))
                ->description(__('Offered when sending a WhatsApp from a contact or a file. Placeholders: {nombre}, {nombre_completo}, {referencia}, {direccion}, {fecha_visita}, {agente}, {empresa}, {enlace_firma}. A message cannot be sent while a placeholder is left unfilled.'))
                ->schema([
                    Repeater::make('templates')->hiddenLabel()->addActionLabel(__('Add text'))->reorderable()->collapsible()
                        ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                        ->schema([
                            TextInput::make('name')->label(__('Name'))->required()->maxLength(60),
                            Textarea::make('body')->label(__('Text'))->required()->rows(4)->maxLength(2000),
                        ]),
                ]),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Company line'))
                ->description(__('One WhatsApp number per team, linked as a device of the line\'s phone. Messages are sent and received through the internal WAHA service; nothing is exposed to the Internet.'))
                ->schema([
                    Text::make(fn (): string => $this->line()->statusLabel())->badge()->color(fn (): string => $this->line()->statusColor()),
                    Text::make(fn (): string => $this->line()->connected()
                        ? __('Number: :phone', ['phone' => $this->line()->phone ?? '—']).($this->line()->push_name ? ' · '.$this->line()->push_name : '')
                        : __('No number linked.')),
                    Text::make(__('On the line\'s phone open WhatsApp → Settings → Linked devices → Link a device, and scan this code. It renews itself every few seconds.'))
                        ->visible(fn (): bool => filled($this->qr)),
                    Image::make(fn (): string => (string) $this->qr, __('QR code to link WhatsApp'))->visible(fn (): bool => filled($this->qr)),
                    View::make('filament.whatsapp.poll')->visible(fn (): bool => in_array($this->line()->status, ['STARTING', 'SCAN_QR_CODE'], true)),
                ]),
            Form::make([EmbeddedSchema::make('form')])->id('form')->livewireSubmitHandler('save')
                ->footer([Actions::make([Action::make('save')->label(__('Save'))->submit('save')])]),
        ]);
    }

    public function save(): void
    {
        $this->line()->update(['templates' => array_values($this->form->getState()['templates'] ?? [])]);
        Notification::make()->title(__('Saved'))->success()->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('link')->label(__('Link line'))->icon('heroicon-m-qr-code')
                ->visible(fn (): bool => in_array($this->line()->status, ['SIN_CREAR', 'STOPPED', 'FAILED'], true))
                ->action(fn () => $this->lineAction(fn (Waha $w, WhatsAppLine $l) => $w->start($l->session))),
            Action::make('reconnect')->label(__('Reconnect'))->icon('heroicon-m-arrow-path')->color('gray')
                ->visible(fn (): bool => ! in_array($this->line()->status, ['SIN_CREAR', 'STOPPED'], true))
                ->action(fn () => $this->lineAction(fn (Waha $w, WhatsAppLine $l) => $w->restart($l->session))),
            Action::make('test')->label(__('Send test'))->icon('heroicon-m-paper-airplane')->color('gray')
                ->visible(fn (): bool => $this->line()->connected())
                ->schema([
                    TextInput::make('phone')->label(__('Phone'))->required()->tel()->helperText(__('With country code if it is not Spanish, e.g. +351…')),
                    Textarea::make('text')->label(__('Text'))->required()->default(fn (): string => __('Test message from :app.', ['app' => config('app.name')])),
                ])
                ->action(function (array $data): void {
                    $phone = Phone::e164($data['phone']);
                    try {
                        throw_if($phone === null, new \RuntimeException(__('The recipient is not a valid phone number.')));
                        (new Waha)->sendText($this->line()->session, $phone, $data['text']);
                        Notification::make()->title(__('Test sent to :phone', ['phone' => $phone]))->success()->send();
                    } catch (Throwable $e) {
                        Notification::make()->title(__('The message could not be sent'))->body(mb_strimwidth($e->getMessage(), 0, 300, '…'))->danger()->persistent()->send();
                    }
                }),
            Action::make('disconnect')->label(__('Disconnect'))->icon('heroicon-m-power')->color('danger')
                ->visible(fn (): bool => in_array($this->line()->status, ['WORKING', 'SCAN_QR_CODE', 'STARTING', 'FAILED'], true))
                ->requiresConfirmation()
                ->modalDescription(__('The panel stops sending and receiving WhatsApp and the device is removed from the phone\'s linked devices. The history stays in the panel. To use it again, link the line with a new QR.'))
                ->action(fn () => $this->lineAction(fn (Waha $w, WhatsAppLine $l) => $w->logout($l->session))),
        ];
    }

    /** @param callable(Waha, WhatsAppLine): void $work */
    private function lineAction(callable $work): void
    {
        try {
            $work(new Waha, $this->line());
        } catch (Throwable $e) {
            report($e);
            Notification::make()->title(__('The WhatsApp service refused the action'))->body(mb_strimwidth($e->getMessage(), 0, 300, '…'))->danger()->persistent()->send();
        }
        $this->pollLine(fromPoll: false);
    }
}
