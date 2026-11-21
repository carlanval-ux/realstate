<?php

declare(strict_types=1);

namespace App\Processes\Assistant;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One question to «Preguntar a CLARTO», stored already masked. «Sin respuesta útil» = outcome sin_documentacion or
 * rating -1. Kept log_retention_days (company setting) and then deleted by the daily prune.
 *
 * @property list<array{process_version_id: int, anchor: string}>|null $cited
 * @property list<array{process_version_id: int, score: float}>|null $candidates
 */
final class AssistantQuery extends Model
{
    public const OUTCOMES = ['respondida', 'aclaracion', 'sin_documentacion', 'desactivado', 'error'];

    protected $table = 'clarto_assistant_queries';

    protected $guarded = ['id'];

    protected $casts = ['cited' => 'array', 'candidates' => 'array', 'rated_at' => 'datetime', 'rating' => 'integer'];

    public function scopeUnanswered(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->where('outcome', 'sin_documentacion')->orWhere('rating', -1));
    }

    /** Daily: deletes what is older than each company's retention. @return int deleted rows */
    public static function prune(): int
    {
        $deleted = 0;
        foreach (self::query()->distinct()->pluck('team_id') as $teamId) {
            $days = max(1, AssistantSettings::forTeam($teamId)->log_retention_days);
            $deleted += self::query()->where('team_id', $teamId)->where('created_at', '<', now()->subDays($days))->delete();
        }

        return $deleted;
    }
}
