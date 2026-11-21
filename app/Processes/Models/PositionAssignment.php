<?php

declare(strict_types=1);

namespace App\Processes\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Liberu\Foundation\Organizations\Models\Team;

/** A person in a position (optional office and dates). Active while starts_on <= today <= ends_on (nulls are open). */
final class PositionAssignment extends Model
{
    protected $table = 'clarto_position_user';

    protected $guarded = ['id'];

    protected $casts = ['is_primary' => 'boolean', 'starts_on' => 'date', 'ends_on' => 'date'];

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<Position, $this> */
    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        $today = now()->toDateString();

        return $query->where(fn (Builder $q) => $q->whereNull('clarto_position_user.starts_on')->orWhere('clarto_position_user.starts_on', '<=', $today))
            ->where(fn (Builder $q) => $q->whereNull('clarto_position_user.ends_on')->orWhere('clarto_position_user.ends_on', '>=', $today));
    }
}
