<?php

declare(strict_types=1);

namespace App\Rentals\Models;

use Illuminate\Database\Eloquent\Model;

/** Append-only history line of an occupancy. */
final class RentalEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'clarto_rent_events';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['occurred_on' => 'date', 'data' => 'array'];
    }

    public function occupancy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Occupancy::class);
    }
}
