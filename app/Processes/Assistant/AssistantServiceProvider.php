<?php

declare(strict_types=1);

namespace App\Processes\Assistant;

use App\Filament\Processes\Assistant\AskClarto;
use App\Filament\Processes\Assistant\AssistantSettingsPage;
use App\Filament\Processes\Assistant\QueriesTable;
use App\Filament\Processes\Assistant\RecommendationsTable;
use App\Models\AiSetting;
use App\Modules\ClartoModules;
use App\Processes\Events\ProcessArchived;
use App\Processes\Events\ProcessVersionPublished;
use App\Processes\Models\ProcessVersion;
use App\Processes\ProcessExtensions;
use App\Processes\Recommendations\Recommendation;
use App\Processes\Recommendations\Recommendations;
use Filament\Facades\Filament;
use Filament\PanelRegistry;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * «Preguntar a CLARTO» and «Recomendaciones de documentación» (Agente 3). Registered by ClartoProcessesServiceProvider
 * (Agente 2) with class_exists; touches no shared file: pages are added to the staff panel once it is registered, the
 * tabs and the button go through ProcessExtensions, and the index follows the lifecycle events.
 */
final class AssistantServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $add = function (PanelRegistry $registry): void {
            foreach ($registry->all() as $panel) {
                if ($panel->getId() === 'admin' && ! in_array(AskClarto::class, $panel->getPages(), true)) {
                    $panel->pages([AskClarto::class, AssistantSettingsPage::class]);
                }
            }
            foreach ([AskClarto::class, AssistantSettingsPage::class, RecommendationsTable::class, QueriesTable::class] as $class) {
                [, $name] = app(\Livewire\Finder\Finder::class)->parseNamespaceAndName($class);
                \Livewire\Livewire::component($name, $class);
            }
        };
        $this->app->resolved(PanelRegistry::class)
            ? $add($this->app->make(PanelRegistry::class))
            : $this->app->resolving(PanelRegistry::class, $add);

        if (in_array(AssistantSettings::MODULE, ClartoModules::keys(), true)) {
            ClartoModules::implement(AssistantSettings::MODULE,
                missingConfiguration: fn (): ?string => blank(AiSetting::current()->apiKey()) ? __('asistente.modulo.falta_ia') : null,
                settingsUrl: function (): ?string {
                    try {
                        return Filament::getTenant() !== null ? AssistantSettingsPage::getUrl() : null;
                    } catch (Throwable) {
                        return null;
                    }
                },
            );
        }
    }

    public function boot(): void
    {
        Event::listen(ProcessVersionPublished::class, ReindexProcess::class);
        Event::listen(ProcessArchived::class, ReindexProcess::class);

        ProcessExtensions::assistant(function (?string $question, ?ProcessVersion $context): ?string {
            return AskClarto::canAccess() && AssistantSettings::activeFor(Filament::getTenant()?->getKey())
                ? AskClarto::getUrl(array_filter(['q' => $question, 'v' => $context?->getKey()]))
                : null;
        });
        ProcessExtensions::adminTab('recomendaciones', fn (): Tab => Tab::make(__('asistente.recomendaciones.titulo'))
            ->icon('heroicon-o-light-bulb')->visible(fn (): bool => RecommendationsTable::allowed())
            ->badge(fn (): ?int => Recommendation::query()->where('team_id', Filament::getTenant()?->getKey())->where('status', 'pendiente')->count() ?: null)
            ->schema([Livewire::make(RecommendationsTable::class)]), 50);
        ProcessExtensions::adminTab('consultas', fn (): Tab => Tab::make(__('asistente.consultas.titulo'))
            ->icon('heroicon-o-chat-bubble-left-ellipsis')->visible(fn (): bool => RecommendationsTable::allowed())
            ->schema([Livewire::make(QueriesTable::class)]), 60);

        if ($this->app->runningInConsole()) {
            $this->commands([Console\ReindexCommand::class, Console\TestAssistantCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('clarto:asistente-reindexar')->name('clarto-asistente-reindexar')->dailyAt('03:10')->onOneServer()->withoutOverlapping();
            $schedule->call(fn (): int => AssistantQuery::prune())->name('clarto-asistente-limpiar')->dailyAt('03:20')->onOneServer();
            $schedule->call(function (): void {
                foreach (DB::table('clarto_process_map_entries')->distinct()->pluck('team_id') as $teamId) {
                    Recommendations::generate($teamId);
                }
            })->name('clarto-procesos-recomendaciones')->dailyAt('03:30')->onOneServer();
        });
    }
}
