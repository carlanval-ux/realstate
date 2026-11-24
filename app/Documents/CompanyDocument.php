<?php

declare(strict_types=1);

namespace App\Documents;

use App\Collaborators\CollaboratorAccess;
use App\Filament\Resources\CompanyDocumentResource;
use App\Models\ImprovementRequest;
use App\Models\User;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Liberu\Foundation\Files\Support\UploadPolicy;
use Liberu\Foundation\Organizations\Models\Team;
use Spatie\Permission\PermissionRegistrar;

/**
 * «Documentación de la empresa» (docs/clarto/gestor-documental.md): the company's own official documents (deeds,
 * collaboration contracts, powers of attorney, partners' identity documents…), for the management team only:
 * super administrators, administrators of the company (team) and whoever holds «documentacion.ver» in it. Files on
 * the private disk, served only by CompanyDocumentFileController, which logs every view and download.
 * Nothing else in the panel reads this model (assistant, Procesos, GHL, WhatsApp, website, API).
 *
 * @property array<int, array{path: string, name: string, mime: string, size: int}>|null $files
 * @property CarbonInterface|null $expires_at
 * @property CarbonInterface|null $document_date
 */
final class CompanyDocument extends Model
{
    public const PERMISSION = 'documentacion.ver';

    public const CATEGORIES = ['escrituras', 'contratos_colaboracion', 'poderes', 'identidad_socios', 'otra'];

    public const DISK = 'local';

