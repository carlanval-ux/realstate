<?php

declare(strict_types=1);

namespace App\Collaborators\Import;

use App\Collaborators\Collaborations;
use App\Collaborators\Collaborator;
use App\Collaborators\CollaboratorProperty;
use App\Models\PropertyText;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\RealEstate\MediaAndDocuments\Models\MediaDocument;
use Liberu\RealEstate\Properties\Domain\PropertyStatus;
use Liberu\RealEstate\Properties\Models\City;
use Liberu\RealEstate\Properties\Models\District;
use Liberu\RealEstate\Properties\Models\Property;
use Liberu\RealEstate\Properties\Models\Region;
use Throwable;

/**
 * Collaborator property import (docs/clarto/importacion-colaboradores.md). run() is the ONLY entry point (scheduled,
 * «Importar ahora», CLI) and refuses to do anything — not even one HTTP request — without the written authorization date.
 *
 * Each listing becomes a property of CLARTO's team keyed by collaborator + external reference: created as «draft»
 * (never in the public catalog until staff publish it), linked to the collaborator («aportado», no notice) and marked
 * with its origin. EVERYTHING the listing shows is kept: Liberu columns, draft translations, every photo / plan / video
 * (mirrored: added, removed, reordered) and «otros datos» for the rest. Later runs only write the fields that changed AT
 * THE SOURCE, so staff edits survive. Listings gone from the source become «retirado» (property withdrawn, never
 * deleted). No owner data is ever read: personal keys are dropped and e-mails / phone numbers are blanked out of texts.
 * Nothing goes to GoHighLevel.
 */
final class CollaboratorImports
{
    /** Property statuses the importer may withdraw (sold / let / already withdrawn are left alone). */
    private const LIVE = ['draft', 'moderation', 'published', 'available', 'under_offer', 'coming_soon', 'For Sale', 'For Rent', 'to_let'];

    /** @return array{ok: bool, status: string, message: string, counts: array<string, int>} */
    public static function run(CollaboratorImport $import): array
    {
        $import->forceFill(['requested_at' => null, 'requested_by' => null])->save();
        $collaborator = $import->collaborator;

        // The gate: written authorization first, then a usable configuration.
        $refusal = match (true) {
            ! $import->isAuthorized() => ['sin_autorizacion', __('importacion.gate.refused')],
            ! Collaborations::enabled() => ['error', __('colaboradores.module.off')],
            $collaborator === null || $collaborator->status === Collaborator::INACTIVE => ['error', __('importacion.errors.inactive')],
            ! $import->isConfigured() => ['error', __('importacion.errors.not_configured')],
            default => null,
        };
        if ($refusal !== null) {
            return self::finish($import, $refusal[0], $refusal[1], []);
        }

        $lock = Cache::lock('clarto:importacion:'.$import->id, 7200);
        if (! $lock->get()) {
            return ['ok' => false, 'status' => 'en_curso', 'message' => __('importacion.status.en_curso'), 'counts' => []];
        }
        $counts = ['leidos' => 0, 'nuevos' => 0, 'actualizados' => 0, 'sin_cambios' => 0, 'retirados' => 0, 'fotos' => 0, 'fotos_quitadas' => 0, 'errores' => 0, 'paginas' => 0, 'ia' => 0];
        $import->forceFill(['last_status' => 'en_curso', 'last_run_at' => now()])->save();
        try {
            if ($import->source === CollaboratorImport::FEED) {
                $listings = FeedParser::parse(ImportHttp::get((string) $import->feed_url, 60)->throw()->body(), (string) $import->feed_format, $import->feed_mapping ?? []);
                $complete = true;
                $notes = [];
                $counts['paginas'] = 1;
            } else {
                $crawl = app(WebCrawler::class)->crawl((string) $import->web_url, $import->web_pattern);
                ['listings' => $listings, 'complete' => $complete, 'notes' => $notes] = $crawl;
                $counts['paginas'] = $crawl['pages'];
                $counts['ia'] = $crawl['ai'];
            }
            $counts['leidos'] = count($listings);

            $seen = [];
            foreach ($listings as $listing) {
                try {
                    $ref = self::key($import->source, $listing);
                    if ($ref === null || isset($seen[$ref])) {
                        continue;
                    }
                    $seen[$ref] = true;
                    $result = self::upsert($import, $collaborator, $ref, $listing, $counts);
                    $counts[$result]++;
                } catch (Throwable $e) {
                    $counts['errores']++;
                    $notes[] = Str::limit(($listing['referencia'] ?? $listing['url'] ?? '?').': '.$e->getMessage(), 200);
                    report($e);
                }
            }

            // Retire only after a complete read that found something (an empty or truncated read retires nothing).
            if ($complete && $seen !== []) {
                $counts['retirados'] = self::retireUnseen($import, array_keys($seen));
            } elseif (! $complete) {
                $notes[] = __('importacion.status.no_retire');
            }
            $status = $complete && $counts['errores'] === 0 ? 'ok' : 'parcial';

            return self::finish($import, $status, implode("\n", array_unique($notes)), $counts);
        } catch (Throwable $e) {
            report($e);

            return self::finish($import, 'error', Str::limit($e->getMessage(), 500), $counts);
        } finally {
            $lock->release();
        }
    }

