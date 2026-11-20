<?php

declare(strict_types=1);

namespace App\Providers;

use App\Console\Commands\CheckPropertyTextLanguages;
use App\Models\PropertyText;
use Illuminate\Support\ServiceProvider;
use Liberu\RealEstate\Properties\Models\Property;

/** CLARTO additions to the composed application: per-language public texts of a property. */
final class ClartoServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Property::resolveRelationUsing('clartoTexts', fn (Property $property) => $property->hasMany(PropertyText::class));

        foreach (array_keys(PropertyText::LOCALES) as $locale) {
            Property::resolveRelationUsing(
                'clartoText'.ucfirst($locale),
                fn (Property $property) => $property->hasOne(PropertyText::class)->where('locale', $locale),
            );
        }

        if ($this->app->runningInConsole()) {
            $this->commands([CheckPropertyTextLanguages::class]);
        }
    }
}
