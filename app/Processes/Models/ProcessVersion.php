<?php

declare(strict_types=1);

namespace App\Processes\Models;

use App\Models\User;
use App\Processes\ProcessAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use LogicException;

/**
 * The versioned content of a process. borrador → en_revision → publicada → sustituida | archivada.
 * A publicada / sustituida / archivada version never changes its content: only status (and timestamps) may change.
 * JSON shapes: orquestacion/procesos/modelo-datos.md §3.4–3.5.
 */
final class ProcessVersion extends Model
{
    public const STATUSES = ['borrador', 'en_revision', 'publicada', 'sustituida', 'archivada'];

    public const OPEN = ['borrador', 'en_revision'];

    public const FROZEN = ['publicada', 'sustituida', 'archivada'];

    /** Content fields (copied to a new draft, compared by the diff, frozen once published). */
    public const CONTENT = ['title', 'summary', 'objective', 'expected_result', 'trigger', 'requirements', 'required_data', 'steps',
        'decisions', 'no_decisions', 'exceptions', 'escalation', 'tools', 'evidences', 'deadlines', 'kpis', 'related', 'blocks',
        'diagram_mermaid', 'keywords', 'change_summary'];

    public const BLOCK_TYPES = ['text', 'image', 'video', 'file', 'link', 'callout', 'diagram'];

    protected $table = 'clarto_process_versions';

    protected $guarded = ['id'];

    protected $casts = [
        'steps' => 'array', 'decisions' => 'array', 'exceptions' => 'array', 'tools' => 'array', 'kpis' => 'array', 'related' => 'array',
        'blocks' => 'array', 'keywords' => 'array', 'meta_snapshot' => 'array', 'no_decisions' => 'boolean', 'ai_assisted' => 'boolean',
        'self_approved' => 'boolean', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime', 'published_at' => 'datetime', 'number' => 'integer',
    ];

    /** @var array<int, string>|null */
    private ?array $positionNames = null;

    protected static function booted(): void
    {
        self::saving(function (self $version): void {
            if (! $version->exists || $version->isDirty(self::CONTENT)) {
                $version->search_text = self::normalize($version->title.' '.$version->plainText().' '.implode(' ', (array) $version->keywords));
            }
        });
        self::updating(function (self $version): void {
            if (in_array($version->getOriginal('status'), self::FROZEN, true)
                && array_diff(array_keys($version->getDirty()), ['status', 'updated_at']) !== []) {
                throw new LogicException('A published version cannot be modified.');
            }
        });
    }

    /** @return BelongsTo<Process, $this> */
    public function process(): BelongsTo
    {
        return $this->belongsTo(Process::class);
    }

