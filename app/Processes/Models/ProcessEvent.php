<?php

declare(strict_types=1);

namespace App\Processes\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Append-only history of a process (who, when, which transition, note, metadata before/after). */
final class ProcessEvent extends Model
{
    public const TYPES = ['creado', 'borrador_creado', 'borrador_eliminado', 'enviado_revision', 'retirado', 'devuelto', 'revisado',
        'publicado', 'revision_confirmada', 'archivado', 'restaurado', 'responsable_cambiado', 'metadatos_cambiados', 'audiencia_cambiada'];

    public const UPDATED_AT = null;

    protected $table = 'clarto_process_events';

    protected $guarded = ['id'];

    protected $casts = ['data' => 'array'];

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Process history is append-only.'));
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<ProcessVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(ProcessVersion::class, 'process_version_id');
    }
}
