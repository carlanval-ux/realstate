<?php

declare(strict_types=1);

namespace App\Processes\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Liberu\Foundation\Organizations\Models\Team;

/** A job of the company («Gestor/a de alquileres»), not a Spatie role. */
final class Position extends Model
{
    protected $table = 'clarto_positions';

    protected $guarded = ['id'];

    protected $casts = ['proposal' => 'boolean', 'active' => 'boolean'];

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<Area, $this> */
    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    /** @return HasMany<PositionAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(PositionAssignment::class);
    }

    public function label(): string
    {
        return $this->name.($this->proposal ? ' ('.__('procesos.a_validar').')' : '');
    }

    /** @return array<int, string> id => name of the company's active positions */
    public static function options(int|string $teamId): array
    {
        return self::query()->where('team_id', $teamId)->where('active', true)->orderBy('sort')->orderBy('name')->get()
            ->mapWithKeys(fn (self $p): array => [$p->getKey() => $p->label()])->all();
    }
}
