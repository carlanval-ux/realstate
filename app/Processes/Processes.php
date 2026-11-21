<?php

declare(strict_types=1);

namespace App\Processes;

use App\Models\User;
use App\Processes\Events\ProcessArchived;
use App\Processes\Events\ProcessVersionPublished;
use App\Processes\Models\Area;
use App\Processes\Models\Process;
use App\Processes\Models\ProcessAttachment;
use App\Processes\Models\ProcessAudience;
use App\Processes\Models\ProcessEvent;
use App\Processes\Models\ProcessMapEntry;
use App\Processes\Models\ProcessSettings;
use App\Processes\Models\ProcessVersion;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Life-cycle operations (diseno.md §6). Each one checks permissions and writes clarto_process_events. */
final class Processes
{
    /** Process attributes accepted by createDraft / updateMetadata. */
    public const PROCESS_FIELDS = ['area_id', 'category', 'business_line', 'kind', 'owner_user_id', 'approver_user_id', 'visibility', 'ai_excluded', 'map_entry_id', 'next_review_on'];

    /**
     * New process with its first draft. $attributes: title (required), area_id, category, business_line, kind,
     * owner_user_id, map_entry_id, ai_assisted, summary, objective, steps…, position_ids; $templateKey pre-fills.
     */
    public static function createDraft(User $by, int|string $teamId, array $attributes, ?string $templateKey = null): ProcessVersion
    {
        self::moduleOn();
        self::allow(ProcessAccess::canCreate($by, $teamId));
        if (blank($attributes['title'] ?? null)) {
            throw ValidationException::withMessages(['title' => __('procesos.faltas.title')]);
        }
        $entry = filled($attributes['map_entry_id'] ?? null) ? ProcessMapEntry::query()->where('team_id', $teamId)->findOrFail($attributes['map_entry_id']) : null;
        $templateKey ??= $entry?->template_key;
        $defaults = $templateKey !== null ? ProcessTemplates::defaults($templateKey) : [];
        $area = filled($attributes['area_id'] ?? null)
            ? Area::query()->where('team_id', $teamId)->find($attributes['area_id'])
            : ($entry ? Area::query()->where('team_id', $teamId)->where('code', $entry->area_code)->first() : null);
        if ($area === null) {
            throw ValidationException::withMessages(['area_id' => __('procesos.errores.area')]);
        }

        return DB::transaction(function () use ($by, $teamId, $attributes, $templateKey, $defaults, $area, $entry): ProcessVersion {
            $process = Process::query()->create([
                'team_id' => $teamId,
                'code' => self::nextCode($teamId, $area, $entry),
                'area_id' => $area->getKey(),
                'category' => $attributes['category'] ?? $entry?->category ?? $defaults['category'] ?? 'operativo',
                'business_line' => $attributes['business_line'] ?? $entry?->business_line ?? $defaults['business_line'] ?? $area->business_line,
                'kind' => $attributes['kind'] ?? $entry?->kind ?? $defaults['kind'] ?? 'breve',
                'status' => 'borrador',
                'owner_user_id' => $attributes['owner_user_id'] ?? $by->getKey(),
                'approver_user_id' => $attributes['approver_user_id'] ?? null,
                'visibility' => $attributes['visibility'] ?? 'empresa',
                'ai_excluded' => (bool) ($attributes['ai_excluded'] ?? false),
                'map_entry_id' => $entry?->getKey(),
                'template_key' => $templateKey,
                'created_by' => $by->getKey(),
            ]);
            $positionIds = $attributes['position_ids'] ?? ($entry ? DB::table('clarto_positions')->where('team_id', $teamId)->whereIn('key', (array) $entry->executing_position_keys)->pluck('id')->all() : []);
            $process->positions()->sync($positionIds);
            $entry?->forceFill(['process_id' => $process->getKey()])->save();

            $content = array_intersect_key($attributes, array_flip(ProcessVersion::CONTENT));
            $version = $process->versions()->create([
                ...array_intersect_key($defaults, array_flip(ProcessVersion::CONTENT)),
                ...$content,
                'team_id' => $teamId, 'status' => 'borrador', 'ai_assisted' => (bool) ($attributes['ai_assisted'] ?? false), 'created_by' => $by->getKey(),
                'change_summary' => $content['change_summary'] ?? __('procesos.primera_version'),
            ]);
            self::event($process, $version, $by, 'creado', data: ['code' => $process->code, 'template' => $templateKey]);

            return $version;
        });
    }

