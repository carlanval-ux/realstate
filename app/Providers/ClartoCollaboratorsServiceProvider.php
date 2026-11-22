<?php

declare(strict_types=1);

namespace App\Providers;

use App\Collaborators\Collaborations;
use App\Collaborators\CollaboratorNotices;
use App\Collaborators\Import\CollaboratorImport;
use App\Collaborators\Import\ListingAi;
use App\Collaborators\Import\OpenRouterListingAi;
use App\Console\Commands\ImportCollaboratorListings;
use App\Console\Commands\TestCollaboratorImport;
use App\Console\Commands\TestCollaborators;
use App\Filament\Collaborators\PropertyCollaborators;
use App\Filament\Collaborators\PropertyUpdates;
use App\Filament\Resources\CollaboratorResource;
use App\Filament\Resources\CollaboratorResource\RelationManagers\AssignmentsRelationManager;
use App\Filament\Resources\CollaboratorResource\RelationManagers\NoticesRelationManager;
use App\Http\Middleware\CollaboratorFence;
use App\Modules\ClartoModules;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * «Colaboradores» (contract: orquestacion/colaboradores/contrato.md). Registered from ClartoServiceProvider::register();
 * the only module file it touches is PropertyResource (one tab line).
 */
final class ClartoCollaboratorsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->register(CollaboratorPanelProvider::class);

        // Staff screen «Colaboradores», added to /admin once the panels are registered (same approach as Valoraciones).
        $add = function (\Filament\PanelRegistry $registry): void {
            foreach ($registry->all() as $panel) {
                if ($panel->getId() === 'admin' && ! in_array(CollaboratorResource::class, $panel->getResources(), true)) {
                    $panel->resources([CollaboratorResource::class]);
                }
            }
            $components = [...array_map(fn ($page) => $page->getPage(), CollaboratorResource::getPages()), AssignmentsRelationManager::class, NoticesRelationManager::class];
            foreach ($components as $class) {
                [, $name] = app(\Livewire\Finder\Finder::class)->parseNamespaceAndName($class);
                \Livewire\Livewire::component($name, $class);
            }
        };
        $this->app->resolved(\Filament\PanelRegistry::class)
            ? $add($this->app->make(\Filament\PanelRegistry::class))
            : $this->app->resolving(\Filament\PanelRegistry::class, $add);

        $this->app->bind(ListingAi::class, fn ($app) => $app->make((string) config('importacion.ia', OpenRouterListingAi::class)));

        if (class_exists(ClartoModules::class) && in_array(Collaborations::MODULE, ClartoModules::keys(), true)) {
            ClartoModules::implement(Collaborations::MODULE);
        }
    }

    public function boot(Router $router): void
    {
        \Livewire\Livewire::component('clarto-property-collaborators', PropertyCollaborators::class);
        \Livewire\Livewire::component('clarto-property-updates', PropertyUpdates::class);
        \Livewire\Livewire::component('clarto-property-photo-cleanups', \App\Filament\Collaborators\PropertyPhotoCleanups::class);

        // Collaborators never leave their area (web pages and API).
        $router->pushMiddlewareToGroup('web', CollaboratorFence::class);
        $router->pushMiddlewareToGroup('api', CollaboratorFence::class);
        $this->loadRoutesFrom(__DIR__.'/../../routes/clarto-colaboradores.php');

        // Choosing the password from the invitation activates the collaborator.
        Event::listen(PasswordReset::class, fn (PasswordReset $event) => Collaborations::activated($event->user));

        // Notices that failed (provider down, WhatsApp line disconnected) are retried; access is checked again first.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->call(fn () => CollaboratorNotices::retryOpen())->name('clarto-colaboradores-avisos')->everyTenMinutes()->onOneServer()->withoutOverlapping();
            // Property import (only collaborators with written authorization; a crawl can take minutes → own process).
            $schedule->command('clarto:importar-colaboradores --diario')->dailyAt('05:30')->runInBackground()->onOneServer()->withoutOverlapping(240);
            $schedule->command('clarto:importar-colaboradores')->everyMinute()->runInBackground()->onOneServer()->withoutOverlapping(240)
                ->when(fn (): bool => (bool) rescue(fn () => CollaboratorImport::query()->whereNotNull('requested_at')->exists(), false, false));
        });

        if ($this->app->runningInConsole()) {
            $this->commands([TestCollaborators::class, ImportCollaboratorListings::class, TestCollaboratorImport::class]);
        }
    }
}
