<?php

declare(strict_types=1);

namespace App\Models;

use App\Filament\Resources\ImprovementRequestResource;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Liberu\Foundation\Files\Support\UploadPolicy;
use Liberu\Foundation\Organizations\Models\Team;

/**
 * «Incidencias y mejoras» (formerly «Solicitud de mejoras»): a team forum thread — incident, improvement or
 * change request — with optional photos on the private disk (served only through the authorised attachment
 * route) and a history of replies (text + photos, from anyone who sees the thread) and status changes (team
 * administrators only). Every member of the thread's team sees it, except doubts sent to a process owner
 * (Procesos), which stay between their author, the process owner and the administrators.
 *
 * @property array<int, array{path: string, name: string, mime: string, size: int}>|null $attachments
 */
final class ImprovementRequest extends Model
{
    public const STATUSES = ['received', 'in_review', 'planned', 'implemented', 'discarded'];

    public const TYPES = ['incidencia', 'mejora', 'modificacion'];

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

    public static function typeLabel(?string $type): string
    {
        return $type === null ? '—' : __("clarto.mejoras.types.{$type}");
    }

    /** @return array<string, string> */
    public static function typeOptions(): array
    {
        return array_combine(self::TYPES, array_map(self::typeLabel(...), self::TYPES));
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

    /** Own threads, every thread of the teams the user administers, and their teams' threads that are not process doubts. */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }
        $teams = collect($user->allTeams())->map->getKey()->values();
        $adminTeams = $teams->filter(fn ($teamId): bool => self::isTeamAdmin($user, $teamId))->values()->all();

