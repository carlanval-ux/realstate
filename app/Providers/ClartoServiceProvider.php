<?php

declare(strict_types=1);

namespace App\Providers;

use App\Console\Commands\CheckPropertyTextLanguages;
use App\Console\Commands\CompareAiModels;
use App\Console\Commands\TestPisosConnector;
use App\Console\Commands\TestGoogleSignIn;
use App\Console\Commands\UserAccess;
use App\Auth\GoogleSignIn;
use App\Auth\RejectInvalidOAuthState;
use App\Models\PropertyText;
use Illuminate\Support\ServiceProvider;
use App\Owners\OwnerProfile;
use JoelButcher\Socialstream\Socialstream;
use Liberu\RealEstate\Parties\Models\Party;
use Liberu\RealEstate\Properties\Models\Property;

/** CLARTO additions to the composed application: per-language public texts, AI drafts, portals, Google sign-in. */
final class ClartoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // ── Solicitud de mejoras (begin) ──────────────────────────────────────────────────────────────
        // The module panel providers register their panels on the first resolution of PanelRegistry (callbacks
        // added before this one): the resource is added to every panel afterwards, without touching a module.
        $addImprovementRequests = function (\Filament\PanelRegistry $registry): void {
            foreach ($registry->all() as $panel) {
                if (! in_array(\App\Filament\Resources\ImprovementRequestResource::class, $panel->getResources(), true)) {
                    $panel->resources([\App\Filament\Resources\ImprovementRequestResource::class]); // ignored (and not needed) with a component cache
                }
            }
            // The panel registered its Livewire components already (or restored them from a cache built without these).
            foreach (\App\Filament\Resources\ImprovementRequestResource::getPages() as $page) {
                [, $name] = app(\Livewire\Finder\Finder::class)->parseNamespaceAndName($page->getPage());
                \Livewire\Livewire::component($name, $page->getPage());
            }
        };
        $this->app->resolved(\Filament\PanelRegistry::class)
            ? $addImprovementRequests($this->app->make(\Filament\PanelRegistry::class))
            : $this->app->resolving(\Filament\PanelRegistry::class, $addImprovementRequests);
        // ── Solicitud de mejoras (end) ────────────────────────────────────────────────────────────────

        // Comunicaciones: Alquileres' «log communication» point sends for real and logs in the client timeline
        // (bound before ClartoRentalsServiceProvider's bindIf fallback, TableCommunicationLog).
        $this->app->bind(\App\Rentals\Integration\CommunicationLog::class, \App\Communications\RentalCommunicationLog::class);
        // ── Valoraciones automáticas (feat/valoraciones-auto) ── see ClartoValuationsServiceProvider.
        // Registered here (not in boot()) so its panel resource is added before the panel routes are built.
        $this->app->register(\App\Providers\ClartoValuationsServiceProvider::class);
        // ── fin Valoraciones automáticas ──

        // ── Documentos en las fichas (feat/documentos-ficha) ── see ClartoDocumentsServiceProvider.
        $this->app->register(\App\Providers\ClartoDocumentsServiceProvider::class);
        // ── fin Documentos en las fichas ──

        // ── Procesos (begin) ── «Procesos y conocimiento», see ClartoProcessesServiceProvider (it also registers the assistant).
        $this->app->register(\App\Providers\ClartoProcessesServiceProvider::class);
        // ── Procesos (end) ──

        // ── Colaboradores (feat/colaboradores) ── panel /colaboradores, staff screen, fence; see ClartoCollaboratorsServiceProvider.
        $this->app->register(\App\Providers\ClartoCollaboratorsServiceProvider::class);
        // ── fin Colaboradores ──

        // ── Solicitudes de inmuebles (feat/solicitudes-inmuebles) ── after Colaboradores (its contract); see ClartoSearchRequestsServiceProvider.
        $this->app->register(\App\Providers\ClartoSearchRequestsServiceProvider::class);
        // ── fin Solicitudes de inmuebles ──
    }

    public function boot(): void
    {
        // ── Solicitud de mejoras (begin) ──
        \Illuminate\Support\Facades\Gate::policy(\App\Models\ImprovementRequest::class, \App\Policies\ImprovementRequestPolicy::class);
        $this->loadRoutesFrom(__DIR__.'/../../routes/clarto-mejoras.php');
        // ── Solicitud de mejoras (end) ──

        // After every module provider: CLARTO's rules for "Continue with Google" replace Socialstream's defaults.
        $this->app->booted(function (): void {
            Socialstream::authenticatesOAuthCallbackUsing(GoogleSignIn::class);
            Socialstream::handlesInvalidStateUsing(RejectInvalidOAuthState::class);
            // Outgoing mail configured in the panel (Platform settings → Outgoing mail) overrides MAIL_*.
            \App\Models\MailSetting::apply();
        });
        // Choosing a password from the e-mailed link proves the address: mark it verified (invited users).
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Auth\Events\PasswordReset::class, function (\Illuminate\Auth\Events\PasswordReset $event): void {
            if (method_exists($event->user, 'hasVerifiedEmail') && ! $event->user->hasVerifiedEmail()) {
                $event->user->markEmailAsVerified();
            }
        });

        // Technical menu entries are listed only for super administrators.
        \Filament\Support\Facades\FilamentView::registerRenderHook(\Filament\View\PanelsRenderHook::HEAD_END, fn (): string => \App\Filament\ClartoNavigation::technicalNavigationCss());
        $this->app->bind(\JoelButcher\Socialstream\Actions\RedirectIfTwoFactorAuthenticatable::class, \App\Auth\TwoFactorAfterGoogle::class);

        // Propietarios: the owner 360 profile (1:1).
        Party::resolveRelationUsing('clartoOwnerProfile', fn (Party $party) => $party->hasOne(OwnerProfile::class));

        Property::resolveRelationUsing('clartoTexts', fn (Property $property) => $property->hasMany(PropertyText::class));

        foreach (array_keys(PropertyText::LOCALES) as $locale) {
            Property::resolveRelationUsing(
                'clartoText'.ucfirst($locale),
                fn (Property $property) => $property->hasOne(PropertyText::class)->where('locale', $locale)->where('status', PropertyText::APPROVED),
            );
            Property::resolveRelationUsing(
                'clartoDraft'.ucfirst($locale),
                fn (Property $property) => $property->hasOne(PropertyText::class)->where('locale', $locale)->where('status', PropertyText::DRAFT),
            );
        }

        // When the approved Castilian text changes, approved translations are flagged for review.
        Property::saved(function (Property $property): void {
            if ($property->wasChanged(['title', 'description'])) {
                PropertyText::flagTranslationsForReview($property->getKey());
            }
        });
        PropertyText::saved(function (PropertyText $text): void {
            if ($text->locale === 'es' && $text->status === PropertyText::APPROVED && $text->wasChanged(['summary', 'seo_title', 'seo_description'])) {
                PropertyText::flagTranslationsForReview($text->property_id);
            }
        });

        // ── Alquileres (feat/alquileres): tradicional, habitaciones, vacacional ── see ClartoRentalsServiceProvider.
        $this->app->register(\App\Providers\ClartoRentalsServiceProvider::class);
        // ── fin Alquileres ──

        // «Albaranes» tab on the owner 360 page (Propietarios), when that page is present.
        if (class_exists(\App\Owners\OwnerProfileSections::class)) {
            \App\Owners\OwnerProfileSections::register('albaranes', fn () => \App\Filament\AlbaranTabs::owner(), sort: 70);
        }

        // ── TARMIO: pending actions become TARMIO tasks (docs/clarto/tarmio.md) ─────────────────────────────────
        $this->app->bind(\App\Tarmio\TarmioClient::class, fn () => config('tarmio.driver') === 'http' ? new \App\Tarmio\HttpTarmioClient : new \App\Tarmio\SimulatedTarmioClient);
        // New owner from a GHL won opportunity: task to complete the owner and the draft property.
        \App\Acquisition\GhlHandoff::saved(function (\App\Acquisition\GhlHandoff $handoff): void {
            $property = $handoff->status === 'procesado' && $handoff->property_id ? Property::query()->find($handoff->property_id) : null;
            if ($property === null) {
                return;
            }
            try {   // a TARMIO problem must never undo the GHL handoff
                $team = \Liberu\Foundation\Organizations\Models\Team::query()->find($property->team_id);
                \App\Tarmio\TarmioTask::sync(
                    $property,
                    'alta_propietario',
                    'Alta de propietario (captación GHL) · completar ficha '.$property->reference(),
                    config('tarmio.responsable_captacion') ?: \App\Models\User::query()->find($team?->user_id)?->email,
                    now()->addWeekdays(2),
                    \Liberu\RealEstate\PropertiesFilament\Resources\PropertyResource::getUrl('edit', ['record' => $property], panel: 'admin', tenant: $team),
                );
            } catch (\Throwable $e) {
                report($e);
            }
        });
        // ── end TARMIO ──────────────────────────────────────────────────────────────────────────────────────

        // ── Comunicaciones (docs/clarto/comunicaciones.md): one log and one timeline per client ──
        \Livewire\Livewire::component('clarto-client-timeline', \App\Filament\Communications\ClientTimeline::class);
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Mail\Events\MessageSent::class, [\App\Communications\Communications::class, 'logSystemMail']);
        if (class_exists(\App\Owners\OwnerProfileSections::class)) {
            \App\Owners\OwnerProfileSections::register('comunicaciones', fn () => \App\Filament\Communications\CommunicationsUi::tab(), sort: 15);
        }
        // ── fin Comunicaciones ──

        // Signing and SMS drivers (config/firma.php): simulated unless the server's .env turns the real ones on.
        $this->app->bind(\App\Signing\SignatureProvider::class, fn () => config('firma.driver') === 'docuseal' ? new \App\Signing\DocusealProvider : new \App\Signing\SimulatedSignatureProvider);
        $this->app->bind(\App\Signing\SmsGateway::class, fn () => config('firma.sms.driver') === 'redtelecom' ? new \App\Signing\RedTelecomSms : new \App\Signing\SimulatedSms);

        // Owner 360 page (Propietarios): "Signatures" tab, once that feature is merged.
        if (class_exists(\App\Owners\OwnerProfileSections::class)) {
            \App\Owners\OwnerProfileSections::register('firmas', fn () => \App\Filament\SignatureRequestsTab::forOwner(), sort: 55);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([CheckPropertyTextLanguages::class, CompareAiModels::class, TestPisosConnector::class, TestGoogleSignIn::class, UserAccess::class, \App\Console\Commands\TestGhlHandoff::class, \App\Console\Commands\TestOwnerProfiles::class, \App\Console\Commands\TestAlbaranes::class, \App\Console\Commands\TestTarmioTasks::class, \App\Console\Commands\TestWhatsApp::class, \App\Console\Commands\TestSigning::class, \App\Console\Commands\TestCommunications::class]);
            $this->commands([\App\Console\Commands\TestImprovementRequests::class]); // Solicitud de mejoras
        }
    }
}
