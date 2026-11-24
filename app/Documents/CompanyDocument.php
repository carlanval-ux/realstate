<?php

declare(strict_types=1);

namespace App\Documents;

use App\Collaborators\CollaboratorAccess;
use App\Filament\Resources\CompanyDocumentResource;
use App\Models\ImprovementRequest;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
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

/**
 * «Documentación de la empresa» (docs/clarto/gestor-documental.md): the company's own official records («expedientes»:
 * several described files and a thread of annotations, CompanyDocumentAnnotation), in folders,
 * only for its responsables: super administrators, the company's administrators (Spatie role «admin» or «super_admin»
 * in that team) and the company's owner (Jetstream team owner). There is no assignable permission. A document may be
 * put «pending approval»: every other responsable is notified (bell + e-mail) and answers conforme / no conforme; the
 * manager (who asked) sets how many approvals are needed, is notified when they are reached and then marks it approved
 * or signed. Files on the private disk, served only by CompanyDocumentFileController, which logs every view and
 * download. Nothing else in the panel reads this model (assistant, Procesos, GHL, WhatsApp, website, API).
 *
 * @property array<int, array{path: string, name: string, mime: string, size: int, description?: string|null}>|null $files
 * @property CarbonInterface|null $expires_at
 * @property CarbonInterface|null $document_date
 */
final class CompanyDocument extends Model
{
    public const PENDING = 'pendiente';

    public const APPROVED = 'aprobado';

    public const SIGNED = 'firmado';

    public const STATUSES = [self::PENDING, self::APPROVED, self::SIGNED];

    public const DISK = 'local';