    /** Draft from the current version (or the last one of an archived process), or the one already open. */
    public static function startRevision(User $by, Process $process): ProcessVersion
    {
        self::moduleOn();
        self::allow(ProcessAccess::canEdit($by, $process));

        return DB::transaction(function () use ($by, $process): ProcessVersion {
            $process = Process::query()->lockForUpdate()->findOrFail($process->getKey());
            if (($open = $process->openVersion()->first()) !== null) {
                return $open;
            }
            $source = $process->currentVersion ?? $process->versions()->whereNotNull('number')->orderByDesc('number')->first();
            if ($source === null) {
                throw ValidationException::withMessages(['process' => __('procesos.errores.sin_version')]);
            }
            $draft = $process->versions()->create([
                ...$source->only(ProcessVersion::CONTENT),
                'team_id' => $process->team_id, 'status' => 'borrador', 'based_on_version_id' => $source->getKey(),
                'created_by' => $by->getKey(), 'change_summary' => null, 'ai_assisted' => false,
            ]);
            $map = [];
            foreach ($source->attachments as $attachment) {
                $copy = $attachment->replicate(['process_version_id']);
                $copy->process_version_id = $draft->getKey();
                $copy->save();
                $map[$attachment->getKey()] = $copy->getKey();
            }
            if ($map !== []) {
                $draft->forceFill(self::remapAttachments($draft->only(['steps', 'blocks', 'tools']), $map))->save();
            }
            self::event($process, $draft, $by, $process->status === 'archivado' ? 'restaurado' : 'borrador_creado');

            return $draft;
        });
    }

    /** Saves the content of an open draft (minimum: title). */
    public static function saveDraft(User $by, ProcessVersion $draft, array $content): ProcessVersion
    {
        self::allow($draft->status === 'borrador' && ProcessAccess::canEdit($by, $draft->process));
        $content = array_intersect_key($content, array_flip([...ProcessVersion::CONTENT, 'ai_assisted']));
        if (array_key_exists('title', $content) && blank($content['title'])) {
            throw ValidationException::withMessages(['title' => __('procesos.faltas.title')]);
        }
        $draft->fill($content)->save();
        // Attachments no longer referenced by the draft are removed (the file stays while another row uses it).
        $draft->attachments()->whereNotIn('id', $draft->referencedAttachmentIds() ?: [0])->get()->each->delete();

        return $draft;
    }

