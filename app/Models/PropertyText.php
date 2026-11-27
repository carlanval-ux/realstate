<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Liberu\RealEstate\Properties\Models\Property;

/**
 * Public texts of a property in one language. Castilian title/description live on the property itself.
 *
 * @property string $locale
 */
final class PropertyText extends Model
{
    /** Languages offered to the website. es is the source and the fallback. */
    public const LOCALES = ['es' => 'Español', 'en' => 'English', 'tr' => 'Türkçe'];

    /** Public text fields, in display order. */
    public const FIELDS = ['title', 'summary', 'description', 'seo_title', 'seo_description'];

    /** Text the website may show. */
    public const APPROVED = 'aprobado';

    /** AI proposal (or pending text) the website never shows until approved. */
    public const DRAFT = 'borrador';

    /** Written by the AI and not touched by anyone since: the only kind of draft a new AI proposal may replace. */
    public const SOURCE_AI = 'ia';

    /** Typed or corrected by a person (an approved text is always reviewed by a person). */
    public const SOURCE_MANUAL = 'manual';

    protected $table = 'clarto_property_texts';

    /** Set by PropertyTextWriter for its own writes; any other change to an AI draft is a person's correction. */
    public bool $machineWrite = false;

    protected $fillable = ['property_id', 'locale', 'status', 'title', 'summary', 'description', 'seo_title', 'seo_description', 'source', 'model', 'notes', 'review_required_at'];

    protected $attributes = ['status' => self::APPROVED];

    protected function casts(): array
    {
        return ['review_required_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        // A person editing an AI draft (in the «Public texts» tab) turns it into their own text: a new AI proposal never replaces it.
        static::saving(function (self $text): void {
            if ($text->exists && ! $text->machineWrite && $text->status === self::DRAFT && $text->source === self::SOURCE_AI && $text->isDirty(self::FIELDS)) {
                $text->source = self::SOURCE_MANUAL;
            }
        });
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * Why an AI proposal must not be written over this language's draft (corrected by a person, or imported), or null.
     */
    public static function protectedDraft(Property $property, string $locale): ?string
    {
        $source = self::query()->where('property_id', $property->getKey())->where('locale', $locale)->where('status', self::DRAFT)->value('source');

        return match (true) {
            $source === null, $source === self::SOURCE_AI => null,
            $source === self::SOURCE_MANUAL => __('the draft has corrections by a person: approve or discard it first'),
            default => __('the draft was not written by the AI (:source): approve or discard it first', ['source' => $source]),
        };
    }

    /** An approved en/tr translation with title and long description, not flagged since the Castilian changed. */
    public static function reviewedTranslation(Property $property, string $locale): bool
    {
        return $locale !== 'es' && self::query()->where('property_id', $property->getKey())->where('locale', $locale)->where('status', self::APPROVED)
            ->whereNull('review_required_at')->whereNotNull('title')->where('title', '!=', '')
            ->whereNotNull('description')->where('description', '!=', '')->exists();
    }

    public static function flagTranslationsForReview(int $propertyId): void
    {
        self::query()->where('property_id', $propertyId)->where('locale', '!=', 'es')
            ->where('status', self::APPROVED)->whereNull('review_required_at')
            ->update(['review_required_at' => now()]);
    }

    /**
     * Field values for a property in one language, falling back to Castilian per field.
     *
     * @return array{values: array<string, ?string>, languages: array<string, ?string>}
     */
    public static function resolve(Property $property, string $locale): array
    {
        $rows = ($property->relationLoaded('clartoTexts') ? $property->getRelation('clartoTexts') : $property->clartoTexts()->get())
            ->where('status', self::APPROVED);
        $es = $rows->firstWhere('locale', 'es');
        $wanted = $locale === 'es' ? null : $rows->firstWhere('locale', $locale);

        $castilian = [
            'title' => $property->title,
            'summary' => $es?->summary,
            'description' => $property->description,
            'seo_title' => $es?->seo_title,
            'seo_description' => $es?->seo_description,
        ];

        $values = [];
        $languages = [];
        foreach (self::FIELDS as $field) {
            $translated = $wanted?->{$field};
            if (filled($translated)) {
                [$values[$field], $languages[$field]] = [$translated, $locale];
            } elseif (filled($castilian[$field])) {
                [$values[$field], $languages[$field]] = [$castilian[$field], 'es'];
            } else {
                [$values[$field], $languages[$field]] = [null, null];
            }
        }

        return ['values' => $values, 'languages' => $languages];
    }

    /**
     * Fields still missing in a language (es counts the property's own title/description).
     *
     * @return list<string>
     */
    public static function missing(Property $property, string $locale): array
    {
        $row = $property->clartoTexts()->where('locale', $locale)->where('status', self::APPROVED)->first();

        return array_values(array_filter(self::FIELDS, function (string $field) use ($property, $locale, $row): bool {
            if ($locale === 'es' && in_array($field, ['title', 'description'], true)) {
                return blank($property->{$field});
            }

            return blank($row?->{$field});
        }));
    }
}
