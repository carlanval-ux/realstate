<?php

declare(strict_types=1);

namespace App\Rentals\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
/** Breakdown, maintenance or cleaning job, with photos, authorised access, provider, quote, who pays and verified closure. */
final class RentalIncident extends Model
{
    use SoftDeletes;

    protected $table = 'clarto_rent_incidents';

    protected $guarded = ['id'];

    public function team(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\Liberu\Foundation\Organizations\Models\Team::class);
    }

    protected function casts(): array
    {
        return ['photos' => 'array', 'access_authorized' => 'boolean', 'quote_amount' => 'decimal:2', 'final_amount' => 'decimal:2', 'reported_on' => 'date', 'verified_at' => 'datetime'];
    }

    public const STATUSES = ['abierta', 'presupuestada', 'en_curso', 'resuelta', 'cerrada'];

    public function occupancy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Occupancy::class);
    }
}