    /** Process metadata (not versioned): changes are logged and apply to every version at once. */
    public static function updateMetadata(User $by, Process $process, array $attributes, ?array $positionIds = null, ?array $audiences = null): void
    {
        self::allow(ProcessAccess::canEdit($by, $process) || ProcessAccess::canMaintain($by, $process) || ProcessAccess::canAdminister($by, $process->team_id));
        foreach (['owner_user_id', 'approver_user_id'] as $field) {   // only active people of the same company
            if (filled($attributes[$field] ?? null) && (string) $attributes[$field] !== (string) $process->{$field} && ! array_key_exists((int) $attributes[$field], \App\Filament\Processes\ProcessUi::users($process->team_id))) {
                throw ValidationException::withMessages([$field => __('procesos.faltas.owner')]);
            }
        }
        DB::transaction(function () use ($by, $process, $attributes, $positionIds, $audiences): void {
            $before = $process->only(self::PROCESS_FIELDS);
            $process->fill(array_intersect_key($attributes, array_flip(self::PROCESS_FIELDS)));
            $dirty = array_keys($process->getDirty());
            $process->save();
            if (in_array('owner_user_id', $dirty, true)) {
                self::event($process, null, $by, 'responsable_cambiado', data: ['antes' => $before['owner_user_id'], 'despues' => $process->owner_user_id]);
            }
            $other = array_values(array_diff($dirty, ['owner_user_id', 'visibility']));
            $oldPositions = $process->positions()->pluck('clarto_positions.id')->map('intval')->sort()->values()->all();
            if ($positionIds !== null) {
                $process->positions()->sync($positionIds);
                $new = collect($positionIds)->map('intval')->sort()->values()->all();
                if ($new !== $oldPositions) {
                    $other[] = 'positions';
                }
            }
            if ($other !== []) {
                self::event($process, null, $by, 'metadatos_cambiados', data: [
                    'antes' => array_intersect_key([...$before, 'positions' => $oldPositions], array_flip($other)),
                    'despues' => array_intersect_key([...$process->only(self::PROCESS_FIELDS), 'positions' => $positionIds], array_flip($other)),
                ]);
            }
            if ($audiences !== null || in_array('visibility', $dirty, true)) {
                $old = $process->audiences()->get(['type', 'target_id', 'effect'])->map(fn ($a) => $a->only(['type', 'target_id', 'effect']))->all();
                if ($audiences !== null) {
                    $process->audiences()->delete();
                    foreach ($audiences as $a) {
                        if (! in_array($a['type'] ?? null, ProcessAudience::TYPES, true) || blank($a['target_id'] ?? null)) {
                            continue;
                        }
                        $effect = ($a['effect'] ?? 'allow') === 'deny' && $a['type'] === 'user' ? 'deny' : 'allow';
                        $process->audiences()->updateOrCreate(['type' => $a['type'], 'target_id' => (int) $a['target_id']], ['effect' => $effect, 'created_by' => $by->getKey()]);
                    }
                }
                $new = $process->audiences()->get(['type', 'target_id', 'effect'])->map(fn ($a) => $a->only(['type', 'target_id', 'effect']))->all();
                if ($new != $old || in_array('visibility', $dirty, true)) {
                    self::event($process, null, $by, 'audiencia_cambiada', data: ['antes' => ['visibility' => $before['visibility'], 'audiencia' => $old], 'despues' => ['visibility' => $process->visibility, 'audiencia' => $new]]);
                }
            }
        });
    }

    public static function submit(User $by, ProcessVersion $draft): void
    {
        self::moduleOn();
        self::allow($draft->status === 'borrador' && ProcessAccess::canEdit($by, $draft->process));
        if (($gaps = $draft->publicationGaps()) !== []) {
            throw ValidationException::withMessages(['version' => $gaps]);
        }
        $draft->forceFill(['status' => 'en_revision', 'submitted_by' => $by->getKey(), 'submitted_at' => now()])->save();
        self::event($draft->process, $draft, $by, 'enviado_revision');
    }

    /** Back to draft by whoever sent it, the owner or an administrator. */
    public static function withdraw(User $by, ProcessVersion $version): void
    {
        self::allow($version->status === 'en_revision' && ((string) $version->submitted_by === (string) $by->getKey() || ProcessAccess::canMaintain($by, $version->process)));
        $version->forceFill(['status' => 'borrador'])->save();
        self::event($version->process, $version, $by, 'retirado');
    }

    public static function sendBack(User $by, ProcessVersion $version, string $notes): void
    {
        self::allow(ProcessAccess::canReview($by, $version));
        if (blank($notes)) {
            throw ValidationException::withMessages(['notes' => __('procesos.errores.comentario')]);
        }
        $version->forceFill(['status' => 'borrador', 'review_notes' => trim($notes)])->save();
        self::event($version->process, $version, $by, 'devuelto', trim($notes));
    }

