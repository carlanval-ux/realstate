<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\MailSetting;
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
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Platform settings → Outgoing mail: the mailbox the panel sends from (invitations, access links, notices). */
final class MailSettings extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-envelope';

    protected static ?string $slug = 'correo-saliente';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('Outgoing mail');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('filament.nav_groups.platform_settings');
    }

    public function getTitle(): string
    {
        return __('Outgoing mail');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && method_exists($user, 'isAdmin') && $user->isAdmin();
    }

    public function mount(): void
    {
        $this->form->fill(MailSetting::current()->only(['enabled', 'host', 'port', 'encryption', 'username', 'from_address', 'from_name']));
    }

    public function form(Schema $schema): Schema
    {
        $settings = MailSetting::current();

        return $schema->statePath('data')->components([
            Section::make(__('Mailbox'))->columns(2)
                ->description(__('E-mails of the panel (invitations, access links, notices) are sent from this mailbox. Hostinger: smtp.hostinger.com, port 465, SSL, user = full mailbox address.'))
                ->schema([
                    Toggle::make('enabled')->label(__('Send through this mailbox'))->columnSpanFull()
                        ->helperText(__('Off: the server default is used (test mailbox on staging).')),
                    TextInput::make('host')->label(__('SMTP server'))->required(),
                    TextInput::make('port')->label(__('Port'))->numeric()->required(),
                    Select::make('encryption')->label(__('Encryption'))->options(['ssl' => 'SSL (465)', 'tls' => 'STARTTLS (587)', 'none' => __('None')])->required(),
                    TextInput::make('username')->label(__('User'))->email()->required(),
                    TextInput::make('password')->label(__('Password'))->password()
                        ->placeholder(filled($settings->password) ? '••••••••' : __('Not set'))
                        ->helperText(__('Stored encrypted on the server. Leave empty to keep the current one.')),
                    TextInput::make('from_address')->label(__('Sender address'))->email()->placeholder(__('Same as the user')),
                    TextInput::make('from_name')->label(__('Sender name')),
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
        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        MailSetting::current()->fill($data)->save();
        MailSetting::apply();
        try {
            Artisan::call('queue:restart'); // queued mail picks up the new mailbox
        } catch (Throwable) {
        }

        Notification::make()->title(__('Saved'))->success()->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('test')->label(__('Send test e-mail'))->icon('heroicon-m-paper-airplane')
                ->action(function (): void {
                    MailSetting::apply();
                    $to = (string) auth()->user()?->email;
                    try {
                        Mail::raw(__('Test e-mail from :app. If you are reading this, outgoing mail works.', ['app' => config('app.name')]),
                            fn ($m) => $m->to($to)->subject(__('Test e-mail from :app', ['app' => config('app.name')])));
                        Notification::make()->title(__('Test e-mail sent to :email', ['email' => $to]))->success()->send();
                    } catch (Throwable $e) {
                        Notification::make()->title(__('The e-mail could not be sent'))->body(mb_strimwidth($e->getMessage(), 0, 300, '…'))->danger()->persistent()->send();
                    }
                }),
        ];
    }
}
