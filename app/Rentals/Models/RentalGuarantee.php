<?php

declare(strict_types=1);

namespace App\Rentals\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
/** Fianza legal, garantía adicional, aval or impago insurance (never income). */
final class RentalGuarantee extends Model
{
    use SoftDeletes;

    protected $table = 'clarto_rent_guarantees';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'returned_amount' => 'decimal:2', 'received_on' => 'date', 'deposited_on' => 'date', 'returned_on' => 'date', 'deductions' => 'array'];
    }

    public const TYPES = ['fianza', 'garantia_adicional', 'aval', 'seguro_impago'];

    public function occupancy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Occupancy::class);
    }
}
