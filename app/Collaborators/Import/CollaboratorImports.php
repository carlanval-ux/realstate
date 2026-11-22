<?php

declare(strict_types=1);

namespace App\Collaborators\Import;

use App\Collaborators\Collaborations;
use App\Collaborators\Collaborator;
use App\Collaborators\CollaboratorProperty;
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
use Throwable;

/**
 * Collaborator property import (docs/clarto/importacion-colaboradores.md). run() is the ONLY entry point (scheduled,
 * «Importar ahora», CLI) and refuses to do anything — not even one HTTP request — without the written authorization date.
 *
 * Each listing becomes a property of CLARTO's team keyed by collaborator + external reference: created as «draft»
 * (never in the public catalog until staff publish it), linked to the collaborator («aportado», no notice) and marked
 * with its origin. Later runs only write the fields that changed AT THE SOURCE, so staff edits survive. Listings gone
 * from the source become «retirado» (property withdrawn, never deleted). No owner data is ever read: only listing
 * fields, and e-mails / phone numbers are blanked out of descriptions. Nothing goes to GoHighLevel.
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
        $counts = ['leidos' => 0, 'nuevos' => 0, 'actualizados' => 0, 'sin_cambios' => 0, 'retirados' => 0, 'fotos' => 0, 'errores' => 0, 'paginas' => 0, 'ia' => 0];
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
        $values = self::attributes($listing);
        $row = ImportedProperty::query()->where('collaborator_id', $collaborator->id)->where('external_ref', $ref)->first();
        $property = $row?->property;

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
                'status' => ImportedProperty::ACTIVE, 'data' => $values, 'photos' => [], 'first_seen_at' => now(), 'last_seen_at' => now(), 'withdrawn_at' => null,
            ]);
            CollaboratorProperty::query()->create(['team_id' => $import->team_id, 'collaborator_id' => $collaborator->id, 'property_id' => $property->getKey(),
                'relation' => 'aportado', 'authorised_price' => $property->price, 'availability' => 'disponible', 'assigned_at' => now()]);
            Collaborator::log($property, 'importado', ['colaborador' => $collaborator->id, 'origen' => $import->source, 'referencia' => $ref, 'url' => $listing['url'] ?? null]);
            $counts['fotos'] += self::photos($row, $property, $listing['fotos'] ?? [], $import->source === CollaboratorImport::WEB);

            return 'nuevos';
        }

        if ($property->trashed()) {   // deleted by staff on purpose: leave it deleted
            $row->forceFill(['last_seen_at' => now()])->save();

            return 'sin_cambios';
        }

        $previous = $row->data ?? [];
        $changed = array_filter($values, fn ($v, string $k): bool => ! array_key_exists($k, $previous) || (string) $previous[$k] !== (string) $v, ARRAY_FILTER_USE_BOTH);
        $reappeared = $row->status === ImportedProperty::WITHDRAWN;
        if ($changed !== [] || $reappeared) {
            $diff = [];
            foreach ($changed as $k => $v) {
                $diff[$k] = [$property->getRawOriginal($k), $v];
            }
            $property->forceFill($changed + ($reappeared && $property->status === PropertyStatus::Withdrawn ? ['status' => PropertyStatus::Draft] : []))->save();
            Collaborator::log($property, $reappeared ? 'importacion_reaparecido' : 'importacion_actualizado', ['colaborador' => $collaborator->id, 'cambios' => $diff]);
        }
        $row->forceFill(['data' => $values, 'status' => ImportedProperty::ACTIVE, 'withdrawn_at' => null, 'last_seen_at' => now(), 'source_url' => $listing['url'] ?? $row->source_url])->save();
        $counts['fotos'] += self::photos($row, $property, $listing['fotos'] ?? [], $import->source === CollaboratorImport::WEB);

        return $changed !== [] || $reappeared ? 'actualizados' : 'sin_cambios';
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
     * Listing fields → property columns. Location only down to town/zone (no street address is ever imported).
     *
     * @param  array<string, mixed>  $l
     * @return array<string, mixed>
     */
    public static function attributes(array $l): array
    {
        $price = self::number($l['precio'] ?? null);
        $town = self::clean($l['municipio'] ?? null);
        $zone = self::clean($l['zona'] ?? null);
        $city = $town ? City::query()->whereRaw('lower(name) = ?', [Str::lower($town)])->limit(2)->get() : collect();
        $city = $city->count() === 1 ? $city->first() : null;
        $district = $city && $zone ? District::query()->where('city_id', $city->id)->whereRaw('lower(name) = ?', [Str::lower($zone)])->first() : null;
        $description = self::scrub(self::clean($l['descripcion'] ?? null, multiline: true));
        $type = self::type((string) ($l['tipo'] ?? ''));
        $operation = self::operation((string) ($l['operacion'] ?? ''));
        $title = self::scrub(self::clean($l['titulo'] ?? null)) ?? trim(__('importacion.title', ['type' => __('importacion.types.'.$type), 'town' => $zone ?? $town ?? '']), ' ,');

        return array_filter([
            'title' => Str::limit((string) $title, 180, ''),
            'description' => $description,
            'price' => $price,
            'deal_type' => $operation,
            'property_type' => $type,
            'area_sqft' => self::number($l['m2'] ?? null),
            'bedrooms' => ($n = self::number($l['habitaciones'] ?? null)) !== null ? (int) $n : null,
            'bathrooms' => ($n = self::number($l['banos'] ?? null)) !== null ? (int) $n : null,
            'address' => implode(', ', array_filter([$zone, $town])) ?: __('importacion.no_location'),
            'city_id' => $city?->id,
            'region_id' => $city?->region_id,
            'district_id' => $district?->id,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /** Downloads the photos not downloaded yet (public disk, like the property photo uploader). */
    private static function photos(ImportedProperty $row, Property $property, array $urls, bool $polite): int
    {
        $done = $row->photos ?? [];
        $max = (int) config('importacion.fotos.max_por_inmueble', 20);
        $sort = (int) MediaDocument::query()->where('property_id', $property->getKey())->where('kind', 'photo')->max('sort_order');
        $added = 0;
        foreach (array_slice(array_values(array_unique(array_filter($urls, 'is_string'))), 0, $max) as $url) {
            if (isset($done[$url]) || ! preg_match('~^https?://~i', $url)) {
                continue;
            }
            try {
                if ($polite && $added > 0) {
                    Sleep::for((float) config('importacion.fotos.pausa', 1))->seconds();
                }
                $response = ImportHttp::get($url, 30);
                $type = Str::lower(Str::before((string) $response->header('Content-Type'), ';'));
                $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'][$type] ?? null;
                if (! $response->successful() || $ext === null || strlen($response->body()) > (int) config('importacion.fotos.max_bytes', 8_000_000)) {
                    continue;
                }
                $path = 'inmuebles/importados/'.$row->collaborator_id.'/'.sha1($url).'.'.$ext;
                Storage::disk('public')->put($path, $response->body());
                MediaDocument::query()->create(['team_id' => $property->team_id, 'created_by' => $property->created_by, 'property_id' => $property->getKey(),
                    'kind' => 'photo', 'path' => $path, 'title' => __('Photo :n', ['n' => $sort + 1]), 'sort_order' => ++$sort, 'metadata' => ['origen' => $url]]);
                $done[$url] = $path;
                $added++;
            } catch (Throwable) {
                // a broken photo never stops the listing; it is tried again next run
            }
        }
        $row->forceFill(['photos' => $done])->save();

        return $added;
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
