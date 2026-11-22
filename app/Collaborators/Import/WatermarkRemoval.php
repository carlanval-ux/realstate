<?php

declare(strict_types=1);

namespace App\Collaborators\Import;

use App\Collaborators\Collaborator;
use GdImage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Liberu\RealEstate\MediaAndDocuments\Models\MediaDocument;
use Liberu\RealEstate\Properties\Models\Property;
use RuntimeException;
use Throwable;

/**
 * Removing a collaborator's watermark from its photos (owner's request, 22/11/2026), within the law: a watermark is
 * rights-management information (LPI art. 162, InfoSoc art. 7), so it is removed ONLY for a collaborator whose record
 * says «Autoriza a retirar sus marcas de agua de las fotos» (+ the written authorization date), only on the photos
 * imported from that collaborator, never on CLARTO's own photos or anything else.
 *
 * Engine: IOPaint/LaMa in its own container on the internal network (config importacion.marcas_agua.url), CPU, one
 * image at a time on the low-priority queue «marcas-agua»; no photo leaves the server, no paid API. The mask comes from
 * WatermarkCheck (the mark repeated in the same place). The model's output is pasted back ONLY inside the mask: every
 * pixel outside it is the original's. The original is kept on the private disk; the cleaned photo becomes the public
 * one only when staff approve it in the property's photo review (side by side). Every step is logged.
 */
final class WatermarkRemoval
{
    public const QUEUE = 'marcas-agua';

    /** Why this property's photos may not be cleaned, or null. */
    public static function refusal(Property $property): ?string
    {
        if (blank(config('importacion.marcas_agua.url'))) {
            return __('importacion.cleanup.refusals.service');
        }
        $row = ImportedProperty::query()->where('property_id', $property->getKey())->where('team_id', $property->team_id)->first();
        if ($row === null) {
            return __('importacion.cleanup.refusals.not_imported');   // CLARTO's own or added by hand: never
        }
        $settings = CollaboratorImport::query()->where('collaborator_id', $row->collaborator_id)->first();

        return match (true) {
            ! ($settings?->watermark_removal_allowed ?? false) => __('importacion.cleanup.refusals.not_allowed'),
            ! ($settings?->isAuthorized() ?? false) => __('importacion.cleanup.refusals.authorization'),
            default => null,
        };
    }

    /**
     * Queues the imported photos of a property whose watermark was detected. Returns how many were queued.
     *
     * @throws RuntimeException when not allowed
     */
    public static function request(Property $property): int
    {
        if (($refusal = self::refusal($property)) !== null) {
            throw new RuntimeException($refusal);
        }
        $row = ImportedProperty::query()->where('property_id', $property->getKey())->firstOrFail();
        $docs = self::importedPhotos($property);
        $mask = WatermarkCheck::mask($docs->pluck('path')->all());
        if ($mask === null) {
            throw new RuntimeException(__('importacion.cleanup.no_mask'));
        }
        $queued = 0;
        foreach ($docs as $doc) {
            if (PhotoCleanup::query()->where('media_document_id', $doc->getKey())->whereIn('status', PhotoCleanup::OPEN)->exists()) {
                continue;
            }
            $cleanup = PhotoCleanup::query()->create(['team_id' => $property->team_id, 'property_id' => $property->getKey(), 'media_document_id' => $doc->getKey(),
                'collaborator_id' => $row->collaborator_id, 'status' => PhotoCleanup::PENDING, 'mask' => $mask, 'requested_by' => auth()->id()]);
            CleanWatermarkJob::dispatch($cleanup->id)->onQueue(self::QUEUE);
            $queued++;
        }
        Collaborator::log($property, 'marca_agua_solicitada', ['colaborador' => $row->collaborator_id, 'fotos' => $queued, 'mascara' => $mask]);

        return $queued;
    }

    /** The queued work: original to the private disk, inpainting, paste-back inside the mask only. */
    public static function process(PhotoCleanup $cleanup): void
    {
        $property = $cleanup->property;
        $doc = $cleanup->document;
        if ($property === null || $doc === null || ! in_array($cleanup->status, [PhotoCleanup::PENDING, PhotoCleanup::ERROR], true)) {
            return;
        }
        if (($refusal = self::refusal($property)) !== null || blank(data_get($doc->metadata, 'origen'))) {
            $cleanup->forceFill(['status' => PhotoCleanup::ERROR, 'error' => $refusal ?? __('importacion.cleanup.refusals.not_imported')])->save();

            return;
        }
        $cleanup->forceFill(['status' => PhotoCleanup::PROCESSING, 'error' => null])->save();
        try {
            $private = Storage::disk('local');
            $dir = 'marcas-agua/'.$cleanup->team_id.'/'.$cleanup->property_id;
            if (blank($cleanup->original_path)) {
                $bytes = (string) Storage::disk('public')->get($doc->path);
                throw_if($bytes === '', new RuntimeException(__('importacion.cleanup.errors.no_photo')));
                $original = $dir.'/'.$doc->getKey().'-original.'.(pathinfo((string) $doc->path, PATHINFO_EXTENSION) ?: 'jpg');
                $private->put($original, $bytes);
                $cleanup->forceFill(['original_path' => $original])->save();
            }
            $bytes = (string) $private->get((string) $cleanup->original_path);
            $image = @imagecreatefromstring($bytes) ?: throw new RuntimeException(__('importacion.cleanup.errors.no_photo'));
            [$width, $height] = [imagesx($image), imagesy($image)];
            $boxes = self::pixelBoxes($cleanup->mask ?? [], $width, $height);

            $response = Http::timeout((int) config('importacion.marcas_agua.timeout', 75))->acceptJson()
                ->post(rtrim((string) config('importacion.marcas_agua.url'), '/').'/api/v1/inpaint', [
                    'image' => base64_encode($bytes),
                    'mask' => base64_encode(self::png(self::maskImage($boxes, $width, $height))),
                    'hd_strategy' => 'Crop', 'hd_strategy_crop_margin' => 128, 'hd_strategy_crop_trigger_size' => 800,
                ])->throw();
            $result = @imagecreatefromstring($response->body()) ?: throw new RuntimeException(__('importacion.cleanup.errors.bad_answer'));
            throw_if(imagesx($result) !== $width || imagesy($result) !== $height, new RuntimeException(__('importacion.cleanup.errors.bad_answer')));

            // Only the masked boxes come from the model; everything else stays the original's, pixel for pixel.
            foreach ($boxes as [$x0, $y0, $x1, $y1]) {
                imagecopy($image, $result, $x0, $y0, $x0, $y0, $x1 - $x0, $y1 - $y0);
            }
            $cleaned = $dir.'/'.$doc->getKey().'-limpia.png';   // lossless: the untouched area keeps the original pixels
            $private->put($cleaned, self::png($image));
            $cleanup->forceFill(['status' => PhotoCleanup::READY, 'cleaned_path' => $cleaned])->save();
            Collaborator::log($property, 'marca_agua_procesada', ['foto' => $doc->getKey(), 'limpieza' => $cleanup->id]);
        } catch (Throwable $e) {
            $cleanup->forceFill(['status' => PhotoCleanup::ERROR, 'error' => Str::limit($e->getMessage(), 500)])->save();
            Collaborator::log($property, 'marca_agua_error', ['foto' => $doc->getKey(), 'limpieza' => $cleanup->id, 'error' => Str::limit($e->getMessage(), 200)]);
        }
    }

