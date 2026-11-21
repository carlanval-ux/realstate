<?php

declare(strict_types=1);

namespace App\Processes\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Entry of the process map (database/data/procesos-mapa.json, loaded per company by clarto:procesos-demo). */
final class ProcessMapEntry extends Model
{
    public const STATUSES = ['por_documentar', 'a_validar', 'descartado'];

    protected $table = 'clarto_process_map_entries';

    protected $guarded = ['id'];

    protected $casts = ['executing_position_keys' => 'array', 'liberu_refs' => 'array', 'tarmio_kinds' => 'array', 'priority' => 'integer'];

    /** @return BelongsTo<Process, $this> */
    public function process(): BelongsTo
    {
        return $this->belongsTo(Process::class);
    }
}
