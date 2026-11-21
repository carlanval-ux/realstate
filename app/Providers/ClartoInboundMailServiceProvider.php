<?php

declare(strict_types=1);

namespace App\Providers;

use App\Filament\Pages\InboundMailInbox;
use App\Filament\Pages\InboundMailSettingsPage;
use App\InboundMail\InboundMails;
use App\Modules\ClartoModules;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * «Correo entrante» (docs/clarto/correo-entrante.md): triage of the company mailbox. Registered from
 * ClartoServiceProvider; touches no module file.
 */
final class ClartoInboundMailServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $add = function (\Filament\PanelRegistry $registry): void {
            foreach ($registry->all() as $panel) {
                if ($panel->getId() === 'admin' && ! in_array(InboundMailInbox::class, $panel->getPages(), true)) {
                    $panel->pages([InboundMailInbox::class, InboundMailSettingsPage::class]);
                }
            }
            foreach ([InboundMailInbox::class, InboundMailSettingsPage::class] as $class) {
                [, $name] = app(\Livewire\Finder\Finder::class)->parseNamespaceAndName($class);
                \Livewire\Livewire::component($name, $class);
            }
        };
        $this->app->resolved(\Filament\PanelRegistry::class)
            ? $add($this->app->make(\Filament\PanelRegistry::class))
            : $this->app->resolving(\Filament\PanelRegistry::class, $add);

        $this->app->bind(\App\InboundMail\MailClassifier::class, fn ($app) => $app->make((string) config('correo-entrante.classifier', \App\InboundMail\OpenRouterMailClassifier::class)));

        if (in_array(InboundMails::MODULE, ClartoModules::keys(), true)) {
            ClartoModules::implement(InboundMails::MODULE,
                missingConfiguration: fn (): ?string => InboundMails::mailboxConfigured() ? null : __('correo.modulo.falta_buzon'),
                settingsUrl: fn (): ?string => rescue(fn () => InboundMailSettingsPage::getUrl(), null, report: false),
            );
        }
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/clarto-correo.php');

        $this->callAfterResolving(\Illuminate\Console\Scheduling\Schedule::class, function (\Illuminate\Console\Scheduling\Schedule $schedule): void {
            // Every minute; the command itself waits for the interval set in the panel (polling off = nothing happens).
            $schedule->command('clarto:correo-sondear')->name('clarto-correo-sondear')->everyMinute()->onOneServer()->withoutOverlapping(15);
        });

        // Our e-mails carry a Message-ID that replies point back to. Never breaks the mailer.
        Event::listen(MessageSending::class, fn (MessageSending $e) => rescue(fn () => InboundMails::stampMessageId($e), report: true));

        if ($this->app->runningInConsole()) {
            $this->commands([\App\Console\Commands\PollInboundMail::class, \App\Console\Commands\TestInboundMail::class]);
        }
    }
}
