<?php

declare(strict_types=1);

namespace App\Portals\Pisos;

use App\Http\Controllers\CatalogoController;
use App\Models\PropertyText;
use Liberu\RealEstate\MediaAndDocuments\Models\MediaDocument;
use Liberu\RealEstate\Properties\Models\Property;

/**
 * Maps a property to pisos.com's `Inmueble` model (API v5). Only public data: the approved Castilian title and
 * description, no street address (location goes as zone code, postcode and approximate coordinates) and the
 * agency's authorised contact. Missing required data is listed before anything is sent.
 */
final class PisosPayload
{
    /** pisos.com feature keys (v4 import names; to confirm with pisos.com) for our detected extras. */
    private const FEATURES = [
        'ascensor' => 'Ascensor', 'trastero' => 'Trastero', 'jardin' => 'Jardin', 'piscina' => 'Piscina',
        'terraza' => 'Terraza', 'garaje' => 'Garaje', 'aire-acondicionado' => 'AireAcondicionado',
    ];

    /** @return list<string> human-readable list of what is missing before sending */
    public static function missing(Property $p): array
    {
        $texts = PropertyText::resolve($p, 'es')['values'];
        $status = $p->status instanceof \BackedEnum ? $p->status->value : (string) $p->status;

        return array_values(array_filter([
            ! in_array($status, ['available', 'published', 'under_offer'], true) ? 'Estado publicable (Disponible) en el panel' : null,
            blank($texts['title']) ? 'Título aprobado en castellano' : null,
            mb_strlen((string) $texts['description']) < 30 ? 'Descripción larga aprobada en castellano' : null,
            blank(config('pisos.tipos.'.$p->property_type)) ? 'Código pisos.com del tipo «'.$p->property_type.'»' : null,
            blank(config('pisos.operaciones.'.($p->deal_type?->value ?? 'sale'))) ? 'Código pisos.com de la operación' : null,
            ! ((float) $p->price > 0) ? 'Precio' : null,
            ! ((float) $p->area_sqft > 0) ? 'Superficie construida' : null,
            self::zone($p) === null ? 'Código de zona pisos.com del municipio o barrio' : null,
            blank($p->postal_code) ? 'Código postal' : null,
            self::photos($p) === [] ? 'Al menos una foto' : null,
            blank(config('pisos.contacto.telefono')) ? 'Teléfono de contacto autorizado (PISOS_CONTACTO_TELEFONO)' : null,
        ]));
    }

    /** @return array<string, mixed> Inmueble */
    public static function build(Property $p, ?string $externalId = null): array
    {
        $texts = PropertyText::resolve($p, 'es')['values'];
        $features = array_filter([
            'NumHabitaciones' => $p->bedrooms,
            'NumBanos' => $p->bathrooms,
            'SuperficieConstruida' => $p->area_sqft !== null ? (int) round((float) $p->area_sqft) : null,
            'AnoConstruccion' => $p->year_built,
            'energiaconsumocategoria' => $p->energy_rating ? strtoupper((string) $p->energy_rating) : null,
        ], fn ($v): bool => filled($v));
        foreach (CatalogoController::extrasOf($p) as $extra) {
            if (isset(self::FEATURES[$extra])) {
                $features[self::FEATURES[$extra]] = 'true';
            }
        }

        return array_filter([
            'ID' => $externalId,
            'Estado' => 0,
            'OrigenPublicacion' => (int) config('pisos.origen_publicacion'),
            'CodigoTipoInmueble' => config('pisos.tipos.'.$p->property_type),
            // v5 has no title or own-reference field: the title heads the description and our reference closes it
            // (it is also how a listing is found again after a timeout).
            'Descripcion' => trim($texts['title']."\n\n".$texts['description']."\n\nRef. ".$p->reference()),
            'Precios' => [[
                'TipoOperacion' => config('pisos.operaciones.'.($p->deal_type?->value ?? 'sale')),
                'Valor' => (float) $p->price,
                'Oculto' => false,
            ]],
            'Localizacion' => array_filter([
                'CodigoZonaGeo' => self::zone($p),
                'CodigoPostal' => $p->postal_code,
                // Same precision as the website catalog: never the exact position.
                'Latitud' => $p->latitude !== null ? round((float) $p->latitude, 2) : null,
                'Longitud' => $p->longitude !== null ? round((float) $p->longitude, 2) : null,
            ], fn ($v): bool => $v !== null),
            'Caracteristicas' => array_map(fn ($k, $v): array => ['Key' => $k, 'Value' => (string) $v], array_keys($features), $features),
            'Usuario' => array_filter([
                'ID' => config('pisos.user_id') ? (int) config('pisos.user_id') : null,
                'Nombre' => config('pisos.contacto.nombre'),
                'Email' => config('pisos.contacto.email'),
                'Telefono' => config('pisos.contacto.telefono'),
            ], fn ($v): bool => filled($v)),
        ], fn ($v): bool => $v !== null);
    }

    /** @return list<array{url: string, orden: int}> */
    public static function photos(Property $p): array
    {
        $width = max((array) config('catalogo.anchos_foto', [1600]));

        return MediaDocument::query()->where('property_id', $p->getKey())->where('team_id', $p->team_id)
            ->where('kind', 'photo')->orderBy('sort_order')->orderBy('id')->get()
            ->values()->map(fn (MediaDocument $d, int $i): array => ['url' => CatalogoController::photoUrl($d, $width), 'orden' => $i + 1])->all();
    }

    private static function zone(Property $p): ?string
    {
        $zones = (array) config('pisos.zonas');

        return $zones[$p->district?->slug] ?? $zones[$p->city?->slug] ?? null;
    }
}
