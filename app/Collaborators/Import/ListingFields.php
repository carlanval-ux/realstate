<?php

declare(strict_types=1);

namespace App\Collaborators\Import;

use Illuminate\Support\Str;

/**
 * The importer's vocabulary: which label («Superficie útil», «Gastos de comunidad», «Orientación»…) is which field,
 * which words are which feature («Ascensor», «Trastero»…), and which keys are personal data that is never imported.
 * Shared by the feed parser (unmapped fields) and the page extractor (feature lists, «Label: value» rows).
 */
final class ListingFields
{
    /** Listing keys used by parsers and the importer, besides «otros» (label => value) and the media lists. */
    public const KEYS = ['referencia', 'url', 'precio', 'operacion', 'tipo', 'm2', 'm2_util', 'm2_parcela', 'habitaciones', 'banos', 'planta', 'orientacion',
        'anio', 'estado', 'energia_letra', 'energia_consumo', 'emisiones_letra', 'emisiones', 'comunidad', 'ibi', 'municipio', 'zona', 'provincia',
        'direccion', 'cp', 'lat', 'lon', 'titulo', 'descripcion'];

    /** Most specific first: ascii-lowercased label regex => field. */
    private const LABELS = [
        '/(ano|year).*(constru|built)|^construido en|antiguedad/' => 'anio',   // before «built» area
        '/(superficie|m2|metros)?\s*(construid|built)/' => 'm2',
        '/(superficie|m2|metros)?\s*(util|usable|useful)/' => 'm2_util',
        '/parcela|plot|superficie (del )?terreno|solar/' => 'm2_parcela',
        '/^(superficie|metros|m2|area|size)$/' => 'm2',
        '/habitacion|dormitorio|bedroom|^habs?\.?$/' => 'habitaciones',
        '/bano|aseo|bathroom/' => 'banos',
        '/^(planta|piso|altura|floor)\b|planta del piso/' => 'planta',
        '/orientaci|orientation|facing/' => 'orientacion',
        '/^estado|conservaci|condition/' => 'estado',
        '/emisi|emission/' => 'emisiones',
        '/consumo|consumption/' => 'energia_consumo',
        '/certificad|calificaci|clase energ|energy|eficiencia/' => 'energia_letra',
        '/comunidad|community|service charge/' => 'comunidad',
        '/\bibi\b|impuesto de bienes|property tax/' => 'ibi',
        '/^(ref|referencia|reference|codigo)\b/' => 'referencia',
        '/^(precio|price)\b/' => 'precio',
        '/^(tipo|tipologia|property type|type)\b/' => 'tipo',
        '/^(operacion|operation)\b/' => 'operacion',
        '/codigo postal|^c\.?p\.?$|post ?code|zip/' => 'cp',
        '/provincia|province|region/' => 'provincia',
        '/municipio|localidad|poblacion|ciudad|town|city/' => 'municipio',
        '/^(zona|barrio|urbanizacion|area|district|neighbourhood)\b/' => 'zona',
        '/direccion|address|calle/' => 'direccion',
    ];

    /** Canonical feature => words that announce it. Catalog extras (CatalogoController::EXTRAS) read these names. */
    public const FEATURES = [
        'Ascensor' => ['ascensor', 'lift', 'elevator'],
        'Garaje' => ['garaje', 'garage', 'parking', 'aparcamiento', 'plaza de garaje', 'cochera'],
        'Trastero' => ['trastero', 'storage room', 'storeroom'],
        'Piscina' => ['piscina', 'pool'],
        'Terraza' => ['terraza', 'terrace'],
        'Balcón' => ['balcon', 'balcony'],
        'Jardín' => ['jardin', 'garden'],
        'Aire acondicionado' => ['aire acondicionado', 'air conditioning', 'a/c', 'climatizacion', 'bomba de calor'],
        'Calefacción' => ['calefaccion', 'heating', 'suelo radiante', 'radiadores'],
        'Amueblado' => ['amueblado', 'furnished', 'muebles'],
        'Armarios empotrados' => ['armarios empotrados', 'armario empotrado', 'built-in wardrobe', 'fitted wardrobe'],
        'Chimenea' => ['chimenea', 'fireplace'],
        'Vistas al mar' => ['vistas al mar', 'sea view'],
        'Portero' => ['portero', 'conserje', 'concierge'],
        'Alarma' => ['alarma', 'alarm'],
        'Accesible' => ['accesible', 'wheelchair', 'movilidad reducida'],
        'Cocina equipada' => ['cocina equipada', 'cocina amueblada', 'fitted kitchen'],
        'Lavadero' => ['lavadero', 'utility room'],
        'Solárium' => ['solarium'],
        'Zonas comunes' => ['zonas comunes', 'communal'],
    ];