        return $query->where(fn (Builder $q) => $q->where('user_id', $user->getKey())->orWhereIn('team_id', $adminTeams)
            ->orWhere(function (Builder $q) use ($teams): void {
                $q->whereIn('team_id', $teams->all());
                if (self::hasProcessDoubts()) {
                    $q->whereNotIn($q->qualifyColumn('id'), DB::table('clarto_process_feedback')->select('improvement_request_id'));
                }
            }));
    }

    /** A doubt sent to a process owner («Comunicar una duda», assistant) is not shown to the whole team. */
    public function isProcessDoubt(): bool
    {
        return self::hasProcessDoubts() && DB::table('clarto_process_feedback')->where('improvement_request_id', $this->getKey())->exists();
    }

    private static function hasProcessDoubts(): bool
    {
        return Schema::hasTable('clarto_process_feedback');
    }

    /**
     * Sends a request. $paths are files the user uploaded to their upload directory on the private disk; each
     * one is checked here (content type and size, whatever the form allowed) and moved next to the request.
     *
     * @param  list<string>  $paths
     * @param  array<string, string>  $names  original file name by path
     */
    public static function submit(User $user, int|string $teamId, string $title, string $description, array $paths = [], array $names = [], string $type = 'mejora'): self
    {
        $team = Team::query()->findOrFail($teamId);
        if (! $user->belongsToTeam($team)) {
            throw ValidationException::withMessages(['team' => __('clarto.mejoras.errors.team')]);
        }
        if (! in_array($type, self::TYPES, true)) {
            throw ValidationException::withMessages(['type' => __('clarto.mejoras.errors.type')]);
        }
        $files = self::checkUploads($user, array_values(array_filter($paths)));

        return DB::transaction(function () use ($user, $team, $title, $description, $files, $names, $type): self {
            $request = self::query()->create([
                'team_id' => $team->getKey(), 'user_id' => $user->getKey(), 'status' => 'received', 'type' => $type,
                'title' => trim($title), 'description' => trim($description),
            ]);
            $request->forceFill(['attachments' => self::storeUploads($files, $names, "mejoras/{$team->getKey()}/{$request->getKey()}/")])->save();
            $request->events()->create(['user_id' => $user->getKey(), 'to_status' => 'received', 'created_at' => now()]);

            return $request;
        });
    }

    /** An administrator of the team answers and/or changes the status (Procesos «Responder» uses this). */
    public function respond(User $by, string $status, ?string $reply = null): void
    {
        Gate::forUser($by)->authorize('update', $this);
        $this->reply($by, $reply, status: $status);
    }

    /**
     * A reply in the thread: anyone who sees it writes text and/or photos (checked like the thread's); only an
     * administrator also changes the status. Each reply is a history entry, and the thread's author and the other
     * participants get an in-panel notice. Returns null when there is nothing to record.
     *
     * @param  list<string>  $paths
     * @param  array<string, string>  $names  original file name by path
     */
    public function reply(User $by, ?string $body, array $paths = [], array $names = [], ?string $status = null): ?ImprovementRequestEvent
    {
        Gate::forUser($by)->authorize('reply', $this);
        $status ??= $this->status;
        if (! in_array($status, self::STATUSES, true)) {
            throw ValidationException::withMessages(['status' => __('clarto.mejoras.errors.status')]);
        }
        if ($status !== $this->status) {
            Gate::forUser($by)->authorize('update', $this);
        }
        $body = filled($body) ? trim((string) $body) : null;
        $files = self::checkUploads($by, array_values(array_filter($paths)));
        if ($status === $this->status && $body === null && $files === []) {
            return null;
        }

        $event = DB::transaction(function () use ($by, $status, $body, $files, $names): ImprovementRequestEvent {
            $event = $this->events()->create([
                'user_id' => $by->getKey(), 'body' => $body, 'created_at' => now(),
                'from_status' => $status !== $this->status ? $this->status : null,
                'to_status' => $status !== $this->status ? $status : null,
            ]);
            if ($files !== []) {
                $event->forceFill(['attachments' => self::storeUploads($files, $names, "mejoras/{$this->team_id}/{$this->getKey()}/r{$event->getKey()}-")])->save();
            }
            $this->forceFill(['status' => $status])->touch(); // last activity: the list is sorted by it

            return $event;
        });
        $this->notifyParticipants($by, $event);

        return $event;
    }

    /** In-panel notice (Filament database notification) to the author and everyone who took part, except $by. */
    private function notifyParticipants(User $by, ImprovementRequestEvent $event): void
    {
        $ids = $this->events()->pluck('user_id')->push($this->user_id)->filter()->unique()
            ->reject(fn ($id): bool => (string) $id === (string) $by->getKey())->values();
        $recipients = User::query()->whereKey($ids->all())->get()->filter(fn (User $user): bool => Gate::forUser($user)->allows('view', $this));
        if ($recipients->isEmpty()) {
            return;
        }
        $title = $event->to_status !== null
            ? __('clarto.mejoras.notice.status', ['title' => $this->title, 'status' => self::statusLabel($event->to_status)])
            : __('clarto.mejoras.notice.reply', ['title' => $this->title]);
        $body = $by->name.($event->body !== null ? ': '.Str::limit($event->body, 140) : '');
        foreach ($recipients as $user) {
            // /admin (tenant = the thread's team) for staff, /app for everyone else (User::canAccessPanel).
            $url = $user->hasAdminAccess()
                ? ImprovementRequestResource::getUrl('view', ['record' => $this], panel: 'admin', tenant: $this->team)
                : ImprovementRequestResource::getUrl('view', ['record' => $this], panel: 'app');
            $user->notifyNow(Notification::make()->title($title)->body($body)->icon('heroicon-o-chat-bubble-left-right')
                ->actions([Action::make('open')->label(__('clarto.mejoras.notice.open'))->markAsRead()->url($url)])
                ->toDatabase()); // one row each, no queue needed
        }
    }

    /**
     * Moves checked uploads next to their thread or reply.
     *
     * @param  list<array{0: string, 1: string, 2: int}>  $files
     * @param  array<string, string>  $names
     * @return list<array{path: string, name: string, mime: string, size: int}>
     */
    private static function storeUploads(array $files, array $names, string $prefix): array
    {
        $disk = Storage::disk(self::DISK);
        $attachments = [];
        foreach ($files as $i => [$path, $mime, $size]) {
            $target = $prefix.($i + 1).'-'.basename($path);
            $disk->move($path, $target);
            $attachments[] = ['path' => $target, 'name' => mb_substr((string) ($names[$path] ?? basename($path)), 0, 200), 'mime' => $mime, 'size' => $size];
        }

        return $attachments;
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