    public const MIME_TYPES = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];

    public const MAX_BYTES = 20 * 1024 * 1024;

    /** Files of one expediente (the annotations' attachments do not count). */
    public const MAX_FILES = 20;

    public const MAX_DESCRIPTION = 200;

    /** Days before expiry when the list marks it and the responsables get a notice. */
    public const WARN_DAYS = 60;

    protected $table = 'clarto_company_documents';

    protected $guarded = ['id'];

    protected $casts = [
        'files' => 'array', 'document_date' => 'date', 'expires_at' => 'date', 'expiry_notified_at' => 'datetime',
        'review_requested_at' => 'datetime', 'threshold_notified_at' => 'datetime', 'status_changed_at' => 'datetime',
    ];

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<CompanyDocumentFolder, $this> */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(CompanyDocumentFolder::class, 'folder_id');
    }

    /** @return BelongsTo<User, $this> */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'review_requested_by');
    }

    /** @return HasMany<CompanyDocumentLog, $this> */
    public function logs(): HasMany
    {
        return $this->hasMany(CompanyDocumentLog::class)->latest('id');
    }

    /** @return HasMany<CompanyDocumentAnnotation, $this> oldest first */
    public function annotations(): HasMany
    {
        return $this->hasMany(CompanyDocumentAnnotation::class)->orderBy('created_at')->orderBy('id');
    }

    /** @return HasMany<CompanyDocumentVote, $this> */
    public function votes(): HasMany
    {
        return $this->hasMany(CompanyDocumentVote::class)->orderBy('updated_at');
    }

    /** A responsable of $teamId: super administrator, or administrator / owner of that company (ImprovementRequest::isTeamAdmin). */
    public static function canAccess(?object $user, int|string|null $teamId): bool
    {
        return $user instanceof User && $teamId !== null && CollaboratorAccess::isStaff($user) && blank($user->getAttribute('disabled_at'))
            && ($user->isSuperAdmin() || ImprovementRequest::isTeamAdmin($user, $teamId));
    }

    /** @return Collection<int, User> the company's responsables (members with access; the owner included) */
    public static function responsables(int|string $teamId): Collection
    {
        $team = Team::query()->find($teamId);

        return $team === null ? collect() : collect($team->allUsers())->filter(fn ($user): bool => self::canAccess($user, $teamId))->values();
    }

    /** @param Builder<self> $query */
    public function scopeInFolder(Builder $query, CompanyDocumentFolder $folder): Builder
    {
        return $query->whereIn('folder_id', $folder->withDescendantIds());
    }

    public static function uploadDirectory(int|string $userId): string
    {
        return "gestor-documental/subidas/{$userId}";
    }

    /**
     * What the record shows for a file: its description, or the file name when it has none.
     *
     * @param  array{name: string, description?: string|null}  $file
     */
    public static function fileLabel(array $file): string
    {
        return filled($file['description'] ?? null) ? (string) $file['description'] : $file['name'];
    }

    public static function statusLabel(?string $status): string
    {
        return $status === null ? '—' : __("documentacion.statuses.{$status}");
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
     * Creates ($document null) or updates an expediente. $data: folder_id, title, subject, document_date, expires_at,
     * new_files (paths in the user's upload directory), new_file_names (path => original name), new_file_descriptions
     * (path => description), remove_files (indexes), file_descriptions (index => description of a kept file).
     *
     * @param  array<string, mixed>  $data
     */
    public static function saveFor(User $by, int|string $teamId, array $data, ?self $document = null): self
    {
        $teamId = $document->team_id ?? $teamId;
        self::authorize($by, $teamId);
        $folder = CompanyDocumentFolder::query()->whereKey($data['folder_id'] ?? null)->where('team_id', $teamId)->first();
        if ($folder === null) {
            throw ValidationException::withMessages(['folder_id' => __('documentacion.errors.folder')]);
        }
        $remove = array_map('intval', (array) ($data['remove_files'] ?? []));
        $descriptions = (array) ($data['file_descriptions'] ?? []);
        $kept = [];
        foreach ((array) $document?->files as $i => $file) {
            if (! in_array($i, $remove, true)) {
                $kept[] = array_key_exists($i, $descriptions) ? ['description' => self::description($descriptions[$i])] + $file : $file;
            }
        }
        $uploads = self::checkUploads($by, array_values(array_filter((array) ($data['new_files'] ?? []))), count($kept));
        if ($kept === [] && $uploads === []) {
            throw ValidationException::withMessages(['new_files' => __('documentacion.errors.no_file')]);
        }

        $document ??= new self(['team_id' => $teamId, 'created_by' => $by->getKey()]);
        $removed = array_values(array_filter((array) $document->files, fn ($file, $i): bool => in_array($i, $remove, true), ARRAY_FILTER_USE_BOTH));
        $document->fill([
            'folder_id' => $folder->getKey(),
            'title' => trim((string) $data['title']),
            'subject' => filled($data['subject'] ?? null) ? trim((string) $data['subject']) : null,
            'document_date' => $data['document_date'],
            'expires_at' => $data['expires_at'] ?? null,
            'updated_by' => $by->getKey(),
        ]);
        if ($document->isDirty('expires_at')) {
            $document->expiry_notified_at = null;   // a new expiry date gets its own notice
        }
        DB::transaction(function () use ($document, $kept, $uploads, $data): void {
            $document->files = $kept;
            $document->save();
            $document->addFiles($uploads, (array) ($data['new_file_names'] ?? []), (array) ($data['new_file_descriptions'] ?? []));
        });
        foreach ($removed as $file) {
            Storage::disk(self::DISK)->delete($file['path']);   // replacing a file is uploading another one: no versions
        }

        return $document;
    }

    /** Voters of an approval: the responsables except its manager. @return Collection<int, User> */
    public function voters(): Collection
    {
        return self::responsables($this->team_id)->reject(fn (User $user): bool => (string) $user->getKey() === (string) $this->review_requested_by)->values();
    }

    /**
     * «Pedir aprobación»: $by becomes the manager, sets how many approvals are needed (1 … number of voters); previous
     * votes are cleared and every other responsable gets a notice (bell + e-mail).
     */
    public function requestApproval(User $by, int $required): void
    {
        self::authorize($by, $this->team_id);
        $voters = self::responsables($this->team_id)->reject(fn (User $user): bool => (string) $user->getKey() === (string) $by->getKey());
        if ($voters->isEmpty() || $required < 1 || $required > $voters->count()) {
            throw ValidationException::withMessages(['approvals_required' => __('documentacion.errors.required', ['max' => $voters->count()])]);
        }
        DB::transaction(function () use ($by, $required): void {
            $this->votes()->delete();
            $this->forceFill([
                'status' => self::PENDING, 'approvals_required' => $required, 'review_requested_by' => $by->getKey(),
                'review_requested_at' => now(), 'threshold_notified_at' => null, 'status_changed_by' => $by->getKey(), 'status_changed_at' => now(),
            ])->save();
        });
        $params = ['title' => $this->title, 'folder' => (string) $this->folder?->path(), 'by' => $by->name, 'required' => $required, 'voters' => $voters->count()];
        foreach ($voters as $user) {
            $user->notifyNow(new CompanyDocumentNotice('pending', $params, $this->url()));
        }
    }

    /** A responsable (not the manager) answers conforme / no conforme; can change it while pending. Reaching the threshold notifies the manager once. */
    public function vote(User $by, bool $approved, ?string $comment = null): CompanyDocumentVote
    {
        self::authorize($by, $this->team_id);
        if ($this->status !== self::PENDING || (string) $by->getKey() === (string) $this->review_requested_by) {
            throw ValidationException::withMessages(['approved' => __('documentacion.errors.vote')]);
        }
        $vote = $this->votes()->updateOrCreate(['user_id' => $by->getKey()], ['approved' => $approved, 'comment' => filled($comment) ? trim((string) $comment) : null]);
        $manager = $this->manager;
        if ($this->threshold_notified_at === null && $this->approvalCount() >= (int) $this->approvals_required && $manager !== null) {
            $this->forceFill(['threshold_notified_at' => now()])->save();
            $manager->notifyNow(new CompanyDocumentNotice('threshold', [
                'title' => $this->title, 'folder' => (string) $this->folder?->path(), 'by' => $manager->name,
                'required' => (int) $this->approvals_required, 'voters' => $this->voters()->count(),
            ], $this->url()));
        }

        return $vote;
    }

    /** Conforme votes of current voters (someone who lost access no longer counts). */
    public function approvalCount(): int
    {
        $voters = $this->voters()->map(fn (User $user): string => (string) $user->getKey());

        return $this->votes()->where('approved', true)->get()->filter(fn (CompanyDocumentVote $vote): bool => $voters->contains((string) $vote->user_id))->count();
    }

    /** «2 de 3 aprobaciones necesarias (5 responsables)» */
    public function approvalProgress(): ?string
    {
        if ($this->status !== self::PENDING && $this->approvals_required === null) {
            return null;
        }

        return __('documentacion.progress', ['approved' => $this->approvalCount(), 'required' => (int) $this->approvals_required, 'voters' => $this->voters()->count()]);
    }

    /**
     * Any responsable closes the approval: approved, or signed with the signed copy uploaded (required). It never
     * happens by itself, whatever the votes.
     *
     * @param  list<string>  $paths
     * @param  array<string, string>  $names
     */
    public function changeStatus(User $by, string $status, array $paths = [], array $names = []): void
    {
        self::authorize($by, $this->team_id);
        if (! in_array($status, [self::APPROVED, self::SIGNED], true) || $this->status === null || $this->status === self::SIGNED) {
            throw ValidationException::withMessages(['status' => __('documentacion.errors.status')]);
        }
        $uploads = self::checkUploads($by, array_values(array_filter($paths)), count((array) $this->files));
        if ($status === self::SIGNED && $uploads === []) {
            throw ValidationException::withMessages(['new_files' => __('documentacion.errors.signed_file')]);
        }
        DB::transaction(function () use ($by, $status, $uploads, $names): void {
            $this->forceFill(['status' => $status, 'status_changed_by' => $by->getKey(), 'status_changed_at' => now(), 'updated_by' => $by->getKey()])->save();
            $this->addFiles($uploads, $names);
        });
    }

    /**
     * Adds an annotation to the thread: any responsable; text required; at most one attachment ($path in the user's
     * upload directory), checked like the expediente's files and moved next to them.
     */
    public function annotate(User $by, string $body, ?string $path = null, ?string $name = null): CompanyDocumentAnnotation
    {
        self::authorize($by, $this->team_id);
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > CompanyDocumentAnnotation::MAX_LENGTH) {
            throw ValidationException::withMessages(['body' => __('documentacion.errors.annotation_body', ['max' => CompanyDocumentAnnotation::MAX_LENGTH])]);
        }
        $file = null;
        foreach (self::checkUploads($by, filled($path) ? [$path] : []) as [$source, $mime, $size]) {
            $target = "gestor-documental/{$this->team_id}/{$this->getKey()}/anotaciones/".bin2hex(random_bytes(8)).'-'.basename($source);
            Storage::disk(self::DISK)->move($source, $target);
            $file = ['path' => $target, 'name' => mb_substr((string) ($name ?? basename($source)), 0, 200), 'mime' => $mime, 'size' => $size];
        }

        return $this->annotations()->create(['user_id' => $by->getKey(), 'body' => $body, 'file' => $file, 'created_at' => now()]);
    }

    public function url(): string
    {
        return CompanyDocumentResource::getUrl('view', ['record' => $this], panel: 'admin', tenant: $this->team);
    }

    /**
     * Moves checked uploads next to the document.
     *
     * @param  list<array{0: string, 1: string, 2: int}>  $uploads
     * @param  array<string, string>  $names
     * @param  array<string, string|null>  $descriptions
     */
    private function addFiles(array $uploads, array $names, array $descriptions = []): void
    {
        $files = (array) $this->files;
        foreach ($uploads as [$path, $mime, $size]) {
            $target = "gestor-documental/{$this->team_id}/{$this->getKey()}/".bin2hex(random_bytes(8)).'-'.basename($path);
            Storage::disk(self::DISK)->move($path, $target);
            $files[] = ['path' => $target, 'name' => mb_substr((string) ($names[$path] ?? basename($path)), 0, 200), 'mime' => $mime, 'size' => $size,
                'description' => self::description($descriptions[$path] ?? null)];
        }
        $this->forceFill(['files' => $files])->save();
    }

    private static function description(mixed $text): ?string
    {
        return filled($text) ? mb_substr(trim((string) $text), 0, self::MAX_DESCRIPTION) : null;
    }

    private static function authorize(User $by, int|string|null $teamId): void
    {
        if (! self::canAccess($by, $teamId)) {
            throw ValidationException::withMessages(['title' => __('documentacion.errors.access')]);
        }
    }

    /**
     * Server-side check: the user's own uploads, PDF/JPG/PNG/WebP by content (finfo, not the extension), ≤ MAX_BYTES,
     * and the expediente (its $existing files plus these) ≤ MAX_FILES. A rejected file is deleted and nothing is saved.
     *
     * @param  list<string>  $paths
     * @return list<array{0: string, 1: string, 2: int}>
     */
    private static function checkUploads(User $user, array $paths, int $existing = 0): array
    {
        if (count($paths) + $existing > self::MAX_FILES) {
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

    /** Records who viewed or downloaded a file or an annotation's attachment (RGPD: identity documents). */
    public function logAccess(User $user, string $fileName, string $action, ?string $ip): void
    {
        $this->logs()->create([
            'user_id' => $user->getKey(), 'action' => $action, 'ip' => $ip, 'created_at' => now(), 'file_name' => mb_substr($fileName, 0, 200),
        ]);
    }

    /**
     * Daily (scheduler): one notice (bell + e-mail) per document entering the WARN_DAYS window (or already expired), to
     * every responsable of its company. Returns the number of documents notified.
     */
    public static function notifyExpiring(): int
    {
        $count = 0;
        $documents = self::query()->whereNotNull('expires_at')->whereNull('expiry_notified_at')
            ->whereDate('expires_at', '<=', today()->addDays(self::WARN_DAYS))->with(['team', 'folder'])->get();
        foreach ($documents as $document) {
            if ($document->team === null) {
                continue;
            }
            $kind = $document->expiryStatus() === 'caducado' ? 'expired' : 'expiring';
            $params = ['title' => $document->title, 'folder' => (string) $document->folder?->path(), 'date' => (string) $document->expires_at?->format('d/m/Y')];
            foreach (self::responsables($document->team_id) as $user) {
                $user->notifyNow(new CompanyDocumentNotice($kind, $params, $document->url()));
            }
            $document->forceFill(['expiry_notified_at' => now()])->saveQuietly();
            $count++;
        }

        return $count;
    }

    protected static function booted(): void
    {
        // The row is the only reference to its files: deleting one deletes the others. Votes and annotations (with their
        // attachments) go; the access log is kept.
        self::deleted(function (self $document): void {
            foreach ((array) $document->files as $file) {
                Storage::disk(self::DISK)->delete($file['path']);
            }
            $document->votes()->delete();
            $document->annotations()->get()->each->delete();
        });
    }
}
