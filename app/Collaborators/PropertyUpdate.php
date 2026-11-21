<?php

declare(strict_types=1);

namespace App\Collaborators;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\RealEstate\Properties\Models\Property;

/**
 * Seguimiento de un inmueble. Una nota interna (visibility=interna) nunca sale del equipo. Una novedad compartida
 * se envía a sus colaboradores por correo y WhatsApp (CollaboratorNotice) y queda en su panel. Oferta, reserva y
 * cierre esperan a que el equipo revise las condiciones (status=pendiente_revision) antes de enviarse.
 */
final class PropertyUpdate extends Model
{
    public const INTERNAL = 'interna';

    public const SHARED = 'compartida';

    /** Tipos compartibles (orden del formulario). */
    public const SHARED_KINDS = ['alta', 'precio', 'disponibilidad', 'visita', 'resultado_visita', 'oferta', 'reserva', 'cierre', 'otro'];

    /** Su contenido lo revisa el equipo antes del envío. */
    public const REVIEWED_KINDS = ['oferta', 'reserva', 'cierre'];

    public const VISIT_STATUSES = ['programada', 'realizada', 'cancelada', 'aplazada'];

    public const NOTE = 'registrada';

    public const PENDING_REVIEW = 'pendiente_revision';

    public const SENT = 'compartida';

    public const DISCARDED = 'descartada';

    protected $table = 'clarto_property_updates';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'visit_at' => 'datetime',
            'attachments' => 'array',
            'recipients' => 'array',
            'reviewed_at' => 'datetime',
            'shared_at' => 'datetime',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function notices(): MorphMany
    {
        return $this->morphMany(CollaboratorNotice::class, 'subject');
    }

    public function scopeShared(Builder $query): Builder
    {
        return $query->where('visibility', self::SHARED)->where('status', self::SENT);
    }

    public function isShared(): bool
    {
        return $this->visibility === self::SHARED && $this->status === self::SENT;
    }

    public function needsReview(): bool
    {
        return in_array($this->kind, self::REVIEWED_KINDS, true);
    }
}
