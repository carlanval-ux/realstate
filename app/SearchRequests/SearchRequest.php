<?php

declare(strict_types=1);

namespace App\SearchRequests;

use App\Collaborators\CollaboratorNotice;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * A property search the team sends to external collaborators («Solicitudes de inmuebles»). Its general status lives
 * here; each collaborator's follow-up in SearchRequestRecipient, every message sent in CollaboratorNotice (contract
 * of «Colaboradores») and the answers in SearchRequestResponse.
 */
final class SearchRequest extends Model
{
    public const STATUSES = ['borrador', 'abierta', 'en_evaluacion', 'cubierta', 'cerrada', 'caducada'];

    /** Closed one way or another (out of the active inboxes, history kept). */
    public const CLOSED = ['cubierta', 'cerrada', 'caducada'];

    /** Collaborators may still answer, and updates may be sent. */
    public const ACTIVE = ['abierta', 'en_evaluacion'];

    public const OPERATIONS = ['compra', 'alquiler', 'alquiler_temporada', 'traspaso', 'inversion'];

    public const PROPERTY_TYPES = ['piso', 'atico', 'casa', 'chalet', 'local', 'oficina', 'nave', 'terreno', 'edificio', 'garaje', 'otro'];

    /** Fields whose change makes «Enviar actualización» meaningful (the full content goes again). */
    public const CONTENT = ['title', 'operation', 'property_type', 'municipality', 'zones', 'address', 'surface_min', 'surface_max', 'budget_min', 'budget_max',
        'bedrooms_min', 'features', 'must_have', 'preferences', 'needed_by', 'respond_by', 'description', 'example_links', 'attachments'];

    public const DISK = 'local';

    protected $table = 'clarto_search_requests';

    protected $attributes = ['status' => 'borrador', 'version' => 1];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['example_links' => 'array', 'attachments' => 'array', 'attachment_names' => 'array', 'needed_by' => 'date', 'respond_by' => 'date',
            'first_sent_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    /** A new request is saved in one transaction with its reference, so a failed insert never leaves a gap. */
    public function save(array $options = []): bool
    {
        return $this->exists ? parent::save($options) : DB::transaction(fn (): bool => parent::save($options));
    }

    protected static function booted(): void
    {
        self::creating(function (self $request): void {
            $request->reference ??= self::nextReference($request->team_id);
            $request->respond_by ??= now(self::timezone($request->team_id))->addDays((int) SearchRequestSettings::forTeam($request->team_id)->default_days)->toDateString();
            $request->created_by ??= auth()->id();
        });
        // Audit trail of the team's edits (status, version and sends are logged by SearchRequests, saved quietly).
        self::created(fn (self $r) => $r->log('creada', array_filter(['referencia' => $r->reference, 'responsable' => $r->owner()->value('name')])));
        self::updated(function (self $r): void {
            $changed = array_keys(array_intersect_key($r->getChanges(), array_flip(self::CONTENT)));
            if ($changed !== []) {
                $r->log('modificada', ['campos' => array_map(fn (string $f): string => __('solicitudes.campo.'.$f), $changed)]);
            }
            if ($r->wasChanged('owner_id')) {
                $r->log('asignada', ['responsable' => $r->owner()->value('name') ?? '—']);
            }
        });
    }

    /**
     * SOL-2026-000123: correlative per company (team) and year. The counter row is incremented in the caller's
     * transaction (row/write lock until commit), so two requests created at once never share a number and a rolled
     * back insert gives its number back. unique (team_id, reference) is the last guard.
     */
    public static function nextReference(int|string $teamId): string
    {
        $year = (int) now(self::timezone($teamId))->year;
        $counter = DB::table('clarto_search_request_counters')->where('team_id', $teamId)->where('year', $year);
        DB::table('clarto_search_request_counters')->insertOrIgnore(['team_id' => $teamId, 'year' => $year, 'last' => 0]);
        (clone $counter)->increment('last');

        return sprintf('SOL-%d-%06d', $year, (int) (clone $counter)->value('last'));
    }

    /** The company's time zone, for deadlines and dates in messages. */
    public static function timezone(int|string|null $teamId = null): string
    {
        return SearchRequestSettings::forTeam($teamId)->tz();
    }

    /** Open (or under evaluation) and its deadline (end of that day, company time) not past: answers accepted. */
    public function acceptsAnswers(): bool
    {
        return $this->isActive() && ! $this->isPastDeadline();
    }

    public function isPastDeadline(): bool
    {
        return $this->respond_by !== null && now(self::timezone($this->team_id))->toDateString() > $this->respond_by->toDateString();
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(SearchRequestRecipient::class);
    }

    public function responses(): HasMany
    {
        return $this->hasMany(SearchRequestResponse::class);
    }

    /** Every message sent about it (request, updates, clarifications): CollaboratorNotices keeps text and result per channel. */
    public function notices(): MorphMany
    {
        return $this->morphMany(CollaboratorNotice::class, 'subject');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SearchRequestEvent::class)->latest('id');
    }

    /** Filament's tenant (company) of the staff panel: lists and records are scoped to it. */
    public function team(): BelongsTo
    {
        return $this->belongsTo(\Liberu\Foundation\Organizations\Models\Team::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function scopeForTeam(Builder $query, int|string|null $teamId): Builder
    {
        return $query->where('team_id', $teamId);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    public static function label(string $group, ?string $value): string
    {
        return $value === null || $value === '' ? '—' : __("solicitudes.{$group}.{$value}");
    }

    /** @return array<string, string> */
    public static function options(string $group, array $values): array
    {
        return collect($values)->mapWithKeys(fn (string $v) => [$v => self::label($group, $v)])->all();
    }

    /** Audit trail: who created, assigned, changed, shared or closed it. */
    public function log(string $action, array $detail = [], ?int $userId = null): void
    {
        $this->events()->create(['action' => $action, 'detail' => $detail ?: null, 'user_id' => $userId ?? auth()->id(), 'created_at' => now()]);
    }
}