    /** Daily runs (scheduler, 05:30) and «Importar ahora» requests (picked up every minute). */
    public static function runDue(bool $daily): int
    {
        $due = CollaboratorImport::query()->where('source', '!=', CollaboratorImport::NONE)
            ->where(fn ($q) => $daily ? $q->where('schedule', 'diario')->whereNotNull('authorized_at') : $q->whereNotNull('requested_at'))
            ->get();
        foreach ($due as $import) {
            rescue(fn () => self::run($import), report: true);
        }

        return $due->count();
    }

    /** Stable key: the feed's reference; for web pages the page URL (references read by the AI are not stable). */
    private static function key(string $source, array $listing): ?string
    {
        if ($source === CollaboratorImport::WEB) {
            $url = (string) ($listing['url'] ?? '');

            return $url === '' ? null : 'web-'.substr(sha1(Str::lower(rtrim((string) preg_replace('~[?#].*$~', '', $url), '/'))), 0, 20);
        }
        $ref = trim((string) ($listing['referencia'] ?? ''));

        return $ref === '' ? null : Str::limit($ref, 180, '');
    }

    /** @param array<string, int> $counts */
    private static function upsert(CollaboratorImport $import, Collaborator $collaborator, string $ref, array $listing, array &$counts): string
    {
        $cleaner = PublicTextCleaner::for($collaborator, $import, $listing['referencia'] ?? null, [$listing['url'] ?? null]);
        [$listing, $originals, $removed, $tours] = self::whiteLabel($listing, $cleaner);
        $values = self::attributes($listing);
        $extra = $originals + self::extra($listing);
        $polite = $import->source === CollaboratorImport::WEB;
        $row = ImportedProperty::query()->where('collaborator_id', $collaborator->id)->where('external_ref', $ref)->first();
        $property = $row?->property;
        $photosBefore = $counts['fotos'];

        if ($row === null || $property === null) {
            $property = Property::query()->create($values + [
                'team_id' => $import->team_id,
                'created_by' => $collaborator->owner_id ?? Team::query()->find($import->team_id)?->user_id,
                'status' => PropertyStatus::Draft,
                'currency' => 'EUR',
                'internal_notes' => __('importacion.note', ['name' => $collaborator->name, 'source' => __('importacion.sources.'.$import->source),
                    'ref' => $listing['referencia'] ?? $ref, 'url' => $listing['url'] ?? '—', 'date' => now()->format('d/m/Y')]),
            ]);
            $row = ImportedProperty::query()->updateOrCreate(['collaborator_id' => $collaborator->id, 'external_ref' => $ref], [
                'team_id' => $import->team_id, 'property_id' => $property->getKey(), 'source' => $import->source, 'source_url' => $listing['url'] ?? null,
                'status' => ImportedProperty::ACTIVE, 'data' => $values, 'extra' => $extra, 'photos' => [], 'first_seen_at' => now(), 'last_seen_at' => now(), 'withdrawn_at' => null,
            ]);
            CollaboratorProperty::query()->create(['team_id' => $import->team_id, 'collaborator_id' => $collaborator->id, 'property_id' => $property->getKey(),
                'relation' => 'aportado', 'authorised_price' => $property->price, 'availability' => 'disponible', 'assigned_at' => now()]);
            Collaborator::log($property, 'importado', ['colaborador' => $collaborator->id, 'origen' => $import->source, 'referencia' => $ref, 'url' => $listing['url'] ?? null]);
            self::texts($property, $listing);
            self::media($row, $property, $listing, $polite, $counts);
            self::review($row, $property, $cleaner, $removed, $tours, $counts['fotos'] > $photosBefore);

            return 'nuevos';
        }

        if ($property->trashed()) {   // deleted by staff on purpose: leave it deleted
            $row->forceFill(['last_seen_at' => now()])->save();

            return 'sin_cambios';
        }

        $previous = $row->data ?? [];
        $same = fn ($a, $b): bool => json_encode(is_float($a) || is_int($a) ? (float) $a : $a) === json_encode(is_float($b) || is_int($b) ? (float) $b : $b);
        $changed = array_filter($values, fn ($v, string $k): bool => ! array_key_exists($k, $previous) || ! $same($previous[$k], $v), ARRAY_FILTER_USE_BOTH);
        $old = $row->extra ?? [];
        $extraChanged = array_values(array_filter(array_unique([...array_keys($old), ...array_keys($extra)]), fn ($k): bool => ! $same($old[$k] ?? null, $extra[$k] ?? null)));
        if ($extraChanged !== []) {
            Collaborator::log($property, 'importacion_otros_datos', ['colaborador' => $collaborator->id, 'campos' => $extraChanged]);
        }
        $reappeared = $row->status === ImportedProperty::WITHDRAWN;
        if ($changed !== [] || $reappeared) {
            $diff = [];
            foreach ($changed as $k => $v) {
                $diff[$k] = [$property->getRawOriginal($k), $v];
            }
            $property->forceFill($changed + ($reappeared && $property->status === PropertyStatus::Withdrawn ? ['status' => PropertyStatus::Draft] : []))->save();
            Collaborator::log($property, $reappeared ? 'importacion_reaparecido' : 'importacion_actualizado', ['colaborador' => $collaborator->id, 'cambios' => $diff]);
        }
        $row->forceFill(['data' => $values, 'extra' => $extra, 'status' => ImportedProperty::ACTIVE, 'withdrawn_at' => null, 'last_seen_at' => now(), 'source_url' => $listing['url'] ?? $row->source_url])->save();
        $textsChanged = self::texts($property, $listing);
        $mediaChanged = self::media($row, $property, $listing, $polite, $counts);
        $textChanged = $textsChanged || array_intersect(array_keys($changed), ['title', 'description', 'features']) !== [];
        $tourChanged = in_array(__('importacion.extra.tour'), $extraChanged, true);
        self::review($row, $property, $cleaner, $textChanged ? $removed : [], $tourChanged ? $tours : [], $counts['fotos'] > $photosBefore);

        return $changed !== [] || $reappeared || $extraChanged !== [] || $textsChanged || $mediaChanged ? 'actualizados' : 'sin_cambios';
    }

