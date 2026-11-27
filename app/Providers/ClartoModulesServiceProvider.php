<?php

declare(strict_types=1);

namespace App\Providers;

use App\Console\Commands\TestClartoModules;
use App\Filament\Pages\AiSettings;
use App\Models\AiSetting;
use App\Models\PropertyText;
use App\Modules\ClartoModules;
use App\Portals\Pisos\PisosConnector;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Liberu\Foundation\ModuleManager\ModuleActivations;

/**
 * CLARTO feature modules (see App\Modules\ClartoModules): per-request switch state, the implementations already in
 * this build, who manages them, and the guards that stop new operations of a switched-off module at the model level
 * (panel, API and webhooks alike) while existing rows stay untouched.
 */
final class ClartoModulesServiceProvider extends ServiceProvider
{
    /** MediaDocument kinds that are the property gallery (photos and plans), not documents. */
    private const GALLERY_KINDS = ['photo', 'floorplan', 'siteplan', 'video'];

    public function register(): void
    {
        $this->app->scoped(ClartoModules::CHOICES, fn (): array => $this->app->make(ModuleActivations::class)->all());

        ClartoModules::implement(ClartoModules::IDIOMAS,
            missingConfiguration: function (): ?string {
                $settings = AiSetting::query()->first() ?? new AiSetting(['provider' => 'openrouter']);

                return blank($settings->apiKey())
                    ? __('No AI provider key: AI drafting and translation are unavailable (texts can still be written by hand).')
                    : null;
            },
            settingsUrl: fn (): string => AiSettings::getUrl(),
        );

        // Official documents work today; the forms part comes with feat/formularios-firma.
        ClartoModules::implement(ClartoModules::DOCUMENTOS);

        // WhatsApp (WAHA, docs/clarto/whatsapp.md): usable once the server knows WAHA and a team line is linked.
        ClartoModules::implement(ClartoModules::WHATSAPP,
            missingConfiguration: fn (): ?string => match (true) {
                blank(config('whatsapp.api_key')) => __('WAHA is not configured on the server (WAHA_URL, WAHA_API_KEY).'),
                ! \App\Models\WhatsAppLine::query()->where('status', \App\Models\WhatsAppLine::WORKING)->exists() => __('The WhatsApp line is not linked: scan the QR code in Platform settings → WhatsApp.'),
                default => null,
            },
            settingsUrl: fn (): ?string => rescue(fn () => \App\Filament\Pages\WhatsAppSettings::getUrl(), report: false),
        );

        // Signing (docs/clarto/formularios-firma.md): works simulated; real signatures need DocuSeal on the server.
        ClartoModules::implement(ClartoModules::FIRMA,
            missingConfiguration: fn (): ?string => config('firma.driver') !== 'docuseal' || blank(config('firma.docuseal.api_key'))
                ? __('Simulation mode: real signatures need DocuSeal on the server (FIRMA_DRIVER=docuseal, DocuSeal API key and webhook secret).')
                : null,
        );

        ClartoModules::implement(ClartoModules::PISOS,
            missingConfiguration: fn (): ?string => config('pisos.driver') !== 'api'
                ? __('Simulation mode: real sends to pisos.com need the agency gateway credentials on the server (PISOS_DRIVER=api, PISOS_ENABLED, PISOS_API_KEY, PISOS_USER_ID).')
                : PisosConnector::make()->refusal(),
        );

        ClartoModules::implement(ClartoModules::CAPTACION,
            missingConfiguration: function (): ?string {
                $missing = array_keys(array_filter([
                    'GHL_API_TOKEN' => config('ghl.token'),
                    'GHL_LOCATION_ID' => config('ghl.location_id'),
                    'GHL_PIPELINE_ID' => config('ghl.pipeline_id'),
                    'GHL_WEBHOOK_SECRET' => config('ghl.webhook_secret'),
                ], fn (mixed $value): bool => blank($value)));

                return $missing === [] ? null : __('GoHighLevel is not configured on the server: :vars.', ['vars' => implode(', ', $missing)]);
            },
        );
    }

    public function boot(): void
    {
        Gate::define(ClartoModules::ABILITY, fn ($user): bool => method_exists($user, 'isAdmin') && $user->isAdmin());

        // App\Filament\Pages\Modules replaces the package's «Modules» page: it lists the same official feature modules
        // through the same ModuleManagement service, plus CLARTO's. Denying the package page's ability keeps one entry
        // for administrators; super administrators pass every ability (Shield), so ClartoNavigation hides its menu entry.
        $this->app->booted(fn () => Gate::define('manageModules', fn (): bool => false));

        $this->guard('Liberu\\RealEstate\\MediaAndDocuments\\Models\\MediaDocument', ClartoModules::DOCUMENTOS,
            fn (Model $document): bool => ! in_array((string) $document->getAttribute('kind'), self::GALLERY_KINDS, true));
        $this->guard('Liberu\\RealEstate\\MediaAndDocuments\\Models\\DocumentTemplate', ClartoModules::DOCUMENTOS);
        $this->guard('Liberu\\RealEstate\\MediaAndDocuments\\Models\\DocumentVersion', ClartoModules::DOCUMENTOS);

        $this->guard('Liberu\\RealEstate\\MediaAndDocuments\\Models\\DocumentEnvelope', ClartoModules::FIRMA);
        $this->guard('Liberu\\RealEstate\\MediaAndDocuments\\Models\\DocumentEnvelopeSignature', ClartoModules::FIRMA);
        $this->guard('Liberu\\RealEstate\\MediaAndDocuments\\Models\\DocumentSignature', ClartoModules::FIRMA);

        // Translations and AI drafts are the languages module; the Castilian approved text is the property itself.
        // Discarding a draft (delete) and the "review" flag (query update) stay possible.
        // Only real changes: a form re-saving an unchanged translation must not block saving the property.
        PropertyText::saving(function (PropertyText $text): void {
            if ($text->isDirty() && ($text->locale !== 'es' || $text->status === PropertyText::DRAFT)) {
                ClartoModules::ensureEnabled(ClartoModules::IDIOMAS);
            }
        });

        if ($this->app->runningInConsole()) {
            $this->commands([TestClartoModules::class]);
        }
    }

    /**
     * Creating a row of this model is a new operation of the module: refused while it is switched off.
     *
     * @param  class-string<Model>|string  $model
     * @param  (\Closure(Model): bool)|null  $applies
     */
    private function guard(string $model, string $module, ?\Closure $applies = null): void
    {
        if (! class_exists($model)) {
            return;
        }

        $model::creating(function (Model $record) use ($module, $applies): void {
            if ($applies === null || $applies($record)) {
                ClartoModules::ensureEnabled($module);
            }
        });
    }
}
