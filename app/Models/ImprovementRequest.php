<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Liberu\Foundation\Files\Support\UploadPolicy;
use Liberu\Foundation\Organizations\Models\Team;

/**
 * «Solicitud de mejoras»: an improvement request from a team member, with optional screenshots kept on the
 * private disk (served only through the authorised attachment route) and a history of status changes and
 * replies. The author sees their own requests; administrators of the request's team see all of the team's.
 *
 * @property array<int, array{path: string, name: string, mime: string, size: int}>|null $attachments
 */
final class ImprovementRequest extends Model
{
    public const STATUSES = ['received', 'in_review', 'planned', 'implemented', 'discarded'];

    public const DISK = 'local';

    public const MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public const MAX_BYTES = 10 * 1024 * 1024;

    public const MAX_FILES = 10;

    /** Team roles that answer requests (the Jetstream team owner too). */
    public const ADMIN_ROLES = ['super_admin', 'admin'];

    protected $table = 'clarto_improvement_requests';

    protected $guarded = [];

    protected $casts = ['attachments' => 'array'];

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<ImprovementRequestEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(ImprovementRequestEvent::class)->orderBy('created_at')->orderBy('id');
    }

    /** Uploads of a user wait here until the request is sent: only these paths can be attached by that user. */
    public static function uploadDirectory(int|string $userId): string
    {
        return "mejoras/subidas/{$userId}";
    }

    public static function statusLabel(?string $status): string
    {
        return $status === null ? '—' : __("clarto.mejoras.statuses.{$status}");
    }

    /** @return array<string, string> */
    public static function statusOptions(): array
    {
        return array_combine(self::STATUSES, array_map(self::statusLabel(...), self::STATUSES));
    }

    public static function isTeamAdmin(User $user, int|string|null $teamId): bool
    {
        if ($teamId === null) {
            return false;
        }
        if ((string) Team::query()->whereKey($teamId)->value('user_id') === (string) $user->getKey()) {
            return true;
        }

        $pivot = (string) config('permission.table_names.model_has_roles', 'model_has_roles');
        $roles = (string) config('permission.table_names.roles', 'roles');
        $modelKey = (string) (config('permission.column_names.model_morph_key') ?: 'model_id');
        $roleKey = (string) (config('permission.column_names.role_pivot_key') ?: 'role_id');
        $teamKey = (string) (config('permission.column_names.team_foreign_key') ?: 'team_id');

        return DB::table($pivot)->join($roles, "{$roles}.id", '=', "{$pivot}.{$roleKey}")
            ->where("{$pivot}.{$modelKey}", $user->getKey())->where("{$pivot}.model_type", $user->getMorphClass())
            ->where("{$pivot}.{$teamKey}", $teamId)->whereIn("{$roles}.name", self::ADMIN_ROLES)
            ->exists();
    }

    /** Own requests, plus every request of the teams the user administers. */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }
        $adminTeams = collect($user->allTeams())->map->getKey()->filter(fn ($teamId): bool => self::isTeamAdmin($user, $teamId))->values()->all();

        return $query->where(fn (Builder $q) => $q->where('user_id', $user->getKey())->orWhereIn('team_id', $adminTeams));
    }

    /**
     * Sends a request. $paths are files the user uploaded to their upload directory on the private disk; each
     * one is checked here (content type and size, whatever the form allowed) and moved next to the request.
     *
     * @param  list<string>  $paths
     * @param  array<string, string>  $names  original file name by path
     */
    public static function submit(User $user, int|string $teamId, string $title, string $description, array $paths = [], array $names = []): self
    {
        $team = Team::query()->findOrFail($teamId);
        if (! $user->belongsToTeam($team)) {
            throw ValidationException::withMessages(['team' => __('clarto.mejoras.errors.team')]);
        }
        $files = self::checkUploads($user, array_values(array_filter($paths)));

        return DB::transaction(function () use ($user, $team, $title, $description, $files, $names): self {
            $request = self::query()->create([
                'team_id' => $team->getKey(), 'user_id' => $user->getKey(), 'status' => 'received',
                'title' => trim($title), 'description' => trim($description),
            ]);

            $disk = Storage::disk(self::DISK);
            $attachments = [];
            foreach ($files as $i => [$path, $mime, $size]) {
                $target = "mejoras/{$team->getKey()}/{$request->getKey()}/".($i + 1).'-'.basename($path);
                $disk->move($path, $target);
                $attachments[] = ['path' => $target, 'name' => mb_substr((string) ($names[$path] ?? basename($path)), 0, 200), 'mime' => $mime, 'size' => $size];
            }
            $request->forceFill(['attachments' => $attachments])->save();
            $request->events()->create(['user_id' => $user->getKey(), 'to_status' => 'received', 'created_at' => now()]);

            return $request;
        });
    }

    /** An administrator of the team answers and/or changes the status; both are kept in the history. */
    public function respond(User $by, string $status, ?string $reply = null): void
    {
        Gate::forUser($by)->authorize('update', $this);
        if (! in_array($status, self::STATUSES, true)) {
            throw ValidationException::withMessages(['status' => __('clarto.mejoras.errors.status')]);
        }
        $reply = filled($reply) ? trim((string) $reply) : null;
        if ($status === $this->status && $reply === null) {
            return;
        }

        DB::transaction(function () use ($by, $status, $reply): void {
            $this->events()->create([
                'user_id' => $by->getKey(), 'body' => $reply, 'created_at' => now(),
                'from_status' => $status !== $this->status ? $this->status : null,
                'to_status' => $status !== $this->status ? $status : null,
            ]);
            $this->forceFill(['status' => $status])->save();
        });
    }

    /**
     * Server-side check of every upload: it must be the user's own upload, a JPG/PNG/WebP by content and at most
     * MAX_BYTES (Files module UploadPolicy). A rejected file is deleted and nothing is created.
     *
     * @param  list<string>  $paths
     * @return list<array{0: string, 1: string, 2: int}>
     */
    private static function checkUploads(User $user, array $paths): array
    {
        if (count($paths) > self::MAX_FILES) {
            throw ValidationException::withMessages(['attachments' => __('clarto.mejoras.errors.too_many', ['max' => self::MAX_FILES])]);
        }
        $disk = Storage::disk(self::DISK);
        $prefix = self::uploadDirectory($user->getKey()).'/';
        $files = [];
        foreach ($paths as $path) {
            if (! str_starts_with($path, $prefix) || str_contains($path, '..') || ! $disk->exists($path)) {
                throw ValidationException::withMessages(['attachments' => __('clarto.mejoras.errors.file')]);
            }
            // From the content only: Flysystem's mimeType() falls back to the extension for "inconclusive" types (text/plain).
            $stream = $disk->readStream($path);
            $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer((string) fread($stream, 8192));
            fclose($stream);
            $size = (int) $disk->size($path);
            try {
                app(UploadPolicy::class)->assert($mime, $size, self::MIME_TYPES, self::MAX_BYTES);
            } catch (InvalidArgumentException) {
                $disk->delete($path);
                throw ValidationException::withMessages(['attachments' => __('clarto.mejoras.errors.image', ['mb' => self::MAX_BYTES / 1024 / 1024])]);
            }
            $files[] = [$path, $mime, $size];
        }

        return $files;
    }
}
