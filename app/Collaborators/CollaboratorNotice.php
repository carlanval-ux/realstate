<?php

declare(strict_types=1);

namespace App\Collaborators;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Un aviso a un colaborador (correo + WhatsApp) sobre un asunto (novedad de inmueble, solicitud…). Lo crea y envía
 * CollaboratorNotices: una fila por dedupe_key, nunca dos envíos por canal, acceso comprobado justo antes de enviar.
 */
final class CollaboratorNotice extends Model
{
    public const PENDING = 'pendiente';

    public const SENT = 'enviado';

    public const PARTIAL = 'parcial';

    public const ERROR = 'error';

    public const CANCELLED = 'cancelado';

    /** Aún por enviar (o reintentar). */
    public const OPEN = [self::PENDING, self::PARTIAL, self::ERROR];

    protected $table = 'clarto_collaborator_notices';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['whatsapp_blocks' => 'array', 'sent_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function collaborator(): BelongsTo
    {
        return $this->belongsTo(Collaborator::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }
}
