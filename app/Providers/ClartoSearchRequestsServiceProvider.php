<?php

declare(strict_types=1);

namespace App\Providers;

use App\Collaborators\CollaboratorAccess;
use App\Collaborators\CollaboratorNotice;
use App\SearchRequests\SearchRequestLink;
use App\Filament\Pages\SearchRequestInbox;
use App\Filament\Pages\SearchRequestSettingsPage;
use App\Filament\Resources\SearchRequestResource;
use App\Models\Communication;
use App\Modules\ClartoModules;
use App\SearchRequests\SearchRequest;
use App\SearchRequests\SearchRequestAccess;
use App\SearchRequests\SearchRequestResponse;
use App\SearchRequests\SearchRequests;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * «Solicitudes de inmuebles» (docs/clarto/solicitudes.md): internal inbox of property searches sent to external
 * collaborators by e-mail and WhatsApp, their answers (panel, e-mail, WhatsApp), the «to link» queue, comparison and
 * property from a proposal. Built on the «Colaboradores» contract (access scope, notices, collaborator panel).
 * Registered from ClartoServiceProvider; touches no module file.
 */
final class ClartoSearchRequestsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Staff panel: same approach as «Valoraciones» (added once the panels are registered).
        $add = function (\Filament\PanelRegistry $registry): void {
            foreach ($registry->all() as $panel) {
                if ($panel->getId() === 'admin' && ! in_array(SearchRequestResource::class, $panel->getResources(), true)) {
                    $panel->resources([SearchRequestResource::class]);
                    $panel->pages([SearchRequestInbox::class, SearchRequestSettingsPage::class]);
                }
            }
            $classes = [...array_map(fn ($page) => $page->getPage(), SearchRequestResource::getPages()), ...SearchRequestResource::getRelations(), SearchRequestInbox::class, SearchRequestSettingsPage::class];
            foreach ($classes as $class) {
                [, $name] = app(\Livewire\Finder\Finder::class)->parseNamespaceAndName($class);
                \Livewire\Livewire::component($name, $class);
            }
        };
        $this->app->resolved(\Filament\PanelRegistry::class)
            ? $add($this->app->make(\Filament\PanelRegistry::class))
            : $this->app->resolving(\Filament\PanelRegistry::class, $add);

        if (in_array(SearchRequests::MODULE, ClartoModules::keys(), true)) {
            ClartoModules::implement(SearchRequests::MODULE,
                missingConfiguration: fn (): ?string => blank(config('solicitudes.inbound_mail_key'))
                    ? __('solicitudes.modulo.falta_correo')
                    : null,
                settingsUrl: fn (): ?string => null,
            );
        }
    }

    public function boot(): void
    {
        // What collaborators see: requests sent to them and their own answers (server-side, every screen and file).
        CollaboratorAccess::register(SearchRequest::class, new SearchRequestAccess);
        CollaboratorAccess::register(SearchRequestResponse::class, new SearchRequestAccess);

        $this->loadRoutesFrom(__DIR__.'/../../routes/clarto-solicitudes.php');

        // Answer links /s/*: per visitor and for everyone (same approach as the report links /r/*).
        \Illuminate\Support\Facades\RateLimiter::for('solicitud-enlace', fn (\Illuminate\Http\Request $request): array => [
            \Illuminate\Cache\RateLimiting\Limit::perMinute(30)->by('solicitud-enlace:ip:'.$request->ip()),
            \Illuminate\Cache\RateLimiting\Limit::perMinute(600)->by('solicitud-enlace:global'),
        ]);
        // The code of an answer link exists in clear only in the message being delivered: once a message is sent (or
        // cancelled) its copy and the communications register keep «…/s/AbCd…». Never in Telescope either.
        CollaboratorNotice::saved(fn (CollaboratorNotice $n) => rescue(fn () => self::redactLinks($n), report: true));
        if (class_exists(\Laravel\Telescope\Telescope::class)) {
            \Laravel\Telescope\Telescope::filter(fn (\Laravel\Telescope\IncomingEntry $entry): bool => ! preg_match('#\\\\?/s\\\\?/[A-Za-z0-9_-]{22}#', (string) json_encode($entry->content)));
        }
        $this->callAfterResolving(\Illuminate\Console\Scheduling\Schedule::class, function (\Illuminate\Console\Scheduling\Schedule $schedule): void {
            $schedule->command('clarto:solicitudes-plazos')->name('clarto-solicitudes-plazos')->hourly()->onOneServer()->withoutOverlapping();
        });

        // Reply threading: Message-ID of our e-mails; WhatsApp replies of collaborators. Never breaks the mailer or the webhook.
        Event::listen(MessageSending::class, fn (MessageSending $e) => rescue(fn () => SearchRequests::stampMessageId($e), report: true));
        Communication::created(function (Communication $c): void {
            if ($c->channel !== 'whatsapp' || $c->direction !== Communication::IN) {
                return;
            }
            try {
                SearchRequests::inbound($c);
            } catch (Throwable $e) {
                report($e);
            }
        });

        if ($this->app->runningInConsole()) {
            $this->commands([\App\Console\Commands\TestSearchRequests::class, \App\Console\Commands\RunSearchRequestDeadlines::class]);
        }
    }

    private static function redactLinks(CollaboratorNotice $notice): void
    {
        if ($notice->subject_type !== (new SearchRequest)->getMorphClass()) {
            return;
        }
        Communication::query()->where('related_type', $notice->getMorphClass())->where('related_id', (string) $notice->getKey())
            ->whereNotIn('status', ['pendiente', 'error'])->where('body', 'like', '%/s/%')->get()
            ->each(fn (Communication $c) => $c->forceFill(['body' => SearchRequestLink::redact((string) $c->body)])->saveQuietly());
        if (in_array($notice->status, [CollaboratorNotice::SENT, CollaboratorNotice::CANCELLED], true) && str_contains((string) $notice->email_body.json_encode($notice->whatsapp_blocks), '/s/')) {
            $notice->forceFill([
                'email_body' => SearchRequestLink::redact((string) $notice->email_body),
                'whatsapp_blocks' => array_map(fn ($b) => SearchRequestLink::redact((string) $b), (array) $notice->whatsapp_blocks),
            ])->saveQuietly();
        }
    }
}
