<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One entry of a request's history: who, when, status change (from → to) and/or reply. */
final class ImprovementRequestEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'clarto_improvement_request_events';

    protected $guarded = [];

    protected $casts = ['created_at' => 'datetime'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
