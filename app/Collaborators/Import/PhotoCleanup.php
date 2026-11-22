<?php

declare(strict_types=1);

namespace App\Collaborators\Import;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Liberu\RealEstate\MediaAndDocuments\Models\MediaDocument;
use Liberu\RealEstate\Properties\Models\Property;

/**
 * One collaborator photo on its way to losing the collaborator's watermark (WatermarkRemoval). The original always
 * stays on the private disk; the cleaned version replaces the public photo only when staff approve it.
 *
 * @property int $id
 * @property int $team_id
 * @property int $property_id
 * @property int $media_document_id
 * @property int $collaborator_id
 * @property string $status
 * @property ?list<array{0: float, 1: float, 2: float, 3: float}> $mask
 * @property ?string $original_path
 * @property ?string $cleaned_path
 * @property ?string $error
 */
final class PhotoCleanup extends Model
{
    public const PENDING = 'pendiente';

    public const PROCESSING = 'procesando';

    public const READY = 'listo';

    public const APPROVED = 'aprobado';

    public const DISCARDED = 'descartado';

    public const ERROR = 'error';

    /** Rows that block a new request for the same photo. */
    public const OPEN = [self::PENDING, self::PROCESSING, self::READY, self::APPROVED];

    protected $table = 'clarto_photo_cleanups';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['mask' => 'array', 'decided_at' => 'datetime'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(MediaDocument::class, 'media_document_id');
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