    /**
     * White label (clarto.es must not reveal the collaborator): public texts are cleaned before they reach the property
     * (PublicTextCleaner), the originals stay in «otros datos» for staff, features naming the collaborator are dropped
     * and the collaborator's virtual tour (its branding, its link) only goes to «otros datos» until staff decide.
     *
     * @param  array<string, mixed>  $l
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: list<string>, 3: list<string>} listing, originals, removed pieces, tours
     */
    private static function whiteLabel(array $l, PublicTextCleaner $cleaner): array
    {
        $originals = [];
        $removed = [];
        $clean = function (mixed $text, string $label, bool $multiline) use ($cleaner, &$originals, &$removed): ?string {
            $text = self::scrub(self::clean($text, $multiline));
            [$result, $gone] = $cleaner->clean($text);
            if ($gone !== []) {
                $originals[$label] = $text;
                $removed = [...$removed, ...$gone];
            }

            return $result;
        };
        $l['titulo'] = $clean($l['titulo'] ?? null, __('importacion.extra.original_title'), false);
        $l['descripcion'] = $clean($l['descripcion'] ?? null, __('importacion.extra.original_description'), true);
        foreach (['descripciones' => 'original_description', 'titulos' => 'original_title'] as $key => $label) {
            foreach ((array) ($l[$key] ?? []) as $lang => $text) {
                $l[$key][$lang] = $clean($text, __('importacion.extra.'.$label).' ('.Str::upper((string) $lang).')', $key === 'descripciones');
            }
        }
        $l['caracteristicas'] = array_values(array_filter((array) ($l['caracteristicas'] ?? []), function ($f) use ($cleaner, &$removed): bool {
            $gone = is_string($f) ? $cleaner->clean($f)[1] : [];
            $removed = [...$removed, ...$gone];

            return $gone === [];
        }));
        $tours = array_values(array_filter((array) ($l['tours'] ?? []), 'is_string'));
        if ($tours !== []) {
            $originals[__('importacion.extra.tour')] = $tours;
            $l['tours'] = [];
        }

        return [$l, $originals, array_values(array_unique($removed)), $tours];
    }

