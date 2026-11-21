<?php

declare(strict_types=1);

namespace App\Processes\Models;

use Illuminate\Database\Eloquent\Model;

/** Per-company settings; without a row the defaults apply (12 months, four-eyes on, TARMIO review tasks on). */
final class ProcessSettings extends Model
{
    protected $table = 'clarto_process_settings';

    protected $guarded = ['id'];

    protected $attributes = ['default_review_months' => 12, 'require_four_eyes' => true, 'review_tasks_in_tarmio' => true];

    protected $casts = ['default_review_months' => 'integer', 'require_four_eyes' => 'boolean', 'review_tasks_in_tarmio' => 'boolean'];

    public static function forTeam(int|string $teamId): self
    {
        return self::query()->firstOrNew(['team_id' => $teamId]);
    }
}
