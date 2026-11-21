<?php

declare(strict_types=1);

namespace App\Rentals\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Liberu\RealEstate\Properties\Models\Property;

/**
 * Contract (tradicional, habitaciones), booking (vacacional) or calendar block of one rentable unit.
 * Period is [start, end): the exit day is free. Status and dates change only through App\Rentals\Occupancies,
 * which holds the per-property lock and writes the history.
 */
final class Occupancy extends Model
{
    use SoftDeletes;

    public const MODULES = ['tradicional', 'habitaciones', 'vacacional'];

    public const PURPOSES = ['habitual', 'temporal', 'turistica'];

    public const KINDS = ['contrato', 'reserva', 'bloqueo'];

    public const STATUSES = ['borrador', 'confirmada', 'en_curso', 'finalizada', 'cancelada'];

    /** Statuses that occupy the calendar. A finished one occupies up to its actual exit. */
    public const BLOCKING = ['confirmada', 'en_curso', 'finalizada'];

    protected $table = 'clarto_rent_occupancies';

    protected $guarded = ['id'];

    protected $attributes = ['kind' => 'contrato', 'status' => 'borrador', 'occupants' => 1, 'renewals' => 0, 'landlord_is_company' => false];

    public function team(): BelongsTo
    {
        return $this->belongsTo(\Liberu\Foundation\Organizations\Models\Team::class);
    }

    protected function casts(): array
    {
        return [
            'planned_start' => 'immutable_date', 'planned_end' => 'immutable_date',
            'actual_start' => 'immutable_date', 'actual_end' => 'immutable_date',
            'rent_amount' => 'decimal:2', 'utilities' => 'array', 'landlord_is_company' => 'boolean',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(RentalUnit::class, 'unit_id');
    }

    public function previous(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_occupancy_id');
    }

    public function people(): HasMany
    {
        return $this->hasMany(OccupancyPerson::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(RentalEvent::class)->orderBy('id');
    }

    public function charges(): HasMany
    {
        return $this->hasMany(RentalCharge::class);
    }

    public function guarantees(): HasMany
    {
        return $this->hasMany(RentalGuarantee::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(RentalDocument::class);
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(RentalIncident::class);
    }

    public function start(): CarbonImmutable
    {
        return $this->actual_start ?? $this->planned_start;
    }

    /** Exit day (exclusive): the actual one once known, else the planned one. */
    public function end(): CarbonImmutable
    {
        return $this->actual_end ?? $this->planned_end;
    }

    public function nights(): int
    {
        return (int) $this->planned_start->diffInDays($this->planned_end);
    }
}
