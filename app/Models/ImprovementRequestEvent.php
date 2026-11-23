<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry of a thread's history: who, when, status change (from → to) and/or reply with photos.
 *
 * @property array<int, array{path: string, name: string, mime: string, size: int}>|null $attachments
 */
final class ImprovementRequestEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'clarto_improvement_request_events';

    protected $guarded = [];

    protected $casts = ['created_at' => 'datetime', 'attachments' => 'array'];

    /** @return BelongsTo<ImprovementRequest, $this> */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(ImprovementRequest::class, 'improvement_request_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
