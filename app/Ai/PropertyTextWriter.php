<?php

declare(strict_types=1);

namespace App\Ai;

use App\Http\Controllers\CatalogoController;
use App\Models\PropertyText;
use Liberu\RealEstate\Properties\Models\Property;

/**
 * Drafts a property's public texts with AI: Castilian from the confirmed data of the property, English
 * and Turkish translated from Castilian. Results are saved as editable drafts (status borrador) one
 * language at a time, so a quota error never loses what was already generated. Nothing is published
 * until a person approves the draft.
 */
final class PropertyTextWriter
{
    private const LANGUAGE_NAMES = ['en' => 'inglés (británico)', 'tr' => 'turco (Turquía)'];

    public function __construct(private readonly AiClient $client) {}

    public static function make(): self
    {
        return new self(AiClient::make());
    }

    /** @return array<string, string> confirmed, public data of the property (no address, owner or internal notes) */
    public static function facts(Property $p): array
    {
        $type = CatalogoController::TIPOS[(string) $p->property_type] ?? 'otro';
        $operation = CatalogoController::OPERACIONES[$p->deal_type?->value ?? 'sale'] ?? 'venta';
        $price = $p->price !== null ? number_format((float) $p->price, 0, ',', '.').' '.strtoupper((string) ($p->currency ?: 'EUR')) : null;
        $features = collect(is_array($p->features) ? $p->features : [])
            ->map(fn ($v, $k) => is_string($k) ? ($v ? $k : null) : (is_string($v) ? $v : null))->filter()->implode(', ');

        return array_filter([
            'Referencia' => $p->reference(),
            'Tipo de inmueble' => CatalogoController::TIPO_NOMBRES[$type] ?? null,
            'Operación' => CatalogoController::OPERACION_NOMBRES[$operation] ?? null,
            'Precio' => $price ? $price.($operation === 'alquiler' ? ' al mes' : ($operation === 'alquiler-temporal' ? ' por noche' : '')) : null,
            'Superficie construida' => $p->area_sqft ? rtrim(rtrim(number_format((float) $p->area_sqft, 2, ',', '.'), '0'), ',').' m²' : null,
            'Dormitorios' => $p->bedrooms ? (string) $p->bedrooms : null,
            'Baños' => $p->bathrooms ? (string) $p->bathrooms : null,
            'Salones' => $p->reception_rooms ? (string) $p->reception_rooms : null,
            'Año de construcción' => $p->year_built ? (string) $p->year_built : null,
            'Certificado energético' => $p->energy_rating ? strtoupper((string) $p->energy_rating) : null,
            'Zona' => $p->district?->name,
            'Municipio' => $p->city?->name,
            'Provincia' => $p->region?->name,
            'Características' => $features ?: null,
            'Plaza de garaje' => $p->has_parking ? 'sí' : null,
        ], fn ($v): bool => filled($v));
    }

    /** @return array{fields: array<string, ?string>, model: string, notes: list<string>} */
    public function writeSpanish(Property $property): array
    {
        $facts = self::facts($property);
        $system = 'Eres redactor inmobiliario de CLARTO, una firma de Valencia. Escribe en castellano de España, con tono profesional, claro y cercano, sin exageraciones ni superlativos vacíos. '
            .'Usa EXCLUSIVAMENTE los datos facilitados: no inventes cifras, distancias, orientación, materiales, vistas, servicios ni estado de conservación. '
            .'Tampoco afirmes nada que no esté en los datos sobre luminosidad, tamaño de las estancias («amplio»), tranquilidad, vecindario, comercios o transporte: describe solo lo que se sabe. '
            .'Respeta exactamente las cifras, la superficie, el precio y los nombres de lugar. No incluyas dirección exacta, teléfonos ni correos. '
            .'Responde solo con un objeto JSON con estas claves: title (máximo 80 caracteres), summary (máximo 300), description (dos a cuatro párrafos, máximo 1500), seo_title (máximo 60), seo_description (máximo 155).';
        $user = "Datos confirmados del inmueble:\n".collect($facts)->map(fn ($v, $k) => "- {$k}: {$v}")->implode("\n");

        $answer = $this->client->complete('writing', $system, $user);
        $fields = self::parse($answer['content']);

        return ['fields' => $fields, 'model' => $answer['model'], 'notes' => self::check($facts, $fields)];
    }

