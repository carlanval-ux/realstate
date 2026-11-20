<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Liberu\RealEstate\Properties\Models\Property;

/** State of a property on an external portal (pisos.com): portal id and last synchronisation. */
final class PortalListing extends Model
{
    /** Sent, but the portal has not confirmed it yet (timeout or no answer). */
    public const SENT = 'enviado';

    /** The portal validated it and returned its id. */
    public const ACCEPTED = 'aceptado';

    /** The portal reports it as active (Estado 0). */
    public const PUBLISHED = 'publicado';

    public const REJECTED = 'rechazado';

    public const WITHDRAWN = 'retirado';

    public const ERROR = 'error';

    protected $table = 'clarto_portal_listings';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['last_response' => 'array', 'last_attempt_at' => 'datetime', 'last_synced_at' => 'datetime'];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public static function for(Property $property, string $portal): self
    {
        return self::query()->firstOrCreate(['property_id' => $property->getKey(), 'portal' => $portal], ['status' => 'no_enviado']);
    }
}