    public const MIME_TYPES = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];

    public const MAX_BYTES = 20 * 1024 * 1024;

    public const MAX_FILES = 10;

    /** Days before expiry when the list marks it and the management team gets a notice. */
    public const WARN_DAYS = 60;

    protected $table = 'clarto_company_documents';

    protected $guarded = ['id'];

    protected $casts = ['files' => 'array', 'document_date' => 'date', 'expires_at' => 'date', 'expiry_notified_at' => 'datetime'];

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return HasMany<CompanyDocumentLog, $this> */
    public function logs(): HasMany
    {
        return $this->hasMany(CompanyDocumentLog::class)->latest('id');
    }

    /** Management team of $teamId: super administrators, the company's administrators and holders of «documentacion.ver» there. */
    public static function canAccess(?object $user, int|string|null $teamId): bool
    {
        if (! $user instanceof User || $teamId === null || ! CollaboratorAccess::isStaff($user)) {
            return false;
        }
        if ($user->isSuperAdmin() || ImprovementRequest::isTeamAdmin($user, $teamId)) {
            return true;
        }

        return self::holders($teamId)->contains((string) $user->getKey());
    }

    /** Only administrators decide who else gets access. */
    public static function canManageAccess(?object $user, int|string|null $teamId): bool
    {
        return $user instanceof User && $teamId !== null && ($user->isSuperAdmin() || ImprovementRequest::isTeamAdmin($user, $teamId));
    }

    /**
     * Users holding «documentacion.ver» in the team, directly or through a role of that team (read on the pivots:
     * hasPermissionTo is bound to the active team, which is not set on the download route or in the scheduler).
     *
     * @return Collection<int, string>
     */
    public static function holders(int|string $teamId): Collection
    {
        [$t, $teamKey, $modelKey] = self::permissionTables();
        $id = DB::table($t('permissions'))->where('name', self::PERMISSION)->where('guard_name', 'web')->value('id');
        if ($id === null) {
            return collect();
        }
        $viaRole = DB::table($t('model_has_roles').' as mr')->join($t('role_has_permissions').' as rp', 'rp.role_id', '=', 'mr.role_id')
            ->where('mr.model_type', (new User())->getMorphClass())->where("mr.{$teamKey}", $teamId)->where('rp.permission_id', $id)->pluck("mr.{$modelKey}");
        $direct = DB::table($t('model_has_permissions'))->where('model_type', (new User())->getMorphClass())
            ->where($teamKey, $teamId)->where('permission_id', $id)->pluck($modelKey);

        return $viaRole->merge($direct)->map(fn ($id): string => (string) $id)->unique()->values();
    }

    /**
     * «Gestionar acceso»: exactly these users hold the permission directly in the team (roles are left as they are).
     *
     * @param  list<int|string>  $userIds
     */
    public static function grantDirectAccess(User $by, int|string $teamId, array $userIds): void
    {
        if (! self::canManageAccess($by, $teamId)) {
            throw ValidationException::withMessages(['users' => __('documentacion.errors.access')]);
        }
        [$t, $teamKey, $modelKey] = self::permissionTables();
        $id = DB::table($t('permissions'))->where('name', self::PERMISSION)->where('guard_name', 'web')->value('id');
        $members = self::staffMembers($teamId)->keys()->map(fn ($id): string => (string) $id);
        $userIds = collect($userIds)->map(fn ($id): string => (string) $id)->intersect($members)->values();
        $morph = (new User())->getMorphClass();
        DB::transaction(function () use ($t, $teamKey, $modelKey, $id, $teamId, $userIds, $morph): void {
            DB::table($t('model_has_permissions'))->where('permission_id', $id)->where('model_type', $morph)->where($teamKey, $teamId)->delete();
            DB::table($t('model_has_permissions'))->insert($userIds->map(fn (string $userId): array => [
                'permission_id' => $id, 'model_type' => $morph, $modelKey => $userId, $teamKey => $teamId,
            ])->all());
        });
        rescue(fn () => app(PermissionRegistrar::class)->forgetCachedPermissions(), report: false);
    }

    /** @return Collection<int|string, string> staff members of the company (id => name), owner included */
    public static function staffMembers(int|string $teamId): Collection
    {
        $team = Team::query()->find($teamId);

        return $team === null ? collect() : collect($team->allUsers())
            ->filter(fn ($user): bool => $user instanceof User && CollaboratorAccess::isStaff($user) && blank($user->getAttribute('disabled_at')))
            ->sortBy('name')->mapWithKeys(fn (User $user): array => [$user->getKey() => $user->name]);
    }

    /** @return array<string, string> */
    public static function categoryOptions(): array
    {
        return array_combine(self::CATEGORIES, array_map(fn (string $c): string => __("documentacion.categories.{$c}"), self::CATEGORIES));
    }

    public static function uploadDirectory(int|string $userId): string
    {
        return "gestor-documental/subidas/{$userId}";
    }

    /** caducado | proximo | null */
    public function expiryStatus(): ?string
    {
        if ($this->expires_at === null) {
            return null;
        }

        return match (true) {
            $this->expires_at->lt(today()) => 'caducado',
            $this->expires_at->lte(today()->addDays(self::WARN_DAYS)) => 'proximo',
            default => null,
        };
    }

    /**
     * Creates ($document null) or updates a document. $data: title, category, subject, document_date, expires_at, notes,
     * new_files (paths in the user's upload directory), new_file_names (path => original name), remove_files (indexes).
     *
     * @param  array<string, mixed>  $data
     */
    public static function saveFor(User $by, int|string $teamId, array $data, ?self $document = null): self
    {
        if (! self::canAccess($by, $document->team_id ?? $teamId)) {
            throw ValidationException::withMessages(['title' => __('documentacion.errors.access')]);
        }
        if (! in_array($data['category'] ?? null, self::CATEGORIES, true)) {
            throw ValidationException::withMessages(['category' => __('documentacion.errors.category')]);
        }
        $uploads = self::checkUploads($by, array_values(array_filter((array) ($data['new_files'] ?? []))));
        $names = (array) ($data['new_file_names'] ?? []);
        $remove = array_map('intval', (array) ($data['remove_files'] ?? []));
        $kept = array_values(array_filter((array) $document?->files, fn ($file, $i): bool => ! in_array($i, $remove, true), ARRAY_FILTER_USE_BOTH));
        if ($kept === [] && $uploads === []) {
            throw ValidationException::withMessages(['new_files' => __('documentacion.errors.no_file')]);
        }

        $document ??= new self(['team_id' => $teamId, 'created_by' => $by->getKey()]);
        $removed = array_values(array_filter((array) $document->files, fn ($file, $i): bool => in_array($i, $remove, true), ARRAY_FILTER_USE_BOTH));
        $document->fill([
            'title' => trim((string) $data['title']),
            'category' => $data['category'],
            'subject' => filled($data['subject'] ?? null) ? trim((string) $data['subject']) : null,
            'document_date' => $data['document_date'],
            'expires_at' => $data['expires_at'] ?? null,
            'notes' => filled($data['notes'] ?? null) ? trim((string) $data['notes']) : null,
            'updated_by' => $by->getKey(),
        ]);
        if ($document->isDirty('expires_at')) {
            $document->expiry_notified_at = null;   // a new expiry date gets its own notice
        }

        DB::transaction(function () use ($document, $kept, $uploads, $names): void {
            $document->files = $kept;
            $document->save();
            $disk = Storage::disk(self::DISK);
            foreach ($uploads as [$path, $mime, $size]) {
                $target = "gestor-documental/{$document->team_id}/{$document->getKey()}/".bin2hex(random_bytes(8)).'-'.basename($path);
                $disk->move($path, $target);
                $kept[] = ['path' => $target, 'name' => mb_substr((string) ($names[$path] ?? basename($path)), 0, 200), 'mime' => $mime, 'size' => $size];
            }
            $document->forceFill(['files' => $kept])->save();
        });
        foreach ($removed as $file) {
            Storage::disk(self::DISK)->delete($file['path']);   // replacing a file is uploading another one: no versions
        }

        return $document;
    }

    /**
     * Server-side check: the user's own uploads, PDF/JPG/PNG/WebP by content (finfo, not the extension), ≤ MAX_BYTES.
     * A rejected file is deleted and nothing is saved.
     *
     * @param  list<string>  $paths
     * @return list<array{0: string, 1: string, 2: int}>
     */
    private static function checkUploads(User $user, array $paths): array
    {
        if (count($paths) > self::MAX_FILES) {
            throw ValidationException::withMessages(['new_files' => __('documentacion.errors.too_many', ['max' => self::MAX_FILES])]);
        }
        $disk = Storage::disk(self::DISK);
        $prefix = self::uploadDirectory($user->getKey()).'/';
        $files = [];
        foreach ($paths as $path) {
            if (! str_starts_with($path, $prefix) || str_contains($path, '..') || ! $disk->exists($path)) {
                throw ValidationException::withMessages(['new_files' => __('documentacion.errors.file')]);
            }
            $stream = $disk->readStream($path);
            $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer((string) fread($stream, 8192));
            fclose($stream);
            $size = (int) $disk->size($path);
            try {
                app(UploadPolicy::class)->assert($mime, $size, self::MIME_TYPES, self::MAX_BYTES);
            } catch (InvalidArgumentException) {
                $disk->delete($path);
                throw ValidationException::withMessages(['new_files' => __('documentacion.errors.type', ['mb' => self::MAX_BYTES / 1024 / 1024])]);
            }
            $files[] = [$path, $mime, $size];
        }

        return $files;
    }

    /** Records who viewed or downloaded a file (RGPD: identity documents). */
    public function logAccess(User $user, int $index, string $action, ?string $ip): void
    {
        $this->logs()->create([
            'user_id' => $user->getKey(), 'action' => $action, 'ip' => $ip, 'created_at' => now(),
            'file_name' => mb_substr((string) (((array) $this->files)[$index]['name'] ?? '#'.$index), 0, 200),
        ]);
    }

    /**
     * Daily (scheduler): one bell notice per document entering the WARN_DAYS window (or already expired), to every
     * member of its company who has access. Returns the number of documents notified.
     */
    public static function notifyExpiring(): int
    {
        $count = 0;
        $documents = self::query()->whereNotNull('expires_at')->whereNull('expiry_notified_at')
            ->whereDate('expires_at', '<=', today()->addDays(self::WARN_DAYS))->with('team')->get();
        foreach ($documents as $document) {
            if ($document->team === null) {
                continue;
            }
            $title = __('documentacion.notice.'.($document->expiryStatus() === 'caducado' ? 'expired' : 'expiring'), ['title' => $document->title]);
            $body = __('documentacion.notice.body', ['date' => $document->expires_at?->format('d/m/Y')]);
            $url = CompanyDocumentResource::getUrl('view', ['record' => $document], panel: 'admin', tenant: $document->team);
            foreach (collect($document->team->allUsers())->filter(fn ($user): bool => self::canAccess($user, $document->team_id)) as $user) {
                $user->notifyNow(Notification::make()->title($title)->body($body)->icon('heroicon-o-document-text')->warning()
                    ->actions([Action::make('open')->label(__('documentacion.notice.open'))->markAsRead()->url($url)])
                    ->toDatabase());
            }
            $document->forceFill(['expiry_notified_at' => now()])->saveQuietly();
            $count++;
        }

        return $count;
    }

    /** @return array{0: \Closure(string): string, 1: string, 2: string} */
    private static function permissionTables(): array
    {
        return [
            fn (string $key): string => (string) (config("permission.table_names.{$key}") ?: $key),
            (string) (config('permission.column_names.team_foreign_key') ?: 'team_id'),
            (string) (config('permission.column_names.model_morph_key') ?: 'model_id'),
        ];
    }

    protected static function booted(): void
    {
        // The row is the only reference to its files: deleting one deletes the others. The access log is kept.
        self::deleted(function (self $document): void {
            foreach ((array) $document->files as $file) {
                Storage::disk(self::DISK)->delete($file['path']);
            }
        });
    }
}