    /**
     * @param  array<string, ?string>  $spanish
     * @return array{fields: array<string, ?string>, model: string, notes: list<string>}
     */
    public function translate(Property $property, string $locale, array $spanish): array
    {
        $language = self::LANGUAGE_NAMES[$locale] ?? throw new AiUnavailable("Unsupported language {$locale}");
        $system = "Traduce del castellano al {$language} el anuncio inmobiliario que recibes en JSON. "
            .'Mantén exactamente cifras, superficies, precios, moneda y número de dormitorios y baños. No traduzcas los nombres de lugar (por ejemplo Puerto de Sagunto, Ruzafa, Valencia, Sagunto). '
            .'No añadas ni quites información. Usa un registro profesional y natural para un comprador o inquilino nativo'
            .($locale === 'tr' ? ', con ortografía turca correcta (ı, İ, ş, ğ, ç, ö, ü) y tratamiento formal' : '')
            .'. Responde solo con un objeto JSON con las mismas claves (title, summary, description, seo_title, seo_description); deja null las que lleguen vacías.';

        $answer = $this->client->complete('translation', $system, json_encode(array_intersect_key($spanish, array_flip(PropertyText::FIELDS)), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $fields = self::parse($answer['content']);

        return ['fields' => $fields, 'model' => $answer['model'], 'notes' => self::check(self::facts($property), $fields, $spanish)];
    }

    // ---------------------------------------------------------------- persistence --

    /**
     * "Generate ES · EN · TR": Castilian draft from the data, then English and Turkish from that draft.
     *
     * @return array{saved: list<string>, error: ?string}
     */
    public function generateAll(Property $property): array
    {
        return $this->run(function (array &$saved) use ($property): void {
            $es = $this->saveDraft($property, 'es', $this->writeSpanish($property));
            $saved[] = 'es';
            foreach (['en', 'tr'] as $locale) {
                $this->saveDraft($property, $locale, $this->translate($property, $locale, $es));
                $saved[] = $locale;
            }
        });
    }

    /**
     * Translate the approved Castilian into en and tr (or only one language).
     *
     * @param  list<string>  $locales
     * @return array{saved: list<string>, error: ?string}
     */
    public function translateApproved(Property $property, array $locales = ['en', 'tr']): array
    {
        $spanish = PropertyText::resolve($property, 'es')['values'];
        if (blank($spanish['title']) || blank($spanish['description'])) {
            return ['saved' => [], 'error' => __('Approve a Spanish title and long description first.')];
        }

        return $this->run(function (array &$saved) use ($property, $locales, $spanish): void {
            foreach ($locales as $locale) {
                $this->saveDraft($property, $locale, $this->translate($property, $locale, $spanish));
                $saved[] = $locale;
            }
        });
    }

    /** @return array{saved: list<string>, error: ?string} */
    public function regenerate(Property $property, string $locale): array
    {
        if ($locale === 'es') {
            return $this->run(function (array &$saved) use ($property): void {
                $this->saveDraft($property, 'es', $this->writeSpanish($property));
                $saved[] = 'es';
            });
        }

        $approved = PropertyText::resolve($property, 'es');
        $draft = $property->clartoDraftEs()->first();
        $source = filled($approved['values']['title']) && filled($approved['values']['description'])
            ? $approved['values']
            : ($draft ? $draft->only(PropertyText::FIELDS) : null);

        if ($source === null) {
            return ['saved' => [], 'error' => __('There is no Spanish text to translate yet.')];
        }

        return $this->run(function (array &$saved) use ($property, $locale, $source): void {
            $this->saveDraft($property, $locale, $this->translate($property, $locale, $source));
            $saved[] = $locale;
        });
    }

    /** Approve a draft: it becomes the text the website shows. Castilian title/description go to the property. */
    public static function approve(Property $property, string $locale): void
    {
        $draft = PropertyText::query()->where('property_id', $property->getKey())->where('locale', $locale)->where('status', PropertyText::DRAFT)->firstOrFail();

        if ($locale === 'es') {
            $property->forceFill(['title' => $draft->title, 'description' => $draft->description])->save();
        }

        $approved = PropertyText::query()->firstOrNew(['property_id' => $property->getKey(), 'locale' => $locale, 'status' => PropertyText::APPROVED]);
        $fields = $locale === 'es' ? ['summary', 'seo_title', 'seo_description'] : PropertyText::FIELDS;
        $approved->fill($draft->only($fields) + ['source' => $draft->source, 'model' => $draft->model, 'review_required_at' => null, 'notes' => null])->save();
        $draft->delete();
    }

    /** @param  array{fields: array<string, ?string>, model: string, notes: list<string>}  $result */
    private function saveDraft(Property $property, string $locale, array $result): array
    {
        PropertyText::query()->updateOrCreate(
            ['property_id' => $property->getKey(), 'locale' => $locale, 'status' => PropertyText::DRAFT],
            $result['fields'] + ['source' => 'ia', 'model' => $result['model'], 'notes' => $result['notes'] === [] ? null : implode("\n", $result['notes'])],
        );

        return $result['fields'];
    }

    /** @return array{saved: list<string>, error: ?string} */
    private function run(callable $work): array
    {
        $saved = [];
        try {
            $work($saved);

            return ['saved' => $saved, 'error' => null];
        } catch (AiUnavailable $e) {
            return ['saved' => $saved, 'error' => $e->getMessage()];
        }
    }

    // ------------------------------------------------------------------- helpers --

    /** @return array<string, ?string> */
    public static function parse(string $content): array
    {
        $content = (string) preg_replace('/<think>.*?<\/think>/s', '', $content);
        $json = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($content));
        $start = strpos((string) $json, '{');
        $end = strrpos((string) $json, '}');
        $data = $start !== false && $end !== false ? json_decode(substr((string) $json, $start, $end - $start + 1), true) : null;
        if (! is_array($data)) {
            throw new AiUnavailable(__('The model did not return the expected format. Try again or choose another model.'));
        }

        $limits = ['title' => 255, 'summary' => 500, 'description' => 5000, 'seo_title' => 70, 'seo_description' => 170];
        $out = [];
        foreach ($limits as $field => $max) {
            $value = $data[$field] ?? null;
            $out[$field] = is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, $max) : null;
        }

        return $out;
    }