    /** Keys that would carry personal data (owner, agent, contact): never imported, not even into «otros datos». */
    public static function isPersonal(string $key): bool
    {
        return (bool) preg_match('/propietari|owner|contact|telefon|phone|tel$|^tel|movil|mobile|email|e-mail|correo|nombre|^name$|agente|agent|dni|nif|vendedor|seller|landlord|arrendador/i', Str::ascii($key));
    }

    /** Field for a label, or null (it then goes to «otros datos»). */
    public static function keyFor(string $label): ?string
    {
        $l = trim(Str::lower(Str::ascii($label)), " \t:.-");
        foreach (self::LABELS as $regex => $key) {
            if (preg_match($regex, $l)) {
                return $key;
            }
        }

        return null;
    }

    /** Canonical feature name for a text («Con ascensor», «Piscina comunitaria»…), null if unknown. */
    public static function feature(string $text): ?string
    {
        $t = ' '.Str::lower(Str::ascii($text)).' ';
        if (preg_match('/\b(sin|no)\b/', $t)) {
            return null;   // «Sin ascensor», «Ascensor: No»
        }
        foreach (self::FEATURES as $name => $words) {
            foreach ($words as $word) {
                if (str_contains($t, Str::ascii($word))) {
                    return $name;
                }
            }
        }

        return null;
    }

    /**
     * Adds «label → value» rows to a listing: known labels fill their field (if still empty), yes/no rows of known
     * features become features, the rest goes to «otros». Personal rows are dropped.
     *
     * @param  array<string, mixed>  $listing
     * @param  array<string, mixed>  $rows
     * @return array<string, mixed>
     */
    public static function absorb(array $listing, array $rows): array
    {
        foreach ($rows as $label => $value) {
            $label = trim((string) preg_replace('/\s+/', ' ', str_replace('_', ' ', (string) preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', (string) $label))));   // yearBuilt, year_built → year built
            if ($label === '' || self::isPersonal($label) || $value === null || $value === '' || $value === []) {
                continue;
            }
            if (is_array($value)) {
                $value = implode(', ', array_filter(array_map(fn ($v) => is_scalar($v) ? trim((string) $v) : null, self::flatten($value))));
            }
            $value = trim((string) $value);
            $yes = preg_match('/^(si|sí|yes|true|1|x|✓)$/iu', $value);
            $no = preg_match('/^(no|false|0)$/iu', $value);
            $key = self::keyFor($label);
            if ($key === null && ($feature = self::feature($label)) !== null) {
                if (! $no) {
                    $listing['caracteristicas'][] = $feature;
                }
                if ($yes || $no) {
                    continue;   // «Ascensor: Sí» is the feature itself; «Calefacción: Gas natural» also keeps its detail
                }
            }
            if ($key !== null && blank($listing[$key] ?? null)) {
                $listing[$key] = $value;
            } elseif ($key === null) {
                $listing['otros'][Str::ucfirst($label)] = $value;
            }
        }

        return $listing;
    }

    /** «E (120 kWh/m² año)» → [letter, number]. */
    public static function energy(mixed $value): array
    {
        $text = is_scalar($value) ? trim((string) $value) : '';
        $letter = preg_match('/(?<![A-Za-z])([A-G])(?![A-Za-z])/', $text, $m) ? $m[1] : null;
        $number = preg_match('/(\d+(?:[.,]\d+)?)/', $text, $n) ? (float) str_replace(',', '.', $n[1]) : null;

        return [$letter, $number];
    }

    /** @return array<string, mixed> nested arrays as «a.b» keys */
    public static function flatten(array $value, string $prefix = ''): array
    {
        $out = [];
        foreach ($value as $k => $v) {
            $key = $prefix === '' ? (string) $k : $prefix.'.'.$k;
            if (is_array($v)) {
                $out += self::flatten($v, $key);
            } else {
                $out[$key] = $v;
            }
        }

        return $out;
    }
}
