<?php

declare(strict_types=1);

namespace App\Collaborators;

use App\Models\User;
use App\WhatsApp\Phone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Liberu\Foundation\Organizations\Models\Team;

/**
 * Colaborador externo de CLARTO (agente o empresa). Solo ve lo que CollaboratorAccess le concede: los inmuebles
 * asignados explícitamente y lo que otros módulos registren (solicitudes de búsqueda y sus respuestas).
 *
 * @property int $id
 * @property int $team_id
 * @property string $kind persona|empresa
 * @property string $name
 * @property ?string $email
 * @property ?string $whatsapp
 * @property string $locale
 * @property ?int $owner_id
 * @property ?int $user_id
 * @property string $status pendiente|activo|baja
 */
final class Collaborator extends Model
{
    public const KINDS = ['persona', 'empresa'];

    public const PENDING = 'pendiente';

    public const ACTIVE = 'activo';

    public const INACTIVE = 'baja';

    public const STATUSES = [self::PENDING, self::ACTIVE, self::INACTIVE];

    protected $table = 'clarto_collaborators';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'contacts' => 'array',
            'invited_at' => 'datetime',
            'activated_at' => 'datetime',
            'deactivated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::saving(function (self $c): void {
            $c->email = filled($c->email) ? Str::lower(trim((string) $c->email)) : null;
            $c->whatsapp = filled($c->whatsapp) ? (Phone::e164($c->whatsapp) ?? $c->whatsapp) : null;
        });
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(CollaboratorProperty::class);
    }

    public function activeAssignments(): HasMany
    {
        return $this->assignments()->whereNull('revoked_at');
    }

    public function notices(): HasMany
    {
        return $this->hasMany(CollaboratorNotice::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::ACTIVE);
    }

    /** Puede recibir avisos y entrar al panel (no dado de baja). Un colaborador «pendiente» recibe avisos aunque aún no haya activado su cuenta. */
    public function isEnabled(): bool
    {
        return $this->status !== self::INACTIVE;
    }

    public function firstName(): string
    {
        if ($this->kind === 'empresa') {
            $contact = collect($this->contacts ?? [])->first(fn (array $c): bool => filled($c['name'] ?? null));

            return $contact ? Str::before(trim((string) $contact['name']), ' ') : $this->name;
        }

        return Str::before(trim($this->name), ' ') ?: $this->name;
    }

    /** El colaborador del equipo con este correo o WhatsApp (para vincular respuestas entrantes). Null si no hay uno solo. */
    public static function findByAddress(int|string $teamId, ?string $phone = null, ?string $email = null): ?self
    {
        $phone = Phone::e164($phone);
        $email = filled($email) ? Str::lower(trim($email)) : null;
        if ($phone === null && $email === null) {
            return null;
        }
        $found = self::query()->where('team_id', $teamId)->where('status', '!=', self::INACTIVE)
            ->where(fn (Builder $q) => $q->when($phone, fn ($q) => $q->orWhere('whatsapp', $phone))->when($email, fn ($q) => $q->orWhere('email', $email)))
            ->limit(2)->get();

        return $found->count() === 1 ? $found->first() : null;
    }

    /**
     * Whether this phone or e-mail belongs to any collaborator of the team (active or not, main address or a company
     * contact). Owner's rule: nothing about collaborators leaves the SaaS — the GoHighLevel hooks skip these addresses.
     */
    public static function ownsAddress(int|string|null $teamId, ?string $phone = null, ?string $email = null): bool
    {
        $phone = Phone::e164($phone);
        $email = filled($email) ? Str::lower(trim($email)) : null;
        if ($teamId === null || ($phone === null && $email === null)) {
            return false;
        }

        return self::query()->where('team_id', $teamId)->get(['whatsapp', 'email', 'contacts'])
            ->contains(fn (self $c): bool => ($phone !== null && ($c->whatsapp === $phone || collect($c->contacts ?? [])->contains(fn (array $k): bool => Phone::e164($k['phone'] ?? null) === $phone)))
                || ($email !== null && ($c->email === $email || collect($c->contacts ?? [])->contains(fn (array $k): bool => Str::lower(trim((string) ($k['email'] ?? ''))) === $email))));
    }

    /** Auditoría (activity_log 'colaboradores'): quién creó, asignó, modificó, compartió o cerró. */
    public static function log(Model $subject, string $event, array $properties = []): void
    {
        rescue(fn () => activity('colaboradores')->performedOn($subject)->causedBy(auth()->user())->event($event)->withProperties($properties)->log($event), report: true);
    }
}