    /**
     * Data accuracy checks: figures not present in the confirmed data and place names left out.
     *
     * @param  array<string, string>  $facts
     * @param  array<string, ?string>  $fields
     * @param  array<string, ?string>|null  $source  Castilian original, for translations
     * @return list<string>
     */
    public static function check(array $facts, array $fields, ?array $source = null): array
    {
        $text = implode("\n", array_filter($fields));
        $allowed = self::numbers(implode(' ', $facts).' '.($source ? implode(' ', array_filter($source)) : ''));
        $unknown = array_values(array_diff(self::numbers($text), $allowed));

        $notes = [];
        if ($unknown !== []) {
            $notes[] = __('Figures not in the property data: :n', ['n' => implode(', ', $unknown)]);
        }
        foreach (array_filter([$facts['Zona'] ?? null, $facts['Municipio'] ?? null]) as $place) {
            if (! str_contains(mb_strtolower($text), mb_strtolower($place))) {
                $notes[] = __('Does not mention :place', ['place' => $place]);
            }
        }
        if (blank($fields['title'] ?? null) || blank($fields['description'] ?? null)) {
            $notes[] = __('Title or long description missing.');
        }

        return $notes;
    }

    /** @return list<string> numbers normalised: 189.000 / 189,000 / 189 000 → 189000, 96,5 → 96.5 */
    public static function numbers(string $text): array
    {
        preg_match_all('/\d{1,3}(?:[.,\x{00A0}\s]\d{3})+(?!\d)|\d+(?:[.,]\d{1,2})?(?!\d)/u', $text, $m);

        return array_values(array_unique(array_map(function (string $n): string {
            if (preg_match('/^\d{1,3}(?:[.,\x{00A0}\s]\d{3})+$/u', $n)) {
                return (string) preg_replace('/\D/u', '', $n);
            }
            $n = str_replace(',', '.', $n);

            return str_contains($n, '.') ? rtrim(rtrim($n, '0'), '.') : $n;
        }, $m[0])));
    }
}
