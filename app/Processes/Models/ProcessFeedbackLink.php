<?php

declare(strict_types=1);

namespace App\Processes\Models;

use App\Models\ImprovementRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Bridge between an improvement request («Comunicar una duda o mejora») and the process, version and step. */
final class ProcessFeedbackLink extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'clarto_process_feedback';

    protected $guarded = ['id'];

    /** @return BelongsTo<ImprovementRequest, $this> */
    public function improvementRequest(): BelongsTo
    {
        return $this->belongsTo(ImprovementRequest::class);
    }

    /** @return BelongsTo<Process, $this> */
    public function process(): BelongsTo
    {
        return $this->belongsTo(Process::class);
    }

    /** @return BelongsTo<ProcessVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(ProcessVersion::class, 'process_version_id');
    }
}