    /** @return HasMany<ProcessAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(ProcessAttachment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return BelongsTo<self, $this> */
    public function basedOn(): BelongsTo
    {
        return $this->belongsTo(self::class, 'based_on_version_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    /** Lower case, without accents: what the search compares. */
    public static function normalize(?string $text): string
    {
        return Str::lower(Str::ascii((string) $text));
    }

    /** Markdown to plain text (no HTML, no markdown syntax). */
    public static function plain(?string $markdown): string
    {
        if (blank($markdown)) {
            return '';
        }
        $html = Str::markdown((string) $markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false]);

        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace(['</p>', '</li>', '<br />'], ["</p>\n", "</li>\n", "\n"], $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    public function positionName(?int $positionId): ?string
    {
        if ($positionId === null) {
            return null;
        }
        $this->positionNames ??= Position::query()->where('team_id', $this->team_id)->pluck('name', 'id')->all();

        return $this->positionNames[$positionId] ?? null;
    }

    /** Who acts in a step: the position's name, or the outside actor («Inquilino»). */
    public function stepActor(array $step): ?string
    {
        return $this->positionName(isset($step['actor_position_id']) ? (int) $step['actor_position_id'] : null) ?: ($step['actor_label'] ?? null);
    }

    /** @return array<string, int> step key => 1-based number */
    public function stepNumbers(): array
    {
        $numbers = [];
        foreach (array_values((array) $this->steps) as $i => $step) {
            $numbers[(string) ($step['key'] ?? '')] = $i + 1;
        }

        return $numbers;
    }

    /** «Paso 3», «Fin», «Proceso ALQ-09» for a decision option's goto. */
    public function gotoLabel(?string $goto): string
    {
        $goto = (string) $goto;
        if ($goto === 'end' || $goto === '') {
            return __('procesos.ver.fin');
        }
        if (str_starts_with($goto, 'step:')) {
            $n = $this->stepNumbers()[substr($goto, 5)] ?? null;

            return $n ? __('procesos.ver.ir_paso', ['n' => $n]) : '—';
        }

        return __('procesos.ver.ir_proceso', ['code' => substr($goto, 8)]);
    }

    /** Plain text of a list of blocks (texts, callouts, captions and labels; never file contents or URLs). */
    public static function blocksText(array $blocks): string
    {
        $parts = [];
        foreach ($blocks as $block) {
            $d = (array) ($block['data'] ?? []);
            $parts[] = match ($block['type'] ?? null) {
                'text', 'callout' => self::plain($d['markdown'] ?? null),
                'image' => trim(($d['caption'] ?? '').' '.($d['alt'] ?? '')),
                'video', 'diagram' => (string) ($d['caption'] ?? ''),
                'file', 'link' => (string) ($d['label'] ?? ''),
                default => '',
            };
        }

        return trim(implode(' ', array_filter($parts)));
    }

    /**
     * Same structure for the page index and for the assistant's chunks: the anchors always exist on the page.
     *
     * @return list<array{anchor: string, heading: string, text: string}>
     */
    public function sections(): array
    {
        $join = fn (array $parts): string => trim(implode(' · ', array_filter(array_map('trim', $parts), fn ($p) => $p !== '')));
        $labelled = fn (string $label, ?string $md): string => blank($md) ? '' : $label.': '.self::plain($md);
        $sections = [];
        $add = function (string $anchor, string $heading, string $text) use (&$sections): void {
            if ($text !== '') {
                $sections[] = ['anchor' => $anchor, 'heading' => $heading, 'text' => $text];
            }
        };

        $add('resumen', __('procesos.secciones.resumen'), $join([(string) $this->summary, $labelled(__('procesos.campos.objective'), $this->objective), $labelled(__('procesos.campos.expected_result'), $this->expected_result)]));
        $add('inicio', __('procesos.secciones.inicio'), $join([$labelled(__('procesos.campos.trigger'), $this->trigger), $labelled(__('procesos.campos.requirements'), $this->requirements), $labelled(__('procesos.campos.required_data'), $this->required_data)]));

        foreach (array_values((array) $this->steps) as $i => $step) {
            $heading = __('procesos.ver.paso_n', ['n' => $i + 1]).'. '.($step['title'] ?? '');
            $add('paso-'.($step['key'] ?? $i), $heading, $join([
                $heading,
                ($actor = $this->stepActor($step)) ? __('procesos.campos.actor').': '.$actor : '',
                self::plain($step['action'] ?? null),
                $labelled(__('procesos.campos.done_when'), $step['done_when'] ?? null),
                $labelled(__('procesos.campos.evidence'), $step['evidence'] ?? null),
                filled($step['deadline'] ?? null) ? __('procesos.campos.deadline').': '.$step['deadline'] : '',
                self::blocksText((array) ($step['blocks'] ?? [])),
            ]));
        }

        foreach ((array) $this->decisions as $decision) {
            $options = array_map(fn (array $o): string => ($o['label'] ?? '').' → '.$this->gotoLabel($o['goto'] ?? null).(filled($o['note'] ?? null) ? ' ('.self::plain($o['note']).')' : ''), (array) ($decision['options'] ?? []));
            $decider = $this->positionName(isset($decision['decider_position_id']) ? (int) $decision['decider_position_id'] : null);
            $add('decision-'.($decision['key'] ?? ''), (string) ($decision['question'] ?? __('procesos.secciones.decision')), $join([
                (string) ($decision['question'] ?? ''), $decider ? __('procesos.campos.decider').': '.$decider : '', implode('; ', $options),
            ]));
        }

        $exceptions = array_map(fn (array $e): string => ($e['case'] ?? '').': '.self::plain($e['handling'] ?? null).(filled($e['escalate_to'] ?? null) ? ' ('.__('procesos.campos.escalate_to').': '.$e['escalate_to'].')' : ''), (array) $this->exceptions);
        $add('excepciones', __('procesos.secciones.excepciones'), $join([...$exceptions, $labelled(__('procesos.campos.escalation'), $this->escalation)]));

        $tools = array_map(fn (array $t): string => ($t['name'] ?? '').(filled($t['note'] ?? null) ? ' ('.$t['note'].')' : ''), (array) $this->tools);
        $add('herramientas', __('procesos.secciones.herramientas'), $join([...$tools, $labelled(__('procesos.campos.evidences'), $this->evidences)]));

        $kpis = array_map(fn (array $k): string => trim(($k['name'] ?? '').': '.($k['target'] ?? '').' · '.($k['frequency'] ?? '').' · '.($k['source'] ?? ''), ' :·'), (array) $this->kpis);
        $add('plazos', __('procesos.secciones.plazos'), $join([$labelled(__('procesos.campos.deadlines'), $this->deadlines), ...$kpis]));

        $add('diagrama', __('procesos.secciones.diagrama'), trim((string) $this->diagram_mermaid));

        foreach (array_values((array) $this->blocks) as $i => $block) {
            $add('contenido-'.($i + 1), __('procesos.secciones.contenido'), self::blocksText([$block]).($block['type'] === 'diagram' && filled($block['data']['mermaid'] ?? null) ? ' '.$block['data']['mermaid'] : ''));
        }

        return $sections;
    }

    public function plainText(): string
    {
        return implode("\n", array_map(fn (array $s): string => $s['heading'].': '.$s['text'], $this->sections()));
    }

    /**
     * What is missing to send to review or publish (diseno.md §3.2). Empty = complete.
     *
     * @return list<string>
     */
    public function publicationGaps(): array
    {
        $process = $this->process;
        $full = $process?->kind === 'completo';
        $gaps = [];
        $need = function (bool $ok, string $key, array $params = []) use (&$gaps): void {
            if (! $ok) {
                $gaps[] = __("procesos.faltas.{$key}", $params);
            }
        };

        $need(filled($this->title), 'title');
        $need($process !== null && filled($process->area_id) && in_array($process->category, Process::CATEGORIES, true) && in_array($process->kind, Process::KINDS, true) && in_array($process->business_line, Process::BUSINESS_LINES, true), 'clasificacion');
        $need($process !== null && ! $process->ownerMissing(), 'owner');
        $need($process !== null && filled($process->approver_user_id), 'approver');
        $need($process !== null && $process->positions()->exists(), 'positions');
        $need(filled($this->summary), 'summary');
        $need(filled($this->objective), 'objective');
        $need(filled($this->expected_result), 'expected_result');
        $need(filled($this->trigger), 'trigger');

        $steps = array_values((array) $this->steps);
        $need(count($steps) >= ($full ? 2 : 1), 'steps', ['n' => $full ? 2 : 1]);
        foreach ($steps as $i => $step) {
            $need(filled($step['title'] ?? null) && (filled($step['actor_position_id'] ?? null) || filled($step['actor_label'] ?? null)) && filled($step['action'] ?? null) && filled($step['done_when'] ?? null), 'step', ['n' => $i + 1]);
        }
        $keys = array_keys($this->stepNumbers());
        foreach ((array) $this->decisions as $decision) {
            foreach ((array) ($decision['options'] ?? []) as $option) {
                $goto = (string) ($option['goto'] ?? '');
                $need(filled($decision['question'] ?? null) && filled($option['label'] ?? null) && ($goto === 'end' || str_starts_with($goto, 'process:') || in_array(substr($goto, 5), $keys, true)), 'decision', ['q' => $decision['question'] ?? '?']);
            }
        }
        foreach ([...(array) $this->blocks, ...array_merge(...array_map(fn ($s) => (array) ($s['blocks'] ?? []), $steps ?: [[]]))] as $block) {
            if (($block['type'] ?? null) === 'image') {
                $need(filled($block['data']['attachment_id'] ?? null) && filled($block['data']['alt'] ?? null), 'image_alt');
            }
        }

        if ($full) {
            $need(filled($this->requirements) && filled($this->required_data), 'requirements');
            $need(count((array) $this->decisions) > 0 || $this->no_decisions, 'decisions');
            $need(filled($this->escalation), 'escalation');
            $need(count((array) $this->tools) > 0, 'tools');
            $need(filled($this->evidences), 'evidences');
            $need(filled($this->deadlines), 'deadlines');
            $need(count((array) $this->kpis) > 0, 'kpis');
        }
        $need(filled($this->change_summary), 'change_summary');

        return array_values(array_unique($gaps));
    }

    /** Recommendations that do not block publishing. @return list<string> */
    public function publicationWarnings(): array
    {
        return $this->process?->kind === 'completo' && blank($this->diagram_mermaid) ? [__('procesos.faltas.diagram')] : [];
    }

    /** Snapshot of the process metadata at publication (modelo-datos.md §3.2). @return array<string, mixed> */
    public function metaSnapshot(): array
    {
        $p = $this->process()->with(['area', 'owner', 'approver', 'positions'])->firstOrFail();

        return [
            'area' => ['code' => $p->area?->code, 'name' => $p->area?->name],
            'category' => $p->category, 'business_line' => $p->business_line, 'kind' => $p->kind,
            'owner' => $p->owner ? ['id' => $p->owner->getKey(), 'name' => $p->owner->name] : null,
            'approver' => $p->approver ? ['id' => $p->approver->getKey(), 'name' => $p->approver->name] : null,
            'positions' => $p->positions->map(fn (Position $x): array => ['id' => $x->getKey(), 'key' => $x->key, 'name' => $x->name])->values()->all(),
            'visibility' => $p->visibility, 'next_review_on' => $p->next_review_on?->toDateString(),
        ];
    }

    /** Attachment ids referenced by the content (blocks, step blocks and tools). @return list<int> */
    public function referencedAttachmentIds(): array
    {
        $ids = [];
        foreach ([...(array) $this->blocks, ...array_merge([], ...array_map(fn ($s) => (array) ($s['blocks'] ?? []), (array) $this->steps))] as $block) {
            $ids[] = $block['data']['attachment_id'] ?? null;
        }
        foreach ((array) $this->tools as $tool) {
            $ids[] = $tool['attachment_id'] ?? null;
        }

        return array_values(array_unique(array_map('intval', array_filter($ids))));
    }

    public function canBeSeenBy(?User $user): bool
    {
        return ProcessAccess::canView($user, $this);
    }
}