    /** Optional OK of a reviewer (not the author). The version stays «en_revision». */
    public static function markReviewed(User $by, ProcessVersion $version, ?string $note = null): void
    {
        self::allow(ProcessAccess::canReview($by, $version) && (string) $version->created_by !== (string) $by->getKey());
        $version->forceFill(['reviewed_by' => $by->getKey(), 'reviewed_at' => now()])->save();
        self::event($version->process, $version, $by, 'revisado', $note);
    }

    public static function publish(User $by, ProcessVersion $version, ?\DateTimeInterface $nextReviewOn = null): void
    {
        self::moduleOn();
        self::allow(ProcessAccess::canPublish($by, $version));
        if (($gaps = $version->publicationGaps()) !== []) {
            throw ValidationException::withMessages(['version' => $gaps]);
        }
        DB::transaction(function () use ($by, $version, $nextReviewOn): void {
            $process = Process::query()->lockForUpdate()->findOrFail($version->process_id);
            $replaced = $process->currentVersion;
            if ($replaced !== null && $replaced->status === 'publicada') {
                $replaced->forceFill(['status' => 'sustituida'])->save();
            }
            $nextReviewOn ??= now()->addMonths(ProcessSettings::forTeam($process->team_id)->default_review_months);
            $process->forceFill(['next_review_on' => $nextReviewOn->format('Y-m-d')]);
            $version->forceFill([
                'status' => 'publicada', 'number' => (int) $process->versions()->max('number') + 1,
                'approved_by' => $by->getKey(), 'published_at' => now(),
                'self_approved' => (string) $version->created_by === (string) $by->getKey(),
                'meta_snapshot' => [...$version->metaSnapshot(), 'next_review_on' => $nextReviewOn->format('Y-m-d')],
            ])->save();
            $process->forceFill([
                'status' => 'publicado', 'current_version_id' => $version->getKey(), 'last_reviewed_at' => now(),
                'archived_at' => null, 'archive_reason' => null,
            ])->save();
            self::event($process, $version, $by, 'publicado', data: ['number' => $version->number, 'self_approved' => $version->self_approved]);
            event(new ProcessVersionPublished($version->fresh(), $replaced?->fresh()));
        });
    }

    public static function archive(User $by, Process $process, string $reason): void
    {
        self::allow($process->status === 'publicado' && ProcessAccess::canMaintain($by, $process));
        if (blank($reason)) {
            throw ValidationException::withMessages(['reason' => __('procesos.errores.motivo')]);
        }
        if ($process->versions()->where('status', 'en_revision')->exists()) {
            throw ValidationException::withMessages(['reason' => __('procesos.errores.en_revision')]);
        }
        DB::transaction(function () use ($by, $process, $reason): void {
            $version = $process->currentVersion;
            $version?->forceFill(['status' => 'archivada'])->save();
            $process->forceFill(['status' => 'archivado', 'archived_at' => now(), 'archive_reason' => trim($reason)])->save();
            self::event($process, $version, $by, 'archivado', trim($reason));
            event(new ProcessArchived($process->fresh(), $version?->fresh()));
        });
    }

    public static function confirmStillValid(User $by, Process $process, \DateTimeInterface $nextReviewOn): void
    {
        self::allow($process->status === 'publicado' && ProcessAccess::canMaintain($by, $process));
        $before = $process->next_review_on?->toDateString();
        $process->forceFill(['last_reviewed_at' => now(), 'next_review_on' => $nextReviewOn->format('Y-m-d')])->save();
        self::event($process, $process->currentVersion, $by, 'revision_confirmada', data: ['antes' => $before, 'despues' => $nextReviewOn->format('Y-m-d')]);
    }

