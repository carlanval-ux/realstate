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
 * in that team) and the company's owner (Jetstream team owner). There is no assignable permission. A new document is
 * «Disponible» (available to the responsables, without approval or notices) unless whoever uploads it asks for approval
 * right away; «Pedir aprobación» does it later. Pending approval: every other responsable is notified (bell + e-mail)
 * and answers a favor / a favor con condiciones / en contra; the manager (who asked) sets how many approvals are needed
 * (a number, or the absolute majority of the voters), is notified when they are reached (saying whether there are
 * conditions) and then marks it approved, approved with conditions or signed. When the votes against make the number
 * impossible it is «Rechazado» by itself (manager and voters notified); «Pedir aprobación» opens a new round.
 * Files on the private disk, served only by CompanyDocumentFileController, which logs every view and
 * download. Nothing else in the panel reads this model (assistant, Procesos, GHL, WhatsApp, website, API).
 *
 * @property array<int, array{path: string, name: string, mime: string, size: int, description?: string|null}>|null $files
 * @property CarbonInterface|null $expires_at
 * @property CarbonInterface|null $document_date
 */
final class CompanyDocument extends Model
{
    /** Available to the responsables, without approval and without notices: the default of a new expediente. */
    public const AVAILABLE = 'disponible';

    public const PENDING = 'pendiente';

    public const APPROVED = 'aprobado';

    /** Closed by hand when someone voted «a favor con condiciones» (closingStatuses). */
    public const APPROVED_CONDITIONS = 'aprobado_condiciones';

    public const SIGNED = 'firmado';

    /** Set by itself when the votes against make the approvals needed impossible (vote → reject). */
    public const REJECTED = 'rechazado';

    public const STATUSES = [self::AVAILABLE, self::PENDING, self::APPROVED, self::APPROVED_CONDITIONS, self::SIGNED, self::REJECTED];

    /** Approval rule: a fixed number of approvals chosen by the manager (1 … voters). */
    public const RULE_NUMBER = 'numero';

    /** Approval rule: absolute majority of the voters (majority()), worked out when the approval is asked for. */
    public const RULE_MAJORITY = 'mayoria';

    public const RULES = [self::RULE_NUMBER, self::RULE_MAJORITY];

    public const DISK = 'local';

