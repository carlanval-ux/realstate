<?php

declare(strict_types=1);

namespace App\Collaborators;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\RealEstate\Properties\Models\Property;

/**
 * Asignación de un inmueble a un colaborador, con su relación. Activa mientras revoked_at sea null: retirarla
 * (CollaboratorAccess::revoke) corta el acceso y cancela los avisos pendientes de ese inmueble.
 * authorised_price / availability / commercial_status = lo que CLARTO le ha comunicado (lo que él ve).
 */
final class CollaboratorProperty extends Model
{
    public const RELATIONS = ['aportado', 'comercializacion', 'busqueda'];

    public const AVAILABILITY = ['disponible', 'reservado', 'no_disponible'];

    public const COMMERCIAL = ['en_comercializacion', 'con_visitas', 'con_oferta', 'reservado', 'cerrado'];

    protected $table = 'clarto_collaborator_properties';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['authorised_price' => 'decimal:2', 'assigned_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function collaborator(): BelongsTo
    {
        return $this->belongsTo(Collaborator::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }
}
