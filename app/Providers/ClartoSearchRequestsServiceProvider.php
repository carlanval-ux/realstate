<?php

declare(strict_types=1);

namespace App\Providers;

use App\Collaborators\CollaboratorAccess;
use App\Filament\Pages\SearchRequestInbox;
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
                    $panel->pages([SearchRequestInbox::class]);
                }
            }
            $classes = [...array_map(fn ($page) => $page->getPage(), SearchRequestResource::getPages()), ...SearchRequestResource::getRelations(), SearchRequestInbox::class];
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
            $this->commands([\App\Console\Commands\TestSearchRequests::class]);
        }
    }
}
