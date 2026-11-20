<?php

declare(strict_types=1);

namespace App\Providers;

use App\Console\Commands\CheckPropertyTextLanguages;
use App\Console\Commands\CompareAiModels;
use App\Models\PropertyText;
use Illuminate\Support\ServiceProvider;
use Liberu\RealEstate\Properties\Models\Property;

/** CLARTO additions to the composed application: per-language public texts of a property and AI drafts. */
final class ClartoServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
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
            $this->commands([CheckPropertyTextLanguages::class, CompareAiModels::class]);
        }
    }
}
