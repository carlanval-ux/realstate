<?php

declare(strict_types=1);

namespace App\Processes\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Liberu\Foundation\Organizations\Models\Team;

/** Organisational unit of a company (DIR, ALQ…): its code prefixes the process codes. */
final class Area extends Model
{
    public const BUSINESS_LINES = ['inmobiliaria', 'alquileres', 'consultoria', 'gestoria', 'transversal'];

    protected $table = 'clarto_areas';

    protected $guarded = ['id'];

    protected $casts = ['proposal' => 'boolean', 'active' => 'boolean'];

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return HasMany<Position, $this> */
    public function positions(): HasMany
    {
        return $this->hasMany(Position::class)->orderBy('sort')->orderBy('name');
    }

    /** @return BelongsTo<User, $this> */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_user_id');
    }

    public function label(): string
    {
        return $this->code.' · '.$this->name.($this->proposal ? ' ('.__('procesos.a_validar').')' : '');
    }
}
