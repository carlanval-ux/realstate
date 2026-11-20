<?php

declare(strict_types=1);

namespace App\Filament;

use App\Models\PropertyText;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Liberu\RealEstate\Properties\Models\Property;

/**
 * "Public texts" tab of the property form: title, short and long description and SEO in es, en and tr.
 *
 * Castilian title/description are the property's own columns (every module reads them); everything else
 * is a PropertyText row per language, saved through a Filament relationship group, so each language is
 * stored independently. A badge counts the fields still missing in each language. Publishing never
 * depends on en/tr: an empty field falls back to Castilian on the website.
 */
final class PropertyPublicTexts
{
    public static function tab(): Tab
    {
        return Tab::make(__('Public texts'))
            ->icon('heroicon-o-language')
            ->schema([
                Tabs::make('public-texts')->tabs([
                    // Castilian title and long description are the property's own columns; the rest is its es row
                    // (one relationship group only: two groups on the same relation would both create it).
                    self::languageTab('es', [
                        TextInput::make('title')->label(__('Title'))->maxLength(255),
                        Textarea::make('description')->label(__('Long description'))->rows(8),
                        Group::make(self::fields(['summary', 'seo_title', 'seo_description']))
                            ->relationship('clartoTextEs', condition: self::hasContent(...))
                            ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => $data + ['locale' => 'es']),
                    ]),
                    self::languageTab('en'),
                    self::languageTab('tr'),
                ]),
            ]);
    }

    /** @param  list<\Filament\Schemas\Components\Component>|null  $schema */
    private static function languageTab(string $locale, ?array $schema = null): Tab
    {
        $schema ??= [
            Group::make(self::fields(PropertyText::FIELDS))
                ->relationship('clartoText'.ucfirst($locale), condition: self::hasContent(...))
                ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => $data + ['locale' => $locale]),
        ];

        return Tab::make(PropertyText::LOCALES[$locale])
            ->extraAttributes(['lang' => $locale])
            ->badge(fn (?Property $record): ?string => ($n = count($record ? PropertyText::missing($record, $locale) : PropertyText::FIELDS)) > 0 ? __(':count pending', ['count' => $n]) : null)
            ->badgeColor('warning')
            ->schema([
                ...($locale === 'es' ? [] : [\Filament\Schemas\Components\Text::make(__('Empty fields are shown in Spanish on the website until they are translated.'))->color('gray')]),
                ...$schema,
            ]);
    }

    /**
     * @param  list<string>  $names
     * @return list<\Filament\Forms\Components\Field>
     */
    private static function fields(array $names): array
    {
        $all = [
            'title' => TextInput::make('title')->label(__('Title'))->maxLength(255),
            'summary' => Textarea::make('summary')->label(__('Short description'))->rows(2)->maxLength(500),
            'description' => Textarea::make('description')->label(__('Long description'))->rows(8),
            'seo_title' => TextInput::make('seo_title')->label(__('SEO title'))->maxLength(70),
            'seo_description' => Textarea::make('seo_description')->label(__('SEO description'))->rows(2)->maxLength(170),
        ];

        return array_values(array_intersect_key($all, array_flip($names)));
    }

    /** @param  array<string, mixed>|null  $state */
    private static function hasContent(?array $state): bool
    {
        return collect($state ?? [])->except('locale')->filter(fn ($value): bool => filled($value))->isNotEmpty();
    }
}