    /** Staff approved: the cleaned photo becomes the public one (same place in the gallery); the original stays private. */
    public static function approve(PhotoCleanup $cleanup): void
    {
        $doc = $cleanup->document;
        throw_unless($cleanup->status === PhotoCleanup::READY && $doc !== null && $cleanup->property !== null, new RuntimeException(__('importacion.cleanup.errors.not_ready')));
        throw_if(($refusal = self::refusal($cleanup->property)) !== null && $refusal !== __('importacion.cleanup.refusals.service'), new RuntimeException((string) $refusal));
        $public = Storage::disk('public');
        $path = 'inmuebles/importados/'.$cleanup->collaborator_id.'/'.$doc->getKey().'-'.now()->format('YmdHis').'-limpia.png';
        $public->put($path, (string) Storage::disk('local')->get((string) $cleanup->cleaned_path));
        $old = (string) $doc->path;
        $doc->forceFill(['path' => $path, 'metadata' => array_merge((array) $doc->metadata, ['marca_agua_retirada' => true, 'original_privado' => $cleanup->original_path])])->save();
        if ($old !== $path && str_starts_with($old, 'inmuebles/importados/')) {
            $public->delete($old);   // the watermarked copy leaves the public disk; the original is on the private one
        }
        $cleanup->forceFill(['status' => PhotoCleanup::APPROVED, 'decided_by' => auth()->id(), 'decided_at' => now()])->save();
        Collaborator::log($cleanup->property, 'marca_agua_aprobada', ['foto' => $doc->getKey(), 'limpieza' => $cleanup->id]);
    }

    public static function discard(PhotoCleanup $cleanup): void
    {
        if (filled($cleanup->cleaned_path)) {
            Storage::disk('local')->delete((string) $cleanup->cleaned_path);
        }
        $cleanup->forceFill(['status' => PhotoCleanup::DISCARDED, 'cleaned_path' => null, 'decided_by' => auth()->id(), 'decided_at' => now()])->save();
        Collaborator::log($cleanup->property ?? $cleanup, 'marca_agua_descartada', ['foto' => $cleanup->media_document_id, 'limpieza' => $cleanup->id]);
    }

    /** @return \Illuminate\Support\Collection<int, MediaDocument> the photos imported from the collaborator (never staff uploads) */
    public static function importedPhotos(Property $property)
    {
        return MediaDocument::query()->where('property_id', $property->getKey())->where('kind', 'photo')->orderBy('sort_order')->get()
            ->filter(fn (MediaDocument $d): bool => filled(data_get($d->metadata, 'origen')))->values();
    }

    /**
     * @param  list<array{0: float, 1: float, 2: float, 3: float}>  $mask
     * @return list<array{0: int, 1: int, 2: int, 3: int}>
     */
    public static function pixelBoxes(array $mask, int $width, int $height): array
    {
        return array_values(array_filter(array_map(fn (array $b): array => [
            max(0, (int) floor($b[0] * $width)), max(0, (int) floor($b[1] * $height)),
            min($width, (int) ceil($b[2] * $width)), min($height, (int) ceil($b[3] * $height)),
        ], $mask), fn (array $b): bool => $b[2] > $b[0] && $b[3] > $b[1]));
    }

    /** @param list<array{0: int, 1: int, 2: int, 3: int}> $boxes white = inpaint (IOPaint convention) */
    private static function maskImage(array $boxes, int $width, int $height): GdImage
    {
        $mask = imagecreatetruecolor($width, $height);
        $white = imagecolorallocate($mask, 255, 255, 255);
        foreach ($boxes as [$x0, $y0, $x1, $y1]) {
            imagefilledrectangle($mask, $x0, $y0, $x1 - 1, $y1 - 1, $white);
        }

        return $mask;
    }

    private static function png(GdImage $image): string
    {
        ob_start();
        imagepng($image, null, 6);

        return (string) ob_get_clean();
    }
}
