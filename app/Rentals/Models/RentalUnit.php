<?php

declare(strict_types=1);

namespace App\Rentals\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Liberu\RealEstate\Properties\Models\Property;

/** Rentable unit: the whole dwelling (no parent) or one of its rooms (parent = the dwelling). */
final class RentalUnit extends Model
{
    use SoftDeletes;

    public const DWELLING = 'vivienda';

    public const ROOM = 'habitacion';

    protected $table = 'clarto_rent_units';

    protected $guarded = ['id', 'lock_version'];

    protected $attributes = ['active' => true, 'max_occupants' => 1];

    public function team(): BelongsTo
    {
        return $this->belongsTo(\Liberu\Foundation\Organizations\Models\Team::class);
    }

    protected function casts(): array
    {
        return ['common_areas' => 'array', 'active' => 'boolean', 'area_m2' => 'decimal:2', 'reference_rent' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        self::saving(function (self $unit): void {
            if ($unit->kind === self::ROOM) {
                $parent = $unit->parent_id ? self::query()->find($unit->parent_id) : null;
                if (! $parent || $parent->kind !== self::DWELLING || (int) $parent->property_id !== (int) $unit->property_id) {
                    throw new \InvalidArgumentException(__('clarto.alquileres.errors.room_needs_dwelling'));
                }
            } else {
                $unit->kind = self::DWELLING;
                $unit->parent_id = null;
            }
        });
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function seasons(): HasMany
    {
        return $this->hasMany(RentalSeason::class, 'unit_id');
    }

    /**
     * Units whose confirmed occupancy collides with this one: itself, its rooms when it is the dwelling, and its
     * dwelling when it is a room. Two different rooms never collide.
     *
     * @return list<int>
     */
    public function conflictingUnitIds(): array
    {
        $ids = [(int) $this->getKey()];
        if ($this->kind === self::DWELLING) {
            return [...$ids, ...self::withTrashed()->where('parent_id', $this->getKey())->pluck('id')->map(fn ($id): int => (int) $id)->all()];
        }

        return [...$ids, (int) $this->parent_id];
    }

    public function label(): string
    {
        return $this->kind === self::ROOM ? ($this->parent?->name.' · '.$this->name) : $this->name;
    }
}
