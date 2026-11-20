<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PropertyText;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\RealEstate\MediaAndDocuments\Models\MediaDocument;
use Liberu\RealEstate\Properties\Models\Property;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Read-only public catalog for the CLARTO website (clarto.es/inmuebles).
 *
 * Only listings whose status authorises publication (config catalogo.estados_publicos:
 * available/published = "disponible", under_offer = "reservado") of the agency's
 * team are returned, and only through an explicit whitelist of public fields:
 * no address, internal notes, owner/agent data, portal ids, insurance or audit data.
 * Drafts, moderation, withdrawn, sold and let listings are invisible here, including
 * their photos. Photos are served as signed, resized WebP variants that stop working
 * as soon as the listing leaves the public set.
 */
final class CatalogoController
{
    public const OPERACIONES = ['sale' => 'venta', 'rent' => 'alquiler', 'daily' => 'alquiler-temporal'];

    public const OPERACION_NOMBRES = ['venta' => 'Venta', 'alquiler' => 'Alquiler', 'alquiler-temporal' => 'Alquiler temporal'];

    /** Liberu property_type => public type key. */
    public const TIPOS = [
        'apartment' => 'piso', 'condo' => 'piso', 'residential' => 'piso',
        'house' => 'casa', 'cottage' => 'casa', 'guesthouse' => 'casa', 'hunting-lodge' => 'casa',
        'townhouse' => 'adosado', 'villa' => 'chalet',
        'new_build' => 'obra-nueva', 'development' => 'obra-nueva',
        'commercial' => 'local', 'mixed_use' => 'local', 'hmo' => 'edificio', 'hostel' => 'edificio',
        'land' => 'terreno',
    ];

    public const TIPO_NOMBRES = [
        'piso' => 'Piso', 'casa' => 'Casa', 'adosado' => 'Adosado', 'chalet' => 'Chalet',
        'obra-nueva' => 'Obra nueva', 'local' => 'Local', 'edificio' => 'Edificio', 'terreno' => 'Terreno', 'otro' => 'Inmueble',
    ];

    /** Public extra => keywords recognised in Liberu's free-form features. */
    public const EXTRAS = [
        'garaje' => ['Garaje', ['garaje', 'garage', 'parking', 'aparcamiento']],
        'trastero' => ['Trastero', ['trastero', 'storage']],
        'terraza' => ['Terraza', ['terraza', 'terrace', 'balcon', 'balcony']],
        'jardin' => ['Jardín', ['jardin', 'garden']],
        'piscina' => ['Piscina', ['piscina', 'pool']],
        'ascensor' => ['Ascensor', ['ascensor', 'lift', 'elevator']],
        'aire-acondicionado' => ['Aire acondicionado', ['aire acondicionado', 'aire-acondicionado', 'air conditioning', 'climatizacion']],
        'calefaccion' => ['Calefacción', ['calefaccion', 'heating']],
        'amueblado' => ['Amueblado', ['amueblado', 'furnished']],
        'vistas-al-mar' => ['Vistas al mar', ['vistas al mar', 'sea view']],
    ];

