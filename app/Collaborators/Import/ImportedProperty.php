<?php

declare(strict_types=1);

namespace App\Collaborators\Import;

use App\Collaborators\Collaborator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Liberu\RealEstate\Properties\Models\Property;

/**
 * A property that came from a collaborator's feed or website: the link (collaborator + external reference → property),
 * its origin, the last values read from the source (only fields that change there are written again, so staff edits
 * of the rest survive) and the photos already downloaded. «retirado» when the listing disappeared from the source.
 *
 * @property int $id
 * @property int $team_id
 * @property int $collaborator_id
 * @property int $property_id
 * @property string $source feed|web
 * @property string $external_ref
 * @property ?string $source_url
 * @property string $status activo|retirado
 * @property ?array<string, mixed> $data
 * @property ?array<string, mixed> $extra «otros datos» (label => value)
 * @property ?array<string, string> $photos
 */
final class ImportedProperty extends Model
{
    public const ACTIVE = 'activo';

    public const WITHDRAWN = 'retirado';

    protected $table = 'clarto_imported_properties';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['data' => 'array', 'extra' => 'array', 'photos' => 'array', 'first_seen_at' => 'datetime', 'last_seen_at' => 'datetime', 'withdrawn_at' => 'datetime'];
    }

    public function collaborator(): BelongsTo
    {
        return $this->belongsTo(Collaborator::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class)->withTrashed();
    }
}
