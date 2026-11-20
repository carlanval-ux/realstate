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
    public function boot(): void
    {
        // After every module provider: CLARTO's rules for "Continue with Google" replace Socialstream's defaults.
        $this->app->booted(function (): void {
            Socialstream::authenticatesOAuthCallbackUsing(GoogleSignIn::class);
            Socialstream::handlesInvalidStateUsing(RejectInvalidOAuthState::class);
        });
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
            $this->commands([CheckPropertyTextLanguages::class, CompareAiModels::class, TestPisosConnector::class, TestGoogleSignIn::class, UserAccess::class]);
        }
    }
}