    /** Shown in the browser («Ver»); every other accepted type is always a download. */
    public const INLINE_TYPES = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];

    /** Word, Excel and OpenDocument by extension: what checkUploads accepts, and FilePond's extension → type map. */
    public const OFFICE_TYPES = [
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'odt' => 'application/vnd.oasis.opendocument.text',
        'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
    ];

    /** Everything checkUploads accepts, by content: INLINE_TYPES + the values of OFFICE_TYPES. */
    public const MIME_TYPES = [
        'application/pdf', 'image/jpeg', 'image/png', 'image/webp',
        'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.oasis.opendocument.text', 'application/vnd.oasis.opendocument.spreadsheet',
    ];

    public const MAX_EXPLANATION = 10000;

    public const MAX_BYTES = 20 * 1024 * 1024;

    /** Files of one expediente (the annotations' attachments do not count). */
    public const MAX_FILES = 20;

    public const MAX_DESCRIPTION = 200;

    /** Days before expiry when the list marks it and the responsables get a notice. */
    public const WARN_DAYS = 60;

    protected $table = 'clarto_company_documents';

    protected $guarded = ['id'];

    protected $attributes = ['status' => self::AVAILABLE, 'approval_rule' => self::RULE_NUMBER];

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

    /** PDF and images open in the browser; Word, Excel and OpenDocument are only downloaded. */
    public static function opensInBrowser(string $mime): bool
    {
        return in_array($mime, self::INLINE_TYPES, true);
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
     * Creates ($document null) or updates an expediente. $data: folder_id, title, subject, explanation (free text,
     * ≤ MAX_EXPLANATION; the annotations stay the dated thread), document_date, expires_at,
     * new_files (paths in the user's upload directory), new_file_names (path => original name), new_file_descriptions
     * (path => description), remove_files (indexes), file_descriptions (index => description of a kept file) and, only
     * when creating, requires_approval + approval_rule (RULES, numero when missing) + approvals_required (for numero):
     * the new expediente is put «pending approval» with $by as its manager (requestApproval; rule and number are checked
     * before anything is saved). Otherwise it stays «Disponible».
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
        $explanation = filled($data['explanation'] ?? null) ? trim((string) $data['explanation']) : null;
        if (mb_strlen((string) $explanation) > self::MAX_EXPLANATION) {
            throw ValidationException::withMessages(['explanation' => __('documentacion.errors.explanation', ['max' => self::MAX_EXPLANATION])]);
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
        $asks = $document === null && filter_var($data['requires_approval'] ?? false, FILTER_VALIDATE_BOOL);
        $rule = filled($data['approval_rule'] ?? null) ? (string) $data['approval_rule'] : self::RULE_NUMBER;
        $required = (int) ($data['approvals_required'] ?? 0);
        if ($asks) {
            self::approvalVoters($by, $teamId, $required, $rule);
        }

        $document ??= new self(['team_id' => $teamId, 'created_by' => $by->getKey()]);
        $removed = array_values(array_filter((array) $document->files, fn ($file, $i): bool => in_array($i, $remove, true), ARRAY_FILTER_USE_BOTH));
        $document->fill([
            'folder_id' => $folder->getKey(),
            'title' => trim((string) $data['title']),
            'subject' => filled($data['subject'] ?? null) ? trim((string) $data['subject']) : null,
            'explanation' => $explanation,
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
            CompanyDocumentPreview::forget($document, $file['path']);
        }
        if ($asks) {
            $document->requestApproval($by, $required, $rule);
        }

        return $document;
    }

    /** Voters of an approval: the responsables except its manager. @return Collection<int, User> */
    public function voters(): Collection
    {
        return self::otherResponsables($this->team_id, $this->review_requested_by);
    }

    /**
     * «Pedir aprobación»: $by becomes the manager and sets the rule: a number of approvals ($required, 1 … number of
     * voters) or the absolute majority of the voters ($required ignored; the number worked out now is what is stored in
     * approvals_required, so counting, the threshold notice and closing work the same). Previous votes are cleared and
     * every other responsable gets a notice (bell + e-mail).
     */
    public function requestApproval(User $by, int $required, string $rule = self::RULE_NUMBER): void
    {
        self::authorize($by, $this->team_id);
        [$voters, $required] = self::approvalVoters($by, $this->team_id, $required, $rule);
        DB::transaction(function () use ($by, $required, $rule): void {
            $this->votes()->delete();
            $this->forceFill([
                'status' => self::PENDING, 'approval_rule' => $rule, 'approvals_required' => $required, 'review_requested_by' => $by->getKey(),
                'review_requested_at' => now(), 'threshold_notified_at' => null, 'status_changed_by' => $by->getKey(), 'status_changed_at' => now(),
            ])->save();
        });
        $params = ['title' => $this->title, 'folder' => (string) $this->folder?->path(), 'by' => $by->name, 'required' => $required, 'voters' => $voters->count()];
        foreach ($voters as $user) {
            $user->notifyNow(new CompanyDocumentNotice('pending', $params, $this->url()));
        }
    }

    /**
     * Who would vote if $userId asked for approval: the company's responsables except that person. The form of a new
     * expediente and «Pedir aprobación» use the count as the maximum of approvals needed.
     *
     * @return Collection<int, User>
     */
    public static function otherResponsables(int|string $teamId, int|string|null $userId): Collection
    {
        return self::responsables($teamId)->reject(fn (User $user): bool => (string) $user->getKey() === (string) $userId)->values();
    }

    /** Absolute majority of $voters: more than half (5 → 3, 4 → 3, 1 → 1). */
    public static function majority(int $voters): int
    {
        return intdiv($voters, 2) + 1;
    }

    /**
     * The voters of an approval asked by $by and the approvals needed: someone to vote, a known rule and, for numero,
     * $required in 1 … number of voters; for mayoria, majority() of the voters.
     *
     * @return array{0: Collection<int, User>, 1: int}
     */
    private static function approvalVoters(User $by, int|string $teamId, int $required, string $rule): array
    {
        if (! in_array($rule, self::RULES, true)) {
            throw ValidationException::withMessages(['approval_rule' => __('documentacion.errors.rule')]);
        }
        $voters = self::otherResponsables($teamId, $by->getKey());
        if ($voters->isEmpty()) {
            throw ValidationException::withMessages(['approvals_required' => __('documentacion.errors.no_voters')]);
        }
        if ($rule === self::RULE_MAJORITY) {
            return [$voters, self::majority($voters->count())];
        }
        if ($required < 1 || $required > $voters->count()) {
            throw ValidationException::withMessages(['approvals_required' => __('documentacion.errors.required', ['max' => $voters->count()])]);
        }

        return [$voters, $required];
    }

    /**
     * A responsable (not the manager) answers favor / condiciones (the conditions, in $comment, required) / contra
     * (comment optional); can change it while pending. Reaching the threshold notifies the manager once (saying whether
     * someone set conditions). When the votes against leave the approvals needed out of reach
     * (contra > voters − approvals_required) the expediente is «Rechazado» by itself (reject).
     */
    public function vote(User $by, string $decision, ?string $comment = null): CompanyDocumentVote
    {
        self::authorize($by, $this->team_id);
        if ($this->status !== self::PENDING || (string) $by->getKey() === (string) $this->review_requested_by) {
            throw ValidationException::withMessages(['decision' => __('documentacion.errors.vote')]);
        }
        if (! in_array($decision, CompanyDocumentVote::DECISIONS, true)) {
            throw ValidationException::withMessages(['decision' => __('documentacion.errors.decision')]);
        }
        $comment = filled($comment) ? trim((string) $comment) : null;
        if ($decision === CompanyDocumentVote::CONDITIONS && $comment === null) {
            throw ValidationException::withMessages(['comment' => __('documentacion.errors.conditions')]);
        }
        if (mb_strlen((string) $comment) > CompanyDocumentVote::MAX_COMMENT) {
            throw ValidationException::withMessages(['comment' => __('documentacion.errors.comment', ['max' => CompanyDocumentVote::MAX_COMMENT])]);
        }
        $vote = $this->votes()->updateOrCreate(['user_id' => $by->getKey()], ['decision' => $decision, 'comment' => $comment]);
        $voters = $this->voters();
        $counts = $this->decisionCounts();
        $required = (int) $this->approvals_required;
        if ($counts[CompanyDocumentVote::AGAINST] > $voters->count() - $required) {
            $this->reject($voters, $counts[CompanyDocumentVote::AGAINST]);

            return $vote;
        }
        $manager = $this->manager;
        $approvals = $counts[CompanyDocumentVote::FAVOR] + $counts[CompanyDocumentVote::CONDITIONS];
        if ($this->threshold_notified_at === null && $approvals >= $required && $manager !== null) {
            $this->forceFill(['threshold_notified_at' => now()])->save();
            $manager->notifyNow(new CompanyDocumentNotice($counts[CompanyDocumentVote::CONDITIONS] > 0 ? 'threshold_conditions' : 'threshold', [
                'title' => $this->title, 'folder' => (string) $this->folder?->path(), 'by' => $manager->name,
                'required' => $required, 'voters' => $voters->count(), 'conditions' => $counts[CompanyDocumentVote::CONDITIONS],
            ], $this->url()));
        }

        return $vote;
    }

    /**
     * Pending → «Rechazado», once (the status is checked in the UPDATE itself, so two votes at the same time cannot both
     * do it), then the manager and every voter get a notice (bell + e-mail, each in their language).
     *
     * @param  Collection<int, User>  $voters
     */
    private function reject(Collection $voters, int $against): void
    {
        $changed = self::query()->whereKey($this->getKey())->where('status', self::PENDING)
            ->update(['status' => self::REJECTED, 'status_changed_by' => null, 'status_changed_at' => now(), 'updated_at' => now()]);
        $this->refresh();
        if ($changed === 0) {
            return;
        }
        $params = ['title' => $this->title, 'folder' => (string) $this->folder?->path(), 'against' => $against,
            'required' => (int) $this->approvals_required, 'voters' => $voters->count()];
        foreach (collect([$this->manager])->filter()->merge($voters)->unique(fn (User $user): string => (string) $user->getKey()) as $user) {
            $user->notifyNow(new CompanyDocumentNotice('rejected', $params, $this->url()));
        }
    }

    /**
     * Votes of current voters by decision (someone who lost access no longer counts).
     *
     * @return array<string, int> favor, condiciones, contra
     */
    public function decisionCounts(): array
    {
        $voters = $this->voters()->map(fn (User $user): string => (string) $user->getKey());
        $counts = [CompanyDocumentVote::FAVOR => 0, CompanyDocumentVote::CONDITIONS => 0, CompanyDocumentVote::AGAINST => 0];
        foreach ($this->votes()->get() as $vote) {
            if ($voters->contains((string) $vote->user_id) && isset($counts[$vote->decision])) {
                $counts[$vote->decision]++;
            }
        }

        return $counts;
    }

    /** Approvals of current voters: «a favor» and «a favor con condiciones». */
    public function approvalCount(): int
    {
        $counts = $this->decisionCounts();

        return $counts[CompanyDocumentVote::FAVOR] + $counts[CompanyDocumentVote::CONDITIONS];
    }

    /** @return Collection<int, CompanyDocumentVote> the «con condiciones» votes of current voters, with their author, oldest first */
    public function conditionVotes(): Collection
    {
        $voters = $this->voters()->map(fn (User $user): string => (string) $user->getKey());

        return $this->votes()->where('decision', CompanyDocumentVote::CONDITIONS)->with('user')->get()
            ->filter(fn (CompanyDocumentVote $vote): bool => $voters->contains((string) $vote->user_id))->values()->toBase();
    }

    /** «2 a favor · 1 con condiciones · 1 en contra · 1 sin votar (necesarias: 3)», with the majority rule «(necesarias: 3, mayoría absoluta de 5)» */
    public function approvalProgress(): ?string
    {
        if ($this->status !== self::PENDING && $this->approvals_required === null) {
            return null;
        }
        $counts = $this->decisionCounts();
        $voters = $this->voters()->count();

        return __($this->approval_rule === self::RULE_MAJORITY ? 'documentacion.progress_majority' : 'documentacion.progress', [
            'favor' => $counts[CompanyDocumentVote::FAVOR], 'conditions' => $counts[CompanyDocumentVote::CONDITIONS],
            'against' => $counts[CompanyDocumentVote::AGAINST], 'missing' => max(0, $voters - array_sum($counts)),
            'required' => (int) $this->approvals_required, 'voters' => $voters,
        ]);
    }

    /**
     * Where «Cerrar aprobación» can take it now: from pending, approved, approved with conditions (only when someone
     * voted «con condiciones») or signed; from approved / approved with conditions, signed. Nothing from the others
     * (a rejected one needs a new «Pedir aprobación»).
     *
     * @return list<string>
     */
    public function closingStatuses(): array
    {
        return match ($this->status) {
            self::PENDING => $this->conditionVotes()->isEmpty() ? [self::APPROVED, self::SIGNED] : [self::APPROVED, self::APPROVED_CONDITIONS, self::SIGNED],
            self::APPROVED, self::APPROVED_CONDITIONS => [self::SIGNED],
            default => [],
        };
    }

    /**
     * Any responsable closes the approval (closingStatuses): approved, approved with conditions, or signed with the
     * signed copy uploaded (required). Only the rejection happens by itself.
     *
     * @param  list<string>  $paths
     * @param  array<string, string>  $names
     */
    public function changeStatus(User $by, string $status, array $paths = [], array $names = []): void
    {
        self::authorize($by, $this->team_id);
        if (! in_array($status, $this->closingStatuses(), true)) {
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
     * Server-side check: the user's own uploads, MIME_TYPES by content (detectType, not the extension), ≤ MAX_BYTES,
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
            $mime = self::detectType($disk->path($path));
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

    /**
     * The type by content: libmagic on the whole file (local disk). A .docx / .xlsx / .ods it only sees as a zip (it
     * depends on the order of the entries) is told apart by its contents; nothing is extracted, run or converted.
     */
    private static function detectType(string $file): string
    {
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file);

        // Zip-based types are always opened: libmagic's own «docx» does not look for macros.
        return in_array($mime, ['application/zip', 'application/octet-stream', ...self::zipTypes()], true) ? self::zipOfficeType($file) : $mime;
    }

    /** @return list<string> the Office / OpenDocument types that are zip files (not the old .doc / .xls) */
    private static function zipTypes(): array
    {
        return [self::OFFICE_TYPES['docx'], self::OFFICE_TYPES['xlsx'], self::OFFICE_TYPES['odt'], self::OFFICE_TYPES['ods']];
    }

    /**
     * Word / Excel (OOXML: «[Content_Types].xml» + «word/document.xml» or «xl/workbook.xml») or OpenDocument (its
     * «mimetype» entry), without macros; '' for one with macros, any other zip, or anything that is not a zip.
     */
    private static function zipOfficeType(string $file): string
    {
        $zip = new \ZipArchive();
        if ($zip->open($file, \ZipArchive::RDONLY) !== true) {
            return '';
        }
        $has = fn (string $name): bool => $zip->locateName($name) !== false;
        $macros = $has('word/vbaProject.bin') || $has('xl/vbaProject.bin') || $has('Basic/script-lc.xml');
        $type = match (true) {
            $macros => '',
            $has('[Content_Types].xml') && $has('word/document.xml') => self::OFFICE_TYPES['docx'],
            $has('[Content_Types].xml') && $has('xl/workbook.xml') => self::OFFICE_TYPES['xlsx'],
            default => (string) $zip->getFromName('mimetype', 100),
        };
        $zip->close();

        return in_array($type, self::zipTypes(), true) ? $type : '';
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
            CompanyDocumentPreview::forgetAll($document);   // the viewer's converted copies
        });
    }
}
