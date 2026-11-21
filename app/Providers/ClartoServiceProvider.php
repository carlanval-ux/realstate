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
use JoelButcher\Socialstream\Socialstream;
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

        if ($this->app->runningInConsole()) {
            $this->commands([CheckPropertyTextLanguages::class, CompareAiModels::class, TestPisosConnector::class, TestGoogleSignIn::class, UserAccess::class, \App\Console\Commands\TestGhlHandoff::class]);
            $this->commands([\App\Console\Commands\TestImprovementRequests::class]); // Solicitud de mejoras
        }
    }
}
