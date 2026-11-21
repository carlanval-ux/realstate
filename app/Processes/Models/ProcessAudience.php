<?php

declare(strict_types=1);

namespace App\Processes\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Who may read a «restringido» process: allow by area, position or person; deny by person (wins over any allow). */
final class ProcessAudience extends Model
{
    public const TYPES = ['area', 'position', 'user'];

    public const EFFECTS = ['allow', 'deny'];

    protected $table = 'clarto_process_audiences';

    protected $guarded = ['id'];

    /** @return BelongsTo<Process, $this> */
    public function process(): BelongsTo
    {
        return $this->belongsTo(Process::class);
    }
}
