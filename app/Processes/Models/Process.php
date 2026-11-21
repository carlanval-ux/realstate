<?php

declare(strict_types=1);

namespace App\Processes\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Liberu\Foundation\Organizations\Models\Team;

/**
 * A documented procedure of a company. Not versioned: code, area, owner, audience… The content lives in
 * ProcessVersion (immutable once published). Status: borrador (never published) | publicado | archivado.
 */
final class Process extends Model
{
    public const STATUSES = ['borrador', 'publicado', 'archivado'];

    public const CATEGORIES = ['comercial', 'operativo', 'administrativo', 'financiero', 'soporte'];

    public const BUSINESS_LINES = Area::BUSINESS_LINES;

    public const KINDS = ['breve', 'completo'];

    public const VISIBILITIES = ['empresa', 'restringido'];

    protected $table = 'clarto_processes';

    protected $guarded = ['id'];

    protected $casts = [
        'ai_excluded' => 'boolean', 'next_review_on' => 'date', 'last_reviewed_at' => 'datetime', 'archived_at' => 'datetime',
    ];

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

    /** @return HasMany<ProcessVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(ProcessVersion::class)->orderByDesc('id');
    }

    /** @return BelongsTo<ProcessVersion, $this> */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(ProcessVersion::class, 'current_version_id');
    }

    /** The open version (borrador or en_revision), at most one. @return HasOne<ProcessVersion, $this> */
    public function openVersion(): HasOne
    {
        return $this->hasOne(ProcessVersion::class)->whereIn('status', ProcessVersion::OPEN)->latestOfMany();
    }

    /** @return BelongsToMany<Position, $this> */
    public function positions(): BelongsToMany
    {
        return $this->belongsToMany(Position::class, 'clarto_process_positions')->withTimestamps();
    }

    /** @return HasMany<ProcessAudience, $this> */
    public function audiences(): HasMany
    {
        return $this->hasMany(ProcessAudience::class);
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }

    /** @return HasMany<ProcessEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(ProcessEvent::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    /** @return BelongsTo<ProcessMapEntry, $this> */
    public function mapEntry(): BelongsTo
    {
        return $this->belongsTo(ProcessMapEntry::class, 'map_entry_id');
    }

    /** Owner missing, disabled or no longer in the company («Responsables sin asignar»). */
    public function ownerMissing(): bool
    {
        $owner = $this->owner;

        return $owner === null || filled($owner->disabled_at) || ! \App\Processes\ProcessAccess::isMember($owner, $this->team_id);
    }
}
