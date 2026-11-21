<?php

declare(strict_types=1);

namespace App\Rentals\Models;

use App\Rentals\RentalAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A person in one occupancy with one role. Identity (document, birth date, nationality, address…) and solvency
 * (income, employer…) are encrypted at rest; read them through identityFor()/solvencyFor(), which check permissions.
 */
final class OccupancyPerson extends Model
{
    use SoftDeletes;

    /** Reservation holder, signatories, tenants, occupants, guarantors, owner and travellers are kept apart. */
    public const ROLES = ['titular', 'firmante', 'arrendatario', 'ocupante', 'avalista', 'propietario', 'viajero'];

    protected $table = 'clarto_rent_people';

    protected $guarded = ['id'];

    protected $hidden = ['identity', 'solvency'];

    protected function casts(): array
    {
        return ['identity' => 'encrypted:array', 'solvency' => 'encrypted:array'];
    }

    protected static function booted(): void
    {
        self::saving(function (self $person): void {
            if (! in_array($person->role, self::ROLES, true)) {
                throw new \InvalidArgumentException("Unknown role [{$person->role}].");
            }
        });
    }

    public function occupancy(): BelongsTo
    {
        return $this->belongsTo(Occupancy::class);
    }

    /** @return array<string, mixed>|null null when the user may not see it */
    public function identityFor(?object $user): ?array
    {
        $permission = $this->role === 'viajero' ? RentalAccess::TRAVELLERS : RentalAccess::IDENTITY;

        return RentalAccess::allows($user, $permission) ? ($this->identity ?? []) : null;
    }

    /** @return array<string, mixed>|null */
    public function solvencyFor(?object $user): ?array
    {
        return RentalAccess::allows($user, RentalAccess::SOLVENCY) ? ($this->solvency ?? []) : null;
    }
}