    /**
     * Review flags shown at the top of the property record until staff mark them reviewed: text with collaborator
     * traces removed, the collaborator's virtual tour waiting for a decision, photos that may carry its watermark.
     *
     * @param  list<string>  $removed
     * @param  list<string>  $tours
     */
    private static function review(ImportedProperty $row, Property $property, PublicTextCleaner $cleaner, array $removed, array $tours, bool $newPhotos): void
    {
        $flags = array_filter([
            'textos' => $removed !== [] ? array_slice($removed, 0, 20) : null,
            'visita' => $tours !== [] ? $tours[0] : null,
            'fotos' => $newPhotos ? WatermarkCheck::suspect(MediaDocument::query()->where('property_id', $property->getKey())->where('kind', 'photo')
                ->orderBy('sort_order')->get()->filter(fn (MediaDocument $d) => filled(data_get($d->metadata, 'origen')))->pluck('path')->all(), $cleaner->brands()) : null,
        ]);
        if ($flags === []) {
            return;
        }
        $row->forceFill(['review' => array_merge($row->review ?? [], $flags, ['desde' => now()->toIso8601String()])])->save();
        Collaborator::log($property, 'importacion_revisar', ['colaborador' => $row->collaborator_id, 'motivos' => array_keys($flags)]);
        // Watermark found and the collaborator authorized removing it: queue the cleanup (staff still approve each photo).
        if (isset($flags['fotos']) && WatermarkRemoval::refusal($property) === null) {
            rescue(fn () => WatermarkRemoval::request($property), report: false);
        }
    }

    /** @param list<string> $seen */
    private static function retireUnseen(CollaboratorImport $import, array $seen): int
    {
        $gone = ImportedProperty::query()->where('collaborator_id', $import->collaborator_id)->where('status', ImportedProperty::ACTIVE)
            ->whereNotIn('external_ref', $seen)->get();
        foreach ($gone as $row) {
            $row->forceFill(['status' => ImportedProperty::WITHDRAWN, 'withdrawn_at' => now()])->save();
            $property = $row->property;
            if ($property !== null && ! $property->trashed() && in_array($property->status?->value, self::LIVE, true)) {
                $property->forceFill(['status' => PropertyStatus::Withdrawn])->save();
            }
            Collaborator::log($property ?? $row, 'importacion_retirado', ['colaborador' => $import->collaborator_id, 'referencia' => $row->external_ref]);
        }

        return $gone->count();
    }

