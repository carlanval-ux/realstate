<?php

declare(strict_types=1);

namespace App\Providers;

use App\Console\Commands\TestCompanyDocuments;
use App\Documents\CompanyDocument;
use App\Documents\CompanyDocumentFolder;
use App\Filament\Resources\CompanyDocumentFolderResource;
use App\Filament\Resources\CompanyDocumentResource;
use App\Policies\CompanyDocumentPolicy;
use Filament\PanelRegistry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Livewire\Finder\Finder;
use Livewire\Livewire;

/**
 * «Documentación de la empresa» (docs/clarto/gestor-documental.md): documents and folders resources on the staff panel
 * /admin only, policy, file route, daily expiry notice (bell + e-mail) and the test command. Registered from ClartoServiceProvider; no module file.
 */
final class ClartoCompanyDocumentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Same approach as «Solicitud de mejoras»: added once the module panels are registered.
        $add = function (PanelRegistry $registry): void {
            foreach ($registry->all() as $panel) {
                if ($panel->getId() === 'admin' && ! in_array(CompanyDocumentResource::class, $panel->getResources(), true)) {
                    $panel->resources([CompanyDocumentResource::class, CompanyDocumentFolderResource::class]);
                }
            }
            foreach ([...CompanyDocumentResource::getPages(), ...CompanyDocumentFolderResource::getPages()] as $page) {
                [, $name] = app(Finder::class)->parseNamespaceAndName($page->getPage());
                Livewire::component($name, $page->getPage());
            }
        };
        $this->app->resolved(PanelRegistry::class)
            ? $add($this->app->make(PanelRegistry::class))
            : $this->app->resolving(PanelRegistry::class, $add);
    }

    public function boot(): void
    {
        Gate::policy(CompanyDocument::class, CompanyDocumentPolicy::class);
        Gate::policy(CompanyDocumentFolder::class, CompanyDocumentPolicy::class);
        $this->loadRoutesFrom(__DIR__.'/../../routes/clarto-documentacion.php');
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->call(fn () => CompanyDocument::notifyExpiring())->name('clarto-documentacion-caducidad')->dailyAt('08:00')->onOneServer()->withoutOverlapping();
        });

        if ($this->app->runningInConsole()) {
            $this->commands([TestCompanyDocuments::class]);
        }
    }
}
