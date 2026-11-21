<?php

declare(strict_types=1);

namespace App\SearchRequests;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Audit trail of a request: created, assigned, changed, sent, updated, answered, linked, closed… */
final class SearchRequestEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'clarto_search_request_events';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['detail' => 'array', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function summary(): string
    {
        $detail = collect((array) $this->detail)->map(fn ($v, $k) => is_array($v) ? $k.': '.implode(', ', array_map('strval', $v)) : $k.': '.$v)->implode(' · ');

        return __('solicitudes.evento.'.$this->action).($detail !== '' ? ' — '.$detail : '');
    }
}