    /**
     * Listing fields → property columns: everything Liberu has a column for. The address is what the listing
     * publishes (street if shown, else zone and town); it never leaves the panel (the public catalog has no address).
     *
     * @param  array<string, mixed>  $l
     * @return array<string, mixed>
     */
    public static function attributes(array $l): array
    {
        $town = self::clean($l['municipio'] ?? null);
        $zone = self::clean($l['zona'] ?? null);
        $province = self::clean($l['provincia'] ?? null);
        $region = $province ? Region::query()->whereRaw('lower(name) = ?', [Str::lower($province)])->first() : null;
        $cities = $town ? City::query()->whereRaw('lower(name) = ?', [Str::lower($town)])->when($region, fn ($q) => $q->where('region_id', $region->id))->limit(2)->get() : collect();
        $city = $cities->count() === 1 ? $cities->first() : null;
        $district = $city && $zone ? District::query()->where('city_id', $city->id)->whereRaw('lower(name) = ?', [Str::lower($zone)])->first() : null;
        $descriptions = self::descriptions($l);
        $type = self::type((string) ($l['tipo'] ?? ''));
        $title = self::scrub(self::clean($l['titulo'] ?? null)) ?? trim(__('importacion.title', ['type' => __('importacion.types.'.$type), 'town' => $zone ?? $town ?? '']), ' ,');
        $year = ($y = self::number($l['anio'] ?? null)) !== null && $y >= Property::EARLIEST_YEAR_BUILT && $y <= Property::latestYearBuilt() ? (int) $y : null;
        $letter = fn (mixed $v): ?string => ($x = ListingFields::energy($v)[0]) !== null ? $x : null;
        $energy = $letter($l['energia_letra'] ?? null);
        $emissionsLetter = $letter($l['emisiones_letra'] ?? null) ?? (is_string($l['emisiones'] ?? null) ? $letter($l['emisiones']) : null);
        $epc = array_filter([
            'calificacion_consumo' => $energy,
            'consumo_kwh_m2_ano' => ListingFields::energy($l['energia_consumo'] ?? null)[1] ?? (is_string($l['energia_letra'] ?? null) ? ListingFields::energy($l['energia_letra'])[1] : null),
            'calificacion_emisiones' => $emissionsLetter,
            'emisiones_kg_co2_m2_ano' => ListingFields::energy($l['emisiones'] ?? null)[1],
        ], fn ($v) => $v !== null);
        $features = array_values(array_unique(array_filter(array_map(fn ($f) => is_string($f) ? self::scrub(self::clean($f)) : null, (array) ($l['caracteristicas'] ?? [])))));
        $lat = is_numeric($l['lat'] ?? null) && abs((float) $l['lat']) <= 90 ? (float) $l['lat'] : null;
        $lon = is_numeric($l['lon'] ?? null) && abs((float) $l['lon']) <= 180 ? (float) $l['lon'] : null;
        $street = self::scrub(self::clean($l['direccion'] ?? null));

        return array_filter([
            'title' => Str::limit((string) $title, 180, ''),
            'description' => $descriptions['es'] ?? (reset($descriptions) ?: null),
            'price' => self::number($l['precio'] ?? null),
            'deal_type' => self::operation((string) ($l['operacion'] ?? '')),
            'property_type' => $type,
            'area_sqft' => self::number($l['m2'] ?? null) ?? self::number($l['m2_util'] ?? null),
            'bedrooms' => ($n = self::number($l['habitaciones'] ?? null)) !== null ? (int) $n : null,
            'bathrooms' => ($n = self::number($l['banos'] ?? null)) !== null ? (int) $n : null,
            'year_built' => $year,
            'energy_rating' => $energy,
            'epc' => $epc ?: null,
            'features' => $features ?: null,
            'has_parking' => in_array('Garaje', $features, true) ? true : null,
            'service_charge' => self::number($l['comunidad'] ?? null),
            'address' => Str::limit(implode(', ', array_filter([$street, $street ? null : $zone, $town])) ?: __('importacion.no_location'), 250, ''),
            'postal_code' => preg_match('/\b\d{5}\b/', (string) ($l['cp'] ?? ''), $cp) ? $cp[0] : null,
            'latitude' => $lat,
            'longitude' => $lon,
            'virtual_tour_url' => ($tour = collect($l['tours'] ?? [])->first(fn ($u) => is_string($u) && strlen($u) <= 2048)) ? $tour : null,
            'city_id' => $city?->id,
            'region_id' => $city?->region_id ?? $region?->id,
            'district_id' => $district?->id,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /**
     * «Otros datos»: what the listing shows and Liberu has no column for (shown in the property's «Colaboradores» tab).
     *
     * @param  array<string, mixed>  $l
     * @return array<string, mixed>
     */
    public static function extra(array $l): array
    {
        $descriptions = self::descriptions($l);
        $m2 = fn (string $k): ?string => ($n = self::number($l[$k] ?? null)) !== null ? rtrim(rtrim(number_format($n, 2, ',', '.'), '0'), ',').' m²' : null;
        $eur = fn (string $k, string $per): ?string => ($n = self::number($l[$k] ?? null)) !== null ? number_format($n, 2, ',', '.').' €'.$per : (self::clean($l[$k] ?? null));
        $videos = array_values(array_filter((array) ($l['videos'] ?? []), fn ($u) => is_string($u) && ! self::isPlatformVideo($u)));
        $otros = [];
        foreach ((array) ($l['otros'] ?? []) as $label => $value) {
            if (! ListingFields::isPersonal((string) $label) && is_scalar($value) && ($v = self::scrub(self::clean($value))) !== null) {
                $otros[Str::limit((string) $label, 80, '')] = Str::limit($v, 500);
            }
        }

        return array_filter([
            __('importacion.extra.reference') => self::clean($l['referencia'] ?? null),
            __('importacion.extra.url') => self::clean($l['url'] ?? null),
            __('importacion.extra.usable') => $m2('m2_util'),
            __('importacion.extra.plot') => $m2('m2_parcela'),
            __('importacion.extra.floor') => self::clean($l['planta'] ?? null),
            __('importacion.extra.orientation') => self::clean($l['orientacion'] ?? null),
            __('importacion.extra.condition') => self::clean($l['estado'] ?? null),
            __('importacion.extra.community') => $eur('comunidad', '/mes'),
            __('importacion.extra.ibi') => $eur('ibi', '/año'),
            __('importacion.extra.zone') => self::clean($l['zona'] ?? null),
            __('importacion.extra.town') => self::clean($l['municipio'] ?? null),
            __('importacion.extra.province') => self::clean($l['provincia'] ?? null),
            __('importacion.extra.descriptions') => array_diff_key($descriptions, array_flip(array_keys(PropertyText::LOCALES))) ?: null,
            __('importacion.extra.videos') => $videos ?: null,
            __('importacion.extra.tours') => array_slice(array_values((array) ($l['tours'] ?? [])), 1) ?: null,
        ] + $otros, fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    /** @return array<string, string> lang => description (scrubbed), Castilian first */
    private static function descriptions(array $l): array
    {
        $out = [];
        foreach (['es' => $l['descripcion'] ?? null] + (array) ($l['descripciones'] ?? []) as $lang => $text) {
            $lang = Str::lower(substr((string) $lang, 0, 2));
            if (! isset($out[$lang]) && ($t = self::scrub(self::clean($text, multiline: true))) !== null) {
                $out[$lang] = $t;
            }
        }

        return $out;
    }

    /**
     * English / Turkish texts of the listing as DRAFT translations (staff approve them; the website only reads approved
     * ones). A text staff wrote or approved is never overwritten. Other languages stay in «otros datos».
     */
    private static function texts(Property $property, array $l): bool
    {
        $descriptions = self::descriptions($l);
        $changed = false;
        foreach (array_keys(PropertyText::LOCALES) as $locale) {
            if ($locale === 'es' || ! isset($descriptions[$locale])) {
                continue;
            }
            $text = PropertyText::query()->firstOrNew(['property_id' => $property->getKey(), 'locale' => $locale]);
            if ($text->exists && ($text->source !== 'importado' || $text->status !== PropertyText::DRAFT)) {
                continue;
            }
            $title = self::scrub(self::clean($l['titulos'][$locale] ?? null));
            $text->fill(['status' => PropertyText::DRAFT, 'source' => 'importado', 'description' => $descriptions[$locale], 'title' => $title ? Str::limit($title, 180, '') : $text->title]);
            if ($text->isDirty()) {
                $text->save();
                $changed = true;
            }
        }

        return $changed;
    }

    /**
     * Photos, plans and videos mirror the source: every photo (largest variant chosen by the parser) in the listing's
     * order — the first is the cover —, new ones downloaded once, ones gone from the source removed, staff uploads kept
     * after them. Videos are links (YouTube/Vimeo), never downloaded.
     *
     * @param  array<string, int>  $counts
     */
    private static function media(ImportedProperty $row, Property $property, array $l, bool $polite, array &$counts): bool
    {
        $max = (int) config('importacion.fotos.max_por_inmueble', 100);
        $changed = false;
        $paths = $row->photos ?? [];
        foreach (['photo' => $l['fotos'] ?? [], 'floorplan' => $l['planos'] ?? [], 'video' => array_filter((array) ($l['videos'] ?? []), fn ($u) => is_string($u) && self::isPlatformVideo($u))] as $kind => $urls) {
            $urls = array_slice(array_values(array_unique(array_filter((array) $urls, fn ($u) => is_string($u) && preg_match('~^https?://~i', $u)))), 0, $max);
            $docs = MediaDocument::query()->where('property_id', $property->getKey())->where('kind', $kind)->orderBy('sort_order')->orderBy('id')->get();
            $imported = $docs->filter(fn (MediaDocument $d) => filled(data_get($d->metadata, 'origen')))->keyBy(fn (MediaDocument $d) => (string) data_get($d->metadata, 'origen'));

            // Gone from the source: removed (file too). Staff uploads (no «origen») are never touched.
            foreach ($imported->reject(fn ($doc, string $origin): bool => in_array($origin, $urls, true)) as $origin => $doc) {   // not except(): URLs have dots
                if ($kind !== 'video') {
                    Storage::disk('public')->delete($doc->path);
                }
                $doc->forceDelete();
                unset($paths[$origin]);
                $counts['fotos_quitadas']++;
                $changed = true;
            }
            $downloads = 0;
            $order = [];
            foreach ($urls as $url) {
                $doc = $imported->get($url);
                if ($doc === null) {
                    $doc = self::download($kind, $url, $row, $property, $polite && $downloads > 0);
                    if ($doc === null) {
                        continue;   // broken or not an image: tried again next run
                    }
                    $downloads++;
                    $paths[$url] = $doc->path;
                    $counts['fotos']++;
                    $changed = true;
                }
                $order[] = $doc;
            }
            // Source order first (1 = cover), then the staff's own uploads in their order.
            $staff = $docs->filter(fn (MediaDocument $d) => blank(data_get($d->metadata, 'origen')))->values()->all();
            foreach ([...$order, ...$staff] as $i => $doc) {
                if ((int) $doc->sort_order !== $i + 1) {
                    $doc->forceFill(['sort_order' => $i + 1])->save();
                    $changed = $changed || in_array($doc, $order, true);
                }
            }
        }
        $row->forceFill(['photos' => $paths])->save();

        return $changed;
    }

    private static function download(string $kind, string $url, ImportedProperty $row, Property $property, bool $pause): ?MediaDocument
    {
        $common = ['team_id' => $property->team_id, 'created_by' => $property->created_by, 'property_id' => $property->getKey(), 'kind' => $kind, 'metadata' => ['origen' => $url]];
        if ($kind === 'video') {
            return MediaDocument::query()->create($common + ['path' => $url, 'title' => __('documentos.video_n', ['n' => 1])]);
        }
        try {
            if ($pause) {
                Sleep::for((float) config('importacion.fotos.pausa', 1))->seconds();
            }
            $response = ImportHttp::get($url, 30);
            $type = Str::lower(Str::before((string) $response->header('Content-Type'), ';'));
            $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'application/pdf' => 'pdf'][$type] ?? null;
            if (! $response->successful() || $ext === null || ($ext === 'pdf' && $kind !== 'floorplan') || strlen($response->body()) > (int) config('importacion.fotos.max_bytes', 15_000_000)) {
                return null;
            }
            $path = 'inmuebles/importados/'.$row->collaborator_id.'/'.sha1($url).'.'.$ext;
            Storage::disk('public')->put($path, $response->body());

            return MediaDocument::query()->create($common + ['path' => $path, 'title' => $kind === 'photo' ? __('Photo :n', ['n' => 1]) : __('Floor plan :n', ['n' => 1])]);
        } catch (Throwable) {
            return null;
        }
    }

    private static function isPlatformVideo(string $url): bool
    {
        return (bool) preg_match('~youtube\.com/|youtu\.be/|vimeo\.com/~i', $url);
    }

    /** @param array<string, int> $counts */
    private static function finish(CollaboratorImport $import, string $status, string $message, array $counts): array
    {
        $import->forceFill(['last_status' => $status, 'last_message' => $message ?: null, 'last_counts' => $counts ?: null, 'last_run_at' => now()])->save();
        Collaborator::log($import, 'importacion_'.$status, ['colaborador' => $import->collaborator_id, 'recuento' => $counts, 'mensaje' => Str::limit($message, 300)]);

        return ['ok' => in_array($status, ['ok', 'parcial'], true), 'status' => $status, 'message' => $message, 'counts' => $counts];
    }

    private static function type(string $raw): string
    {
        $t = Str::lower(Str::ascii($raw));

        return match (true) {
            (bool) preg_match('/chalet|villa|detached-villa/', $t) => 'villa',
            (bool) preg_match('/adosad|pareado|townhouse|town house|semi/', $t) => 'townhouse',
            (bool) preg_match('/piso|apartament|apartment|flat|atico|penthouse|duplex|estudio|studio|loft/', $t) => 'apartment',
            (bool) preg_match('/casa|house|finca|cortijo|bungalow|masia|country/', $t) => 'house',
            (bool) preg_match('/local|oficina|office|commercial|comercial|nave|warehouse|garaje|garage|parking/', $t) => 'commercial',
            (bool) preg_match('/terreno|solar|parcela|land|plot/', $t) => 'land',
            (bool) preg_match('/edificio|building/', $t) => 'hmo',
            default => 'residential',
        };
    }

    private static function operation(string $raw): ?string
    {
        $o = Str::lower(Str::ascii($raw));

        return match (true) {
            $o === '' => null,
            (bool) preg_match('/temporal|vacacion|holiday|short|week|daily|night/', $o) => 'daily',
            (bool) preg_match('/alquil|rent|let|month/', $o) => 'rent',
            default => 'sale',
        };
    }

    /** «250.000 €», «€250,000», «85,5 m²», 3 → float; null when there is no positive number. */
    public static function number(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return $value > 0 ? (float) $value : null;
        }
        if (! is_string($value) || ! preg_match('/\d[\d.,]*/', $value, $m)) {
            return null;
        }
        $n = rtrim($m[0], '.,');
        $n = match (true) {
            (bool) preg_match('/^\d{1,3}(\.\d{3})+(,\d+)?$/', $n) => str_replace(['.', ','], ['', '.'], $n),   // 250.000,50
            (bool) preg_match('/^\d{1,3}(,\d{3})+(\.\d+)?$/', $n) => str_replace(',', '', $n),                 // 250,000.50
            default => str_replace(',', '.', $n),                                                                // 85,5 / 85.5
        };

        return is_numeric($n) && (float) $n > 0 ? (float) $n : null;
    }

    private static function clean(mixed $value, bool $multiline = false): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $text = html_entity_decode(strip_tags((string) preg_replace('~<br\s*/?>|</p>~i', "\n", (string) $value)), ENT_QUOTES | ENT_HTML5);
        $text = $multiline ? trim((string) preg_replace("/[ \t]+/", ' ', (string) preg_replace("/\n{3,}/", "\n\n", str_replace("\r", '', $text)))) : trim((string) preg_replace('/\s+/', ' ', $text));

        return $text === '' ? null : $text;
    }

    /** Owners' or agents' e-mails and phone numbers never reach CLARTO's records. */
    public static function scrub(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }
        $text = (string) preg_replace('/[\w.+-]+@[\w-]+(\.[\w-]+)+/u', '[…]', $text);

        return (string) preg_replace('/(?<![\d.,])(?:\+?34[\s.-]?)?[6789]\d{2}(?:(?:[\s.-]?\d{3}){2}|(?:[\s.-]?\d{2}){3})(?![\d.,]*\s?(?:€|eur|m2|m²))/iu', '[…]', $text);
    }
}
