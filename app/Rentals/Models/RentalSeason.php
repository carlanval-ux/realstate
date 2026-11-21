<?php

declare(strict_types=1);

namespace App\Rentals\Models;

use Illuminate\Database\Eloquent\Model;

/** Holiday rental nightly rate for a season (both dates inclusive). */
final class RentalSeason extends Model
{

    protected $table = 'clarto_rent_seasons';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'nightly_rate' => 'decimal:2'];
    }

    public function unit(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(RentalUnit::class, 'unit_id');
    }
}
