<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\ClartoModules;
use App\Processes\Models\Process;
use App\Processes\Models\ProcessVersion;
use App\Processes\ProcessPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * «Procesos y conocimiento» (docs/clarto/procesos.md). Registered from ClartoServiceProvider::register(); touches no
 * module file. Also registers the assistant's provider (Agente 3) when its branch is merged.
 */
final class ClartoProcessesServiceProvider extends ServiceProvider
{
    public const MODULE = 'procesos';

    /** Panel pages and resources (staff panel only). */
    public const PAGES = [
        'App\\Filament\\Processes\\Pages\\ProcessHome',
        'App\\Filament\\Processes\\Pages\\ProcessView',
        'App\\Filament\\Processes\\Pages\\ProcessEditor',
        'App\\Filament\\Processes\\Pages\\ProcessAdmin',
    ];

    public const RESOURCES = [
        'App\\Filament\\Processes\\Resources\\AreaResource',
        'App\\Filament\\Processes\\Resources\\PositionResource',
        'App\\Filament\\Processes\\Resources\\PositionAssignmentResource',
    ];

    public function register(): void
    {
        // Same approach as «Solicitud de mejoras»: added to the staff panel once the panels are registered.
        $add = function (\Filament\PanelRegistry $registry): void {
            $pages = array_values(array_filter(self::PAGES, 'class_exists'));
            $resources = array_values(array_filter(self::RESOURCES, 'class_exists'));
            foreach ($registry->all() as $panel) {
                if ($panel->getId() === 'admin' && ! in_array($pages[0] ?? null, $panel->getPages(), true)) {
                    $panel->pages($pages);
                    $panel->resources($resources);
                }
            }
            $components = $pages;
            foreach ($resources as $resource) {
                foreach ($resource::getPages() as $page) {
                    $components[] = $page->getPage();
                }
            }
            foreach ($components as $class) {
                [, $name] = app(\Livewire\Finder\Finder::class)->parseNamespaceAndName($class);
                \Livewire\Livewire::component($name, $class);
            }
        };
        $this->app->resolved(\Filament\PanelRegistry::class)
            ? $add($this->app->make(\Filament\PanelRegistry::class))
            : $this->app->resolving(\Filament\PanelRegistry::class, $add);

        if (class_exists(ClartoModules::class) && in_array(self::MODULE, ClartoModules::keys(), true)) {
            ClartoModules::implement(self::MODULE, settingsUrl: fn (): ?string => class_exists(self::PAGES[3]) ? rescue(fn () => (self::PAGES[3])::getUrl(), null, false) : null);
        }

        // «Preguntar a CLARTO» and recommendations (Agente 3, feat/procesos-asistente).
        foreach (['App\\Processes\\Assistant\\AssistantServiceProvider'] as $provider) {
            if (class_exists($provider)) {
                $this->app->register($provider);
            }
        }
    }

    public function boot(): void
    {
        Gate::policy(Process::class, ProcessPolicy::class);
        Gate::policy(ProcessVersion::class, ProcessPolicy::class);
        $this->loadRoutesFrom(__DIR__.'/../../routes/clarto-procesos.php');

        if ($this->app->runningInConsole()) {
            $this->commands(array_values(array_filter([
                'App\\Console\\Commands\\TestProcesses', 'App\\Console\\Commands\\ProcessesDemo', 'App\\Console\\Commands\\ProcessReviewReminders',
            ], 'class_exists')));
        }
        $this->callAfterResolving(\Illuminate\Console\Scheduling\Schedule::class, function (\Illuminate\Console\Scheduling\Schedule $schedule): void {
            $schedule->command('clarto:procesos-revisiones')->name('clarto-procesos-revisiones')->dailyAt('07:00')->onOneServer()->withoutOverlapping();
        });
    }
}
