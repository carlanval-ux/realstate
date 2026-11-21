<?php

declare(strict_types=1);

namespace App\Providers;

use App\Acquisition\GhlHandoff;
use App\Console\Commands\TestValuations;
use App\Filament\Pages\ValuationSettings;
use App\Filament\Resources\ValuationRequestResource;
use App\Modules\ClartoModules;
use App\Valuations\GhlValuationSync;
use App\Valuations\ValuationAccess;
use App\Valuations\ValuationRequest;
use App\Valuations\ValuationSetting;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Liberu\RealEstate\Properties\Models\Property;
use Throwable;

/**
 * «Valoraciones automáticas» (docs/clarto/valoraciones.md): API for the website, Catastro, estimate, PDF, GoHighLevel,
 * the panel list and the provider settings. Registered from ClartoServiceProvider; touches no module file.
 */
final class ClartoValuationsServiceProvider extends ServiceProvider
{
    public const MODULE = 'valoraciones';

    public function register(): void
    {
        // Same approach as «Solicitud de mejoras»: added to the staff panel once the panels are registered.
        $add = function (\Filament\PanelRegistry $registry): void {
            foreach ($registry->all() as $panel) {
                if ($panel->getId() !== 'admin') {
                    continue;
                }
                if (! in_array(ValuationRequestResource::class, $panel->getResources(), true)) {
                    $panel->resources([ValuationRequestResource::class]);
                    $panel->pages([ValuationSettings::class]);
                }
            }
            foreach ([...array_map(fn ($page) => $page->getPage(), ValuationRequestResource::getPages()), ValuationSettings::class] as $class) {
                [, $name] = app(\Livewire\Finder\Finder::class)->parseNamespaceAndName($class);
                \Livewire\Livewire::component($name, $class);
            }
        };
        $this->app->resolved(\Filament\PanelRegistry::class)
            ? $add($this->app->make(\Filament\PanelRegistry::class))
            : $this->app->resolving(\Filament\PanelRegistry::class, $add);

        if (class_exists(ClartoModules::class) && in_array(self::MODULE, ClartoModules::keys(), true)) {
            ClartoModules::implement(self::MODULE,
                missingConfiguration: function (): ?string {
                    $missing = array_filter([
                        blank(config('valoraciones.api_key')) ? 'VALORACIONES_API_KEY' : null,
                        GhlValuationSync::configured() ? null : 'GHL_API_TOKEN / GHL_LOCATION_ID / GHL_PIPELINE_ID / GHL_PIPELINE_STAGE_ID',
                        ...array_map(fn (string $p): ?string => ValuationSetting::current()->missing($p), ValuationSetting::PROVIDERS),
                    ]);

                    return $missing === [] ? null : __('clarto.valoraciones.modulo.falta', ['que' => implode('; ', $missing)]);
                },
                settingsUrl: fn (): string => ValuationSettings::getUrl(),
            );
        }
    }

    public function boot(): void
    {
        ValuationAccess::defineGates();

        RateLimiter::for('valoraciones', fn (Request $request): array => [
            Limit::perMinute(max(1, (int) config('valoraciones.limite_por_ip', 20)))->by('valoraciones:ip:'.($request->header('X-Cliente-Ip') ?: $request->ip())),
            Limit::perMinute(max(1, (int) config('valoraciones.limite_global', 300)))->by('valoraciones:global'),
        ]);

        $this->loadRoutesFrom(__DIR__.'/../../routes/clarto-valoraciones.php');

        // Won in GHL → GhlHandoff opens owner + draft property (+ TARMIO task): the draft property gets the valuation.
        GhlHandoff::saved(function (GhlHandoff $handoff): void {
            if ($handoff->status !== 'procesado' && $handoff->status !== 'pendiente') {
                return;
            }
            try {
                $valuation = ValuationRequest::query()->where('ghl_opportunity_id', $handoff->opportunity_id)->first();
                $property = $handoff->property_id ? Property::query()->find($handoff->property_id) : null;
                if ($valuation === null || $property === null || $valuation->property_id === $property->getKey()) {
                    return;
                }
                $r = (array) ($valuation->currentRun?->result ?? []);
                $line = 'Valoración automática '.$valuation->reference.(isset($r['precio_central'])
                    ? ': '.number_format((float) $r['precio_central'], 0, ',', '.').' € (intervalo '.number_format((float) $r['intervalo']['min'], 0, ',', '.').' – '.number_format((float) $r['intervalo']['max'], 0, ',', '.').' €, suficiencia '.$r['suficiencia'].', cálculo '.$r['version_calculo'].')'
                    : ' ('.$valuation->status.')').(filled($valuation->catastro['rc'] ?? null) ? ' · Ref. catastral '.$valuation->catastro['rc'] : '');
                $property->forceFill(['internal_notes' => trim(((string) $property->internal_notes)."\n".$line)])->save();
                $valuation->forceFill(['property_id' => $property->getKey()])->save();
            } catch (Throwable $e) {
                report($e);   // never undo the GHL handoff
            }
        });

        // L-5: WhatsApp replies of valuation contacts → note + tag in GHL; «BAJA» → opt-out. Never breaks the WAHA webhook.
        \App\Models\Communication::created(function (\App\Models\Communication $c): void {
            try {
                \App\Acquisition\GhlWhatsApp::inbound($c);
            } catch (Throwable $e) {
                report($e);
            }
        });

        // L-20: the phone or GHL contact becomes a CLARTO client (party saved, GHL won handed off) → nurture stops.
        \Liberu\RealEstate\Parties\Models\Party::saved(function (\Liberu\RealEstate\Parties\Models\Party $party): void {
            rescue(fn () => \App\Acquisition\NurturePlan::partySaved($party), report: true);
        });
        GhlHandoff::saved(function (GhlHandoff $handoff): void {
            if (in_array($handoff->status, ['procesado', 'pendiente'], true) && filled($handoff->contact_id)) {
                rescue(fn () => \App\Acquisition\NurturePlan::clientLinked($handoff->contact_id, null, null), report: true);
            }
        });

        if ($this->app->runningInConsole()) {
            $this->commands([TestValuations::class, \App\Console\Commands\RetryValuationDeliveries::class, \App\Console\Commands\ReconcileGhlWonOpportunities::class,
                \App\Console\Commands\SendWhatsAppNurture::class]);
        }
        // Pending deliveries (e.g. WhatsApp before the line is linked), GHL syncs and WhatsApp asked by GHL are retried
        // every 15 minutes; won opportunities whose webhook never arrived are reconciled daily (L-8).
        $this->callAfterResolving(\Illuminate\Console\Scheduling\Schedule::class, function (\Illuminate\Console\Scheduling\Schedule $schedule): void {
            $schedule->command('clarto:valoraciones-reenviar')->name('clarto-valoraciones-reenviar')->everyFifteenMinutes()->onOneServer()->withoutOverlapping();
            $schedule->command('clarto:ghl-ganadas')->name('clarto-ghl-ganadas')->dailyAt('07:30')->onOneServer()->withoutOverlapping();
            // Every minute: each planned message carries its own window (send_after … window_end), so nothing goes out late;
            // test-mode slots (NUTRICION_WA_PRUEBA_TELEFONOS) fall outside 17:30–18:30.
            $schedule->command('clarto:nutricion-whatsapp')->name('clarto-nutricion-whatsapp')->everyMinute()->onOneServer()->withoutOverlapping();
        });
    }
}
