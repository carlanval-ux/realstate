<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PropertyText;
use Illuminate\Console\Command;
use Liberu\RealEstate\Properties\Models\Property;

/**
 * Checks the language of the titles and descriptions already stored on properties before treating them as
 * Castilian. With --apply, a text detected as English or Turkish is copied to that language (only if that
 * language is still empty) and listed for review; nothing is deleted or overwritten.
 */
final class CheckPropertyTextLanguages extends Command
{
    protected $signature = 'clarto:textos-idioma {--apply : Copy texts detected as en/tr to their language}';

    protected $description = 'Detect the language of existing property titles/descriptions';

    /** @var array<string, list<string>> */
    private const STOPWORDS = [
        'es' => ['el', 'la', 'los', 'las', 'de', 'del', 'que', 'y', 'en', 'con', 'para', 'por', 'una', 'un', 'piso', 'vivienda', 'casa', 'dormitorios', 'baños', 'cocina', 'terraza', 'zona', 'cerca', 'muy'],
        'en' => ['the', 'and', 'with', 'for', 'of', 'in', 'to', 'a', 'an', 'is', 'this', 'flat', 'house', 'bedroom', 'bedrooms', 'bathroom', 'kitchen', 'near', 'close', 'garden', 'apartment'],
        'tr' => ['ve', 'bir', 'için', 'ile', 'bu', 'çok', 'daire', 'ev', 'oda', 'yatak', 'banyo', 'mutfak', 'yakın', 'satılık', 'kiralık', 'deniz', 'manzaralı'],
    ];

    public static function detect(?string $text): ?string
    {
        $words = preg_split('/[^\p{L}]+/u', mb_strtolower((string) $text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($words === []) {
            return null;
        }

        $scores = [];
        foreach (self::STOPWORDS as $locale => $list) {
            $scores[$locale] = count(array_intersect($words, $list));
        }
        $scores['tr'] += preg_match_all('/[ışğİ]/u', (string) $text) * 2;
        $scores['es'] += preg_match_all('/[ñáéíóú¿¡]/u', (string) $text);

        arsort($scores);
        $best = array_key_first($scores);

        return $scores[$best] > 0 ? $best : null;
    }

    public function handle(): int
    {
        $rows = [];
        foreach (Property::query()->withTrashed()->orderBy('id')->get() as $property) {
            $detected = self::detect(trim($property->title.' '.$property->description));
            $action = match (true) {
                $detected === null => 'sin texto o indeterminado: se trata como castellano',
                $detected === 'es' => 'castellano',
                default => $this->copy($property, $detected),
            };
            $rows[] = [$property->id, $property->reference(), mb_strimwidth((string) $property->title, 0, 40, '…'), $detected ?? '-', $action];
        }

        $this->table(['id', 'ref', 'título', 'idioma', 'resultado'], $rows);

        return self::SUCCESS;
    }

    private function copy(Property $property, string $locale): string
    {
        if (! $this->option('apply')) {
            return "detectado {$locale}: revisar (ejecute con --apply para copiarlo a {$locale})";
        }

        $text = PropertyText::query()->firstOrNew(['property_id' => $property->id, 'locale' => $locale]);
        if (filled($text->title) || filled($text->description)) {
            return "detectado {$locale}: {$locale} ya tenía texto, no se toca";
        }
        $text->fill(['title' => $property->title, 'description' => $property->description, 'source' => 'manual'])->save();

        return "copiado a {$locale}: revise el castellano de la ficha";
    }
}