    /** Deletes a draft that was never published (author or administrator). A process without versions goes too. */
    public static function deleteDraft(User $by, ProcessVersion $draft): void
    {
        $process = $draft->process;
        self::allow($draft->status === 'borrador' && $draft->number === null
            && ((string) $draft->created_by === (string) $by->getKey() || ProcessAccess::isAdmin($by, $process->team_id)));
        DB::transaction(function () use ($by, $draft, $process): void {
            $draft->attachments()->get()->each->delete();
            $draft->delete();
            if (! $process->versions()->exists()) {
                ProcessMapEntry::query()->where('process_id', $process->getKey())->update(['process_id' => null]);
                $process->delete();

                return;
            }
            self::event($process, null, $by, 'borrador_eliminado');
        });
    }

    /**
     * Replaces attachment ids in steps[].blocks, blocks and tools.
     *
     * @param  array{steps?: ?array, blocks?: ?array, tools?: ?array}  $content
     * @param  array<int, int>  $map  old id => new id
     * @return array{steps?: ?array, blocks?: ?array, tools?: ?array}
     */
    public static function remapAttachments(array $content, array $map): array
    {
        $blocks = fn (?array $list): ?array => $list === null ? null : array_map(function (array $b) use ($map): array {
            if (isset($b['data']['attachment_id'], $map[(int) $b['data']['attachment_id']])) {
                $b['data']['attachment_id'] = $map[(int) $b['data']['attachment_id']];
            }

            return $b;
        }, $list);
        if (array_key_exists('blocks', $content)) {
            $content['blocks'] = $blocks($content['blocks']);
        }
        if (array_key_exists('steps', $content) && $content['steps'] !== null) {
            $content['steps'] = array_map(fn (array $s): array => [...$s, 'blocks' => $blocks($s['blocks'] ?? [])], $content['steps']);
        }
        if (array_key_exists('tools', $content) && $content['tools'] !== null) {
            $content['tools'] = array_map(fn (array $t): array => isset($t['attachment_id'], $map[(int) $t['attachment_id']]) ? [...$t, 'attachment_id' => $map[(int) $t['attachment_id']]] : $t, $content['tools']);
        }

        return $content;
    }

    /** Stable key for steps and decisions (lower-case ULID). */
    public static function newKey(): string
    {
        return strtolower((string) Str::ulid());
    }

    public static function event(Process $process, ?ProcessVersion $version, ?User $by, string $type, ?string $note = null, ?array $data = null): ProcessEvent
    {
        return ProcessEvent::query()->create([
            'team_id' => $process->team_id, 'process_id' => $process->getKey(), 'process_version_id' => $version?->getKey(),
            'user_id' => $by?->getKey(), 'type' => $type, 'note' => $note, 'data' => $data, 'created_at' => now(),
        ]);
    }

    /** Map key when the entry is free; otherwise the next free number of the area (below the 9x demo range). */
    private static function nextCode(int|string $teamId, Area $area, ?ProcessMapEntry $entry): string
    {
        if ($entry !== null && ! Process::query()->where('team_id', $teamId)->where('code', $entry->key)->exists()) {
            return $entry->key;
        }
        $used = Process::query()->where('team_id', $teamId)->where('code', 'like', $area->code.'-%')->pluck('code')
            ->merge(ProcessMapEntry::query()->where('team_id', $teamId)->where('key', 'like', $area->code.'-%')->pluck('key'))
            ->map(fn (string $c): int => (int) substr($c, strlen($area->code) + 1))->filter(fn (int $n): bool => $n < 90);
        $n = (int) $used->max() + 1;
        while (Process::query()->where('team_id', $teamId)->where('code', sprintf('%s-%02d', $area->code, $n))->exists()) {
            $n++;
        }

        return sprintf('%s-%02d', $area->code, $n);
    }

    private static function allow(bool $allowed): void
    {
        if (! $allowed) {
            throw new AuthorizationException(__('procesos.errores.permiso'));
        }
    }

    /** A new operation (create, revise, send, publish) needs the «procesos» module on; reading never does. */
    private static function moduleOn(): void
    {
        if (class_exists(\App\Modules\ClartoModules::class) && in_array('procesos', \App\Modules\ClartoModules::keys(), true)) {
            \App\Modules\ClartoModules::ensureEnabled('procesos');
        }
    }
}