    private const POR_PAGINA = 12;

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'ubicacion' => ['nullable', 'string', 'max:300'],
            'operacion' => ['nullable', 'string', 'in:venta,alquiler,alquiler-temporal'],
            'tipo' => ['nullable', 'string', 'max:200'],
            'estado' => ['nullable', 'string', 'in:disponible,reservado'],
            'precio_min' => ['nullable', 'numeric', 'min:0'],
            'precio_max' => ['nullable', 'numeric', 'min:0'],
            'superficie_min' => ['nullable', 'numeric', 'min:0'],
            'superficie_max' => ['nullable', 'numeric', 'min:0'],
            'habitaciones_min' => ['nullable', 'integer', 'min:0', 'max:20'],
            'banos_min' => ['nullable', 'integer', 'min:0', 'max:20'],
            'extras' => ['nullable', 'string', 'max:300'],
            'orden' => ['nullable', 'string', 'in:recientes,precio_asc,precio_desc,superficie_desc'],
            'pagina' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'por_pagina' => ['nullable', 'integer', 'min:1', 'max:48'],
            'idioma' => ['nullable', 'string', 'in:es,en,tr'],
        ]);
        $locale = $filters['idioma'] ?? 'es';

        $properties = $this->publicProperties();
        $photos = $this->photosFor($properties, onlyFirst: true);
        $all = $properties->map(fn (Property $p): array => $this->card($p, $photos[$p->getKey()] ?? [], $locale))->values();

        $list = $all->filter(fn (array $c): bool => $this->matches($c, $filters))->values();
        $list = match ($filters['orden'] ?? 'recientes') {
            'precio_asc' => $list->sortBy(fn (array $c) => [$c['precio']['importe'] === null ? 1 : 0, $c['precio']['importe']])->values(),
            'precio_desc' => $list->sortByDesc(fn (array $c) => $c['precio']['importe'] ?? -1)->values(),
            'superficie_desc' => $list->sortByDesc(fn (array $c) => $c['superficie_m2'] ?? -1)->values(),
            default => $list->sortByDesc('publicado_en')->values(),
        };

        $perPage = (int) ($filters['por_pagina'] ?? self::POR_PAGINA);
        $page = (int) ($filters['pagina'] ?? 1);
        $pages = max(1, (int) ceil($list->count() / $perPage));

        return response()->json([
            'data' => $list->forPage($page, $perPage)->map(fn (array $c): array => $this->withoutInternal($c))->values(),
            'meta' => ['idioma' => $locale, 'total' => $list->count(), 'pagina' => $page, 'paginas' => $pages, 'por_pagina' => $perPage, 'total_publicados' => $all->count()],
            'facetas' => $this->facets($all),
        ]);
    }

    public function show(Request $request, string $referencia): JsonResponse
    {
        $locale = $request->validate(['idioma' => ['nullable', 'string', 'in:es,en,tr']])['idioma'] ?? 'es';
        $property = $this->findPublic($referencia);
        abort_if($property === null, 404, 'Inmueble no disponible.');

        $media = $this->photosFor(new Collection([$property]))[$property->getKey()] ?? [];
        $card = $this->card($property, $media, $locale);
        $texts = PropertyText::resolve($property, $locale);
        $photos = array_values(array_filter($media, fn (MediaDocument $d): bool => $d->kind === 'photo'));
        $plans = array_values(array_filter($media, fn (MediaDocument $d): bool => $d->kind !== 'photo'));

        $characteristics = array_values(array_filter([
            ['etiqueta' => 'Superficie construida', 'valor' => $card['superficie_m2'] !== null ? number_format($card['superficie_m2'], 0, ',', '.').' m²' : null],
            ['etiqueta' => 'Habitaciones', 'valor' => $card['habitaciones']],
            ['etiqueta' => 'Baños', 'valor' => $card['banos']],
            ['etiqueta' => 'Salones', 'valor' => $property->reception_rooms ?: null],
            ['etiqueta' => 'Año de construcción', 'valor' => $property->year_built ?: null],
            ['etiqueta' => 'Certificado energético', 'valor' => $property->energy_rating ?: null],
        ], fn (array $row): bool => $row['valor'] !== null && $row['valor'] !== ''));

        $tour = $property->getVirtualTourEmbed();

        return response()->json(['data' => $this->withoutInternal($card) + [
            'descripcion' => $this->cleanText((string) $texts['values']['description']),
            'seo' => ['titulo' => $texts['values']['seo_title'], 'descripcion' => $texts['values']['seo_description']],
            'idiomas_textos' => self::languageMap($texts['languages']),
            'traduccion_completa' => $this->complete($texts['languages'], $locale),
            'idiomas_completos' => array_values(array_filter(array_keys(PropertyText::LOCALES), fn (string $l): bool => $this->complete(PropertyText::resolve($property, $l)['languages'], $l))),
            'caracteristicas' => $characteristics,
            'extras' => array_map(fn (string $k): array => ['clave' => $k, 'nombre' => self::EXTRAS[$k][0]], $card['_extras']),
            'certificado_energetico' => $property->energy_rating ? ['calificacion' => strtoupper((string) $property->energy_rating)] : null,
            'galeria' => array_map(fn (MediaDocument $d): array => $this->image($d, $card['titulo']), $photos),
            'planos' => array_map(fn (MediaDocument $d): array => $this->image($d, 'Plano · '.$card['titulo']), $plans),
            'visita_virtual' => $tour !== null ? ['url' => $tour] : null,
            'coordenadas' => $this->coordinates($property),
        ]]);
    }

    public function sitemap(): JsonResponse
    {
        $properties = $this->publicProperties();

        return response()->json(['data' => $properties->map(fn (Property $p): array => [
            'referencia' => $p->reference(),
            'slug' => $this->slug($p),
            'actualizado_en' => $p->updated_at?->toIso8601String(),
        ])->values()]);
    }

    public function foto(int $documento, int $ancho): BinaryFileResponse
    {
        abort_unless(in_array($ancho, config('catalogo.anchos_foto'), true), 404);

        $doc = MediaDocument::query()->find($documento);
        abort_if($doc === null || ! in_array($doc->kind, ['photo', 'floorplan', 'siteplan'], true), 404);

        $property = $this->publicQuery()->whereKey($doc->property_id)->where('team_id', $doc->team_id)->first();
        abort_if($property === null, 404);

        $disk = Storage::disk('public');
        $relative = ltrim((string) $doc->path, '/');
        abort_if($relative === '' || str_contains($relative, '..') || ! $disk->exists($relative), 404);
        $source = $disk->path($relative);

        $cacheDir = storage_path('app/catalogo-cache');
        $target = sprintf('%s/%d-%d-%d.webp', $cacheDir, $doc->getKey(), $ancho, (int) filemtime($source));
        if (! is_file($target)) {
            if (! is_dir($cacheDir)) {
                mkdir($cacheDir, 0775, true);
            }
            $this->renderWebp($source, $target, $ancho);
        }

        return response()->file($target, [
            'Content-Type' => 'image/webp',
            // Short TTL: a withdrawn listing's photos stop being served within minutes.
            'Cache-Control' => 'public, max-age=300, stale-while-revalidate=60',
        ]);
    }

    // ---------------------------------------------------------------------

    private function team(): ?Team
    {
        return Team::query()->oldest()->first();
    }

    private function publicQuery()
    {
        $team = $this->team();

        return Property::query()
            ->when($team, fn ($q) => $q->forTeam($team->getKey()), fn ($q) => $q->whereRaw('1 = 0'))
            ->whereIn('status', array_keys(config('catalogo.estados_publicos')));
    }

    /** @return Collection<int, Property> */
    private function publicProperties(): Collection
    {
        return $this->publicQuery()->with(['region', 'city', 'district', 'clartoTexts'])->get();
    }

    private function findPublic(string $reference): ?Property
    {
        if (! preg_match('/^[A-Za-z]+-\d{4}-(\d+)$/', $reference, $m)) {
            return null;
        }
        $property = $this->publicQuery()->with(['region', 'city', 'district', 'clartoTexts'])->whereKey((int) $m[1])->first();

        return $property !== null && strcasecmp($property->reference(), $reference) === 0 ? $property : null;
    }

    /** @return array<int, list<MediaDocument>> property id => ordered media */
    private function photosFor(Collection $properties, bool $onlyFirst = false): array
    {
        if ($properties->isEmpty()) {
            return [];
        }

        $docs = MediaDocument::query()
            ->whereIn('property_id', $properties->modelKeys())
            ->whereIn('team_id', $properties->pluck('team_id')->unique()->all())
            ->whereIn('kind', $onlyFirst ? ['photo'] : ['photo', 'floorplan', 'siteplan'])
            ->orderBy('sort_order')->orderBy('id')
            ->get();

        $out = [];
        foreach ($docs as $doc) {
            $out[$doc->property_id][] = $doc;
        }

        return $out;
    }

    /** @param list<MediaDocument> $media */
    private function card(Property $p, array $media, string $locale = 'es'): array
    {
        $texts = PropertyText::resolve($p, $locale);
        $operation = self::OPERACIONES[$p->deal_type?->value ?? 'sale'] ?? 'venta';
        $type = self::TIPOS[(string) $p->property_type] ?? 'otro';
        $zone = $p->district?->name ?? $p->city?->name;
        $photos = array_values(array_filter($media, fn (MediaDocument $d): bool => $d->kind === 'photo'));
        $status = $p->status instanceof \BackedEnum ? $p->status->value : (string) $p->status;
        $title = trim((string) $texts['values']['title']) !== '' ? trim((string) $texts['values']['title'])
            : self::TIPO_NOMBRES[$type].($zone ? ' en '.$zone : '');

        return [
            'referencia' => $p->reference(),
            'slug' => $this->slug($p),
            'titulo' => $title,
            'resumen' => $texts['values']['summary'],
            // Language each public text is really in: the requested one, or es when it is still a fallback.
            'idioma' => $locale,
            'idiomas_textos' => self::languageMap(array_intersect_key($texts['languages'], array_flip(['title', 'summary']))),
            'traduccion_completa' => $this->complete($texts['languages'], $locale),
            'operacion' => $operation,
            'operacion_nombre' => self::OPERACION_NOMBRES[$operation],
            'tipo' => $type,
            'tipo_nombre' => self::TIPO_NOMBRES[$type],
            'estado' => config('catalogo.estados_publicos')[$status] ?? 'disponible',
            'es_prueba' => (bool) config('catalogo.modo_pruebas'),
            'precio' => [
                'importe' => $p->price !== null ? (float) $p->price : null,
                'moneda' => strtoupper((string) ($p->currency ?: 'EUR')),
                'periodo' => $operation === 'venta' ? null : ($operation === 'alquiler' ? 'mes' : 'noche'),
            ],
            'ubicacion' => [
                'zona' => $zone,
                'zona_slug' => $p->district?->slug ?? $p->city?->slug,
                'municipio' => $p->city?->name,
                'municipio_slug' => $p->city?->slug,
                'provincia' => $p->region?->name,
                'provincia_slug' => $p->region?->slug,
            ],
            // The panel records surface in m² (CLARTO staging relabels the area field).
            'superficie_m2' => $p->area_sqft !== null ? round((float) $p->area_sqft, 2) : null,
            'habitaciones' => $p->bedrooms,
            'banos' => $p->bathrooms,
            'foto' => $photos !== [] ? $this->image($photos[0], $title) : null,
            'num_fotos' => count($photos),
            'publicado_en' => ($p->published_at ?? $p->created_at)?->toIso8601String(),
            'actualizado_en' => $p->updated_at?->toIso8601String(),
            '_extras' => $this->extras($p),
            '_texto' => Str::lower(Str::ascii(implode(' ', [$title, (string) $p->title, $p->reference(), $zone, $p->city?->name, $p->region?->name]))),
        ];
    }

    /**
     * @param  array<string, ?string>  $languages  field => language of the returned text
     * @return array<string, ?string>
     */
    private static function languageMap(array $languages): array
    {
        $names = ['title' => 'titulo', 'summary' => 'resumen', 'description' => 'descripcion', 'seo_title' => 'seo_titulo', 'seo_description' => 'seo_descripcion'];
        $out = [];
        foreach ($languages as $field => $language) {
            $out[$names[$field]] = $language;
        }

        return $out;
    }

    /** A language is complete when its title and long description are really in that language. */
    private function complete(array $languages, string $locale): bool
    {
        return ($languages['title'] ?? null) === $locale && ($languages['description'] ?? null) === $locale;
    }

    private function withoutInternal(array $card): array
    {
        $card['extras'] = $card['_extras'];
        unset($card['_extras'], $card['_texto']);

        return $card;
    }

    /** @return list<string> */
    private function extras(Property $p): array
    {
        $features = collect(is_array($p->features) ? $p->features : [])
            ->map(fn ($v, $k) => is_string($k) ? ($v ? $k : null) : (is_string($v) ? $v : null))
            ->filter()->map(fn (string $f): string => Str::lower(Str::ascii($f)))->implode(' | ');

        $found = [];
        foreach (self::EXTRAS as $key => [, $words]) {
            foreach ($words as $word) {
                if ($features !== '' && str_contains($features, Str::ascii($word))) {
                    $found[] = $key;
                    break;
                }
            }
        }
        if ($p->has_parking || ! empty($p->parking)) {
            $found[] = 'garaje';
        }
        if (! empty($p->gardens)) {
            $found[] = 'jardin';
        }

        return array_values(array_unique($found));
    }

    private function matches(array $c, array $f): bool
    {
        if (! empty($f['q']) && ! str_contains($c['_texto'], Str::lower(Str::ascii($f['q'])))) {
            return false;
        }
        if (! empty($f['ubicacion'])) {
            $wanted = array_filter(explode(',', Str::lower($f['ubicacion'])));
            $u = $c['ubicacion'];
            if (array_intersect($wanted, array_filter([$u['zona_slug'], $u['municipio_slug'], $u['provincia_slug']])) === []) {
                return false;
            }
        }
        if (! empty($f['operacion']) && $c['operacion'] !== $f['operacion']) {
            return false;
        }
        if (! empty($f['tipo']) && ! in_array($c['tipo'], explode(',', $f['tipo']), true)) {
            return false;
        }
        if (! empty($f['estado']) && $c['estado'] !== $f['estado']) {
            return false;
        }
        $price = $c['precio']['importe'];
        if (isset($f['precio_min']) && ($price === null || $price < (float) $f['precio_min'])) {
            return false;
        }
        if (isset($f['precio_max']) && ($price === null || $price > (float) $f['precio_max'])) {
            return false;
        }
        $area = $c['superficie_m2'];
        if (isset($f['superficie_min']) && ($area === null || $area < (float) $f['superficie_min'])) {
            return false;
        }
        if (isset($f['superficie_max']) && ($area === null || $area > (float) $f['superficie_max'])) {
            return false;
        }
        if (isset($f['habitaciones_min']) && (int) $c['habitaciones'] < (int) $f['habitaciones_min']) {
            return false;
        }
        if (isset($f['banos_min']) && (int) $c['banos'] < (int) $f['banos_min']) {
            return false;
        }
        if (! empty($f['extras']) && array_diff(array_filter(explode(',', $f['extras'])), $c['_extras']) !== []) {
            return false;
        }

        return true;
    }

    private function facets(\Illuminate\Support\Collection $all): array
    {
        $count = fn (string $field, ?string $slugField = null) => $all
            ->filter(fn (array $c) => ($slugField ? data_get($c, $slugField) : $c[$field]) !== null)
            ->groupBy(fn (array $c) => $slugField ? data_get($c, $slugField) : $c[$field])
            ->map(fn ($group, $key) => ['valor' => (string) $key, 'nombre' => $slugField ? data_get($group->first(), str_replace('_slug', '', $slugField)) : null, 'total' => $group->count()])
            ->sortByDesc('total')->values();

        $prices = $all->pluck('precio.importe')->filter(fn ($v) => $v !== null);
        $areas = $all->pluck('superficie_m2')->filter(fn ($v) => $v !== null);

        return [
            'municipios' => $count('municipio', 'ubicacion.municipio_slug'),
            'zonas' => $count('zona', 'ubicacion.zona_slug'),
            'operaciones' => $count('operacion')->map(fn ($r) => $r + ['nombre' => self::OPERACION_NOMBRES[$r['valor']] ?? $r['valor']])->values(),
            'tipos' => $count('tipo')->map(fn ($r) => array_merge($r, ['nombre' => self::TIPO_NOMBRES[$r['valor']] ?? $r['valor']]))->values(),
            'extras' => collect(self::EXTRAS)->map(fn ($e, $k) => ['valor' => $k, 'nombre' => $e[0], 'total' => $all->filter(fn ($c) => in_array($k, $c['_extras'], true))->count()])
                ->filter(fn ($r) => $r['total'] > 0)->values(),
            'precio' => ['min' => $prices->min(), 'max' => $prices->max()],
            'superficie' => ['min' => $areas->min(), 'max' => $areas->max()],
        ];
    }

    /** Public, signed WebP URL of a catalog photo (served only while the property is public). */
    public static function photoUrl(MediaDocument $doc, int $width): string
    {
        return URL::signedRoute('catalogo.foto', ['documento' => $doc->getKey(), 'ancho' => $width]);
    }

    /** @return list<string> extras keys detected on a property (see EXTRAS) */
    public static function extrasOf(Property $p): array
    {
        return (new self)->extras($p);
    }

    private function image(MediaDocument $doc, string $alt): array
    {
        $widths = config('catalogo.anchos_foto');
        $url = fn (int $w): string => URL::signedRoute('catalogo.foto', ['documento' => $doc->getKey(), 'ancho' => $w]);

        return [
            'src' => $url($widths[1] ?? $widths[0]),
            'srcset' => implode(', ', array_map(fn (int $w): string => $url($w).' '.$w.'w', $widths)),
            'alt' => trim((string) $doc->title) !== '' ? (string) $doc->title : $alt,
        ];
    }

    private function coordinates(Property $p): ?array
    {
        $mode = (string) config('catalogo.precision_ubicacion');
        if ($mode === 'zona' || $p->latitude === null || $p->longitude === null) {
            return null;
        }
        $decimals = $mode === 'exacta' ? 6 : 2;

        return ['lat' => round((float) $p->latitude, $decimals), 'lng' => round((float) $p->longitude, $decimals), 'precision' => $mode];
    }

    private function slug(Property $p): string
    {
        $type = self::TIPO_NOMBRES[self::TIPOS[(string) $p->property_type] ?? 'otro'];
        $operation = ($p->deal_type?->value ?? 'sale') === 'sale' ? 'venta' : 'alquiler';
        $zone = $p->district?->name ?? $p->city?->name ?? '';

        return Str::slug(trim("{$type} {$operation} {$zone} {$p->reference()}"));
    }

    private function cleanText(string $text): string
    {
        return trim(strip_tags($text));
    }

    private function renderWebp(string $source, string $target, int $width): void
    {
        $image = imagecreatefromstring((string) file_get_contents($source));
        abort_if($image === false, 404);

        if (function_exists('exif_read_data') && @exif_imagetype($source) === IMAGETYPE_JPEG) {
            $orientation = (int) (@exif_read_data($source)['Orientation'] ?? 1);
            $image = match ($orientation) {
                3 => imagerotate($image, 180, 0),
                6 => imagerotate($image, -90, 0),
                8 => imagerotate($image, 90, 0),
                default => $image,
            };
        }

        if (imagesx($image) > $width) {
            $image = imagescale($image, $width, -1, IMG_BICUBIC);
        }
        imagewebp($image, $target.'.tmp', 80);
        rename($target.'.tmp', $target);
    }
}
