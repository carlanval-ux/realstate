<?php

declare(strict_types=1);

namespace App\Processes\Recommendations;

use App\Ai\AiClient;
use App\Models\User;
use App\Processes\Assistant\AssistantQuery;
use App\Processes\Assistant\AssistantSettings;
use App\Processes\Assistant\Text;
use App\Processes\Models\Process;
use App\Processes\Models\ProcessVersion;
use App\Processes\ProcessAccess;
use App\Processes\Processes;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Recomendaciones de documentación» (diseno.md §9): the process map against what is registered (by entry, area,
 * business line and position), the assistant's questions without a useful answer and the team's doubts. Each one has
 * a reason with figures, a priority and a suggested owner. Pending ones are recalculated; accepted, dismissed and
 * converted ones are never recreated (same fingerprint).
 */
final class Recommendations
{
    /** Window for questions and doubts. */
    public const DAYS = 90;

    /** Title similarity from which an existing process is shown as a possible duplicate. */
    public const SIMILAR = 0.34;

    private const AUTO_TYPES = ['mapa', 'puesto', 'area', 'sector', 'preguntas', 'dudas'];

    /** @return int pending recommendations after the run */
    public static function generate(int|string $teamId): int
    {
        $wanted = collect([...self::fromMap($teamId), ...self::fromPositions($teamId), ...self::fromQuestions($teamId), ...self::fromDoubts($teamId)])
            ->keyBy(fn (array $r): string => hash('sha256', $r['key']));

        DB::transaction(function () use ($teamId, $wanted): void {
            $existing = Recommendation::query()->where('team_id', $teamId)->get()->keyBy('fingerprint');
            foreach ($wanted as $fingerprint => $attributes) {
                unset($attributes['key']);
                $current = $existing->get($fingerprint);
                if ($current === null) {
                    Recommendation::query()->create($attributes + ['team_id' => $teamId, 'fingerprint' => $fingerprint, 'status' => 'pendiente']);
                } elseif ($current->status === 'pendiente') {
                    $current->fill($attributes)->save();
                }
            }
            // A pending automatic recommendation whose gap is gone (process registered, doubts answered…) disappears.
            Recommendation::query()->where('team_id', $teamId)->where('status', 'pendiente')->whereIn('type', self::AUTO_TYPES)
                ->whereNotIn('fingerprint', $wanted->keys()->all())->delete();
        });

        return Recommendation::query()->where('team_id', $teamId)->where('status', 'pendiente')->count();
    }

    public static function accept(User $by, Recommendation $recommendation): void
    {
        self::authorize($by, $recommendation);
        $recommendation->forceFill(['status' => 'aceptada', 'decided_by' => $by->getKey(), 'decided_at' => now()])->save();
    }

    public static function dismiss(User $by, Recommendation $recommendation, string $reason): void
    {
        self::authorize($by, $recommendation);
        if (mb_strlen(trim($reason)) < 3) {
            throw ValidationException::withMessages(['decision_reason' => __('asistente.errores.motivo')]);
        }
        $recommendation->forceFill(['status' => 'descartada', 'decision_reason' => trim($reason), 'decided_by' => $by->getKey(), 'decided_at' => now()])->save();
    }

    /**
     * Opens a draft from the recommendation's template (or a new revision of its process). Similar processes are
     * checked first: if there are any, $similarReviewed must confirm that the person looked at them.
     */
    public static function openDraft(User $by, Recommendation $recommendation, bool $similarReviewed = false): ProcessVersion
    {
        self::authorize($by, $recommendation);
        if (! in_array($recommendation->status, ['pendiente', 'aceptada'], true)) {
            throw ValidationException::withMessages(['status' => __('asistente.errores.ya_decidida')]);
        }
        $entry = $recommendation->map_entry_id ? DB::table('clarto_process_map_entries')->find($recommendation->map_entry_id) : null;
        $title = (string) ($entry->title ?? $recommendation->title);
        if ($recommendation->process_id === null) {
            $similar = self::similar($recommendation->team_id, $title);
            $recommendation->forceFill(['similar' => $similar])->save();
            if ($similar !== [] && ! $similarReviewed) {
                throw ValidationException::withMessages(['similar' => __('asistente.errores.similares', ['lista' => collect($similar)->map(fn (array $s): string => $s['code'].' '.$s['title'])->implode('; ')])]);
            }
        }

        return DB::transaction(function () use ($by, $recommendation, $entry, $title): ProcessVersion {
            $blocks = filled($recommendation->body) ? [['type' => 'callout', 'data' => ['tone' => 'aviso',
                'markdown' => '**'.__($recommendation->ai_generated ? 'asistente.recomendaciones.propuesta_ia' : 'asistente.recomendaciones.propuesta')."**\n\n".$recommendation->body]]] : [];

            if ($recommendation->process_id !== null) {
                $draft = Processes::startRevision($by, Process::query()->findOrFail($recommendation->process_id));
                if ($blocks !== []) {
                    $draft->forceFill(['blocks' => [...$blocks, ...(array) $draft->blocks], 'ai_assisted' => $draft->ai_assisted || $recommendation->ai_generated])->save();
                }
            } else {
                $areaCode = $entry->area_code ?? ($recommendation->evidence['area_code'] ?? null);
                $areaId = $areaCode !== null
                    ? DB::table('clarto_areas')->where('team_id', $recommendation->team_id)->where('code', $areaCode)->value('id')
                    : DB::table('clarto_positions')->where('id', $recommendation->suggested_position_id)->value('area_id');
                $draft = Processes::createDraft($by, $recommendation->team_id, array_filter([
                    'title' => mb_substr($title, 0, 160),
                    'area_id' => $areaId,
                    'category' => $entry->category ?? null,
                    'business_line' => $entry->business_line ?? null,
                    'kind' => $entry->kind ?? null,
                    'owner_user_id' => $recommendation->suggested_user_id,
                    'map_entry_id' => $recommendation->map_entry_id,
                    'blocks' => $blocks ?: null,
                ], fn ($v): bool => $v !== null) + ['ai_assisted' => (bool) $recommendation->ai_generated],
                    $recommendation->template_key ?? $entry->template_key ?? 'instruccion_breve');
            }

            $recommendation->forceFill(['status' => 'convertida', 'draft_version_id' => $draft->getKey(), 'decided_by' => $by->getKey(), 'decided_at' => now()])->save();

            return $draft;
        });
    }

    /**
     * Asks the AI for an outline of the missing procedure. Only the recommendation's own metadata is sent (title,
     * area, trigger, screens; no people, no clients). The text is stored as a «propuesta generada por IA».
     */
    public static function propose(User $by, Recommendation $recommendation): void
    {
        self::authorize($by, $recommendation);
        $settings = AssistantSettings::forTeam($recommendation->team_id);
        if (! AssistantSettings::activeFor($recommendation->team_id)) {
            throw ValidationException::withMessages(['ai' => __('asistente.respuestas.desactivado')]);
        }
        $entry = $recommendation->map_entry_id ? DB::table('clarto_process_map_entries')->find($recommendation->map_entry_id) : null;
        $facts = array_filter([
            'Procedimiento' => $entry->title ?? $recommendation->title,
            'Área' => $entry->area_code ?? null,
            'Tipo' => $entry->category ?? null,
            'Cuándo empieza' => $entry->trigger ?? null,
            'Pantallas o módulos' => $entry ? implode(', ', (array) json_decode((string) $entry->liberu_refs, true)) : null,
            'Motivo' => $recommendation->reason,
        ]);
        $user = Text::mask(collect($facts)->map(fn ($v, $k): string => "{$k}: {$v}")->implode("\n"));
        $reply = AiClient::make()->complete('writing',
            'Eres consultor de procesos de CLARTO (inmobiliaria, alquileres, consultoría y gestoría en la Comunitat Valenciana). Propón en español un esquema breve del procedimiento: objetivo en una línea y de 4 a 8 pasos numerados con quién actúa, qué hace y cómo comprueba que terminó. No inventes plazos legales ni importes: si hacen falta, escribe «(a confirmar)». Es solo una propuesta para que la empresa la revise.',
            $user, $settings->model());
        $recommendation->forceFill(['body' => Text::mask(trim($reply['content'])), 'ai_generated' => true])->save();
    }

    /**
     * Registered processes (not archived) whose title resembles $title, most similar first.
     *
     * @return list<array{process_id: int, code: string, title: string, score: float}>
     */
    public static function similar(int|string $teamId, string $title, ?int $exceptProcessId = null): array
    {
        return DB::table('clarto_process_versions as v')->join('clarto_processes as p', 'p.id', '=', 'v.process_id')
            ->where('p.team_id', $teamId)->where('p.status', '!=', 'archivado')
            ->when($exceptProcessId !== null, fn ($q) => $q->where('p.id', '!=', $exceptProcessId))
            ->select('p.id', 'p.code', 'v.title')->get()
            ->map(fn (object $r): array => ['process_id' => (int) $r->id, 'code' => (string) $r->code, 'title' => (string) $r->title, 'score' => round(Text::similarity($title, (string) $r->title), 2)])
            ->filter(fn (array $s): bool => $s['score'] >= self::SIMILAR)
            ->sortByDesc('score')->unique('process_id')->take(5)->values()->all();
    }

    /** Map entries without a registered process, plus the coverage of each area and business line. @return list<array<string, mixed>> */
    private static function fromMap(int|string $teamId): array
    {
        $entries = DB::table('clarto_process_map_entries')->where('team_id', $teamId)->where('status', '!=', 'descartado')->orderBy('priority')->orderBy('key')->get();
        $covering = DB::table('clarto_processes')->where('team_id', $teamId)->where('status', '!=', 'archivado')->get(['id', 'code', 'map_entry_id', 'status']);
        $published = fn (object $e): bool => $covering->contains(fn (object $p): bool => $p->status === 'publicado' && ((int) $p->map_entry_id === (int) $e->id || $p->code === $e->key || (int) $p->id === (int) $e->process_id));
        $registered = fn (object $e): bool => $covering->contains(fn (object $p): bool => (int) $p->map_entry_id === (int) $e->id || $p->code === $e->key || (int) $p->id === (int) $e->process_id);

        $out = [];
        foreach ($entries->reject($registered) as $e) {
            [$positionId, $userId] = self::ownerOf($teamId, $e->owner_position_key);
            $out[] = [
                'key' => "mapa:{$e->key}", 'type' => 'mapa', 'priority' => $e->status === 'a_validar' ? 3 : (int) $e->priority,
                'title' => mb_substr("{$e->key} · {$e->title}", 0, 200),
                'reason' => trim(__('asistente.recomendaciones.mapa_motivo', ['key' => $e->key, 'p' => __('asistente.prioridades.'.$e->priority)]).' '.($e->priority_reason ?? '').($e->status === 'a_validar' ? ' '.__('asistente.recomendaciones.a_validar') : '')),
                'map_entry_id' => $e->id, 'template_key' => $e->template_key, 'suggested_position_id' => $positionId, 'suggested_user_id' => $userId,
                'similar' => self::similar($teamId, (string) $e->title),
            ];
        }

        $areas = DB::table('clarto_areas')->where('team_id', $teamId)->get()->keyBy('code');
        foreach (['area' => 'area_code', 'sector' => 'business_line'] as $type => $column) {
            foreach ($entries->groupBy($column) as $group => $items) {
                $total = $items->count();
                $done = $items->filter($published)->count();
                $highMissing = $items->reject($published)->where('priority', 1)->count();
                if ($total < 3 || $done / $total >= 0.5) {
                    continue;
                }
                $label = $type === 'area' ? $group.' · '.($areas[$group]->name ?? $group) : __('asistente.sectores.'.$group);
                $out[] = [
                    'key' => "{$type}:{$group}", 'type' => $type, 'priority' => $highMissing >= 3 ? 1 : 2,
                    'title' => mb_substr(__("asistente.recomendaciones.{$type}_titulo", ['x' => $label]), 0, 200),
                    'reason' => __('asistente.recomendaciones.cobertura_motivo', ['done' => $done, 'total' => $total, 'pct' => (int) round(100 * $done / $total), 'high' => $highMissing]),
                    'suggested_user_id' => $type === 'area' ? ($areas[$group]->manager_user_id ?? null) : null,
                    'evidence' => ['pending_keys' => $items->reject($published)->pluck('key')->values()->all()] + ($type === 'area' ? ['area_code' => $group] : []),
                ];
            }
        }

        return $out;
    }

    /** Positions with people and not a single published procedure to execute. @return list<array<string, mixed>> */
    private static function fromPositions(int|string $teamId): array
    {
        $today = now()->toDateString();
        $holders = DB::table('clarto_position_user')->where('team_id', $teamId)
            ->where(fn ($q) => $q->whereNull('starts_on')->orWhere('starts_on', '<=', $today))
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $today))
            ->selectRaw('position_id, count(distinct user_id) as n')->groupBy('position_id')->pluck('n', 'position_id');
        $withProcess = DB::table('clarto_process_positions as pp')->join('clarto_processes as p', 'p.id', '=', 'pp.process_id')
            ->where('p.team_id', $teamId)->where('p.status', 'publicado')->distinct()->pluck('pp.position_id')->map(fn ($id): int => (int) $id)->all();
        $mapGaps = DB::table('clarto_process_map_entries')->where('team_id', $teamId)->where('status', '!=', 'descartado')->whereNull('process_id')->pluck('executing_position_keys');

        $out = [];
        foreach (DB::table('clarto_positions')->where('team_id', $teamId)->where('active', true)->get() as $position) {
            $people = (int) ($holders[$position->id] ?? 0);
            if ($people === 0 || in_array((int) $position->id, $withProcess, true)) {
                continue;
            }
            $expected = $mapGaps->filter(fn ($keys): bool => in_array($position->key, (array) json_decode((string) $keys, true), true))->count();
            $out[] = [
                'key' => "puesto:{$position->key}", 'type' => 'puesto', 'priority' => $people >= 2 || $expected >= 3 ? 1 : 2,
                'title' => mb_substr(__('asistente.recomendaciones.puesto_titulo', ['x' => $position->name]), 0, 200),
                'reason' => __('asistente.recomendaciones.puesto_motivo', ['n' => $people, 'm' => $expected]),
                'suggested_position_id' => $position->id,
                'suggested_user_id' => DB::table('clarto_areas')->where('id', $position->area_id)->value('manager_user_id'),
            ];
        }

        return $out;
    }

    /** Recurring terms of the assistant's questions without a useful answer. @return list<array<string, mixed>> */
    private static function fromQuestions(int|string $teamId): array
    {
        $queries = AssistantQuery::query()->where('team_id', $teamId)->unanswered()->where('created_at', '>=', now()->subDays(self::DAYS))->get(['id', 'question']);

        return self::byTerm($teamId, 'preguntas', $queries->map(fn (AssistantQuery $q): array => ['id' => $q->id, 'text' => $q->question]));
    }

    /** Doubts sent with «Comunicar una duda o mejora»: per process, and recurring terms of those with no process. @return list<array<string, mixed>> */
    private static function fromDoubts(int|string $teamId): array
    {
        $doubts = DB::table('clarto_process_feedback as f')->join('clarto_improvement_requests as r', 'r.id', '=', 'f.improvement_request_id')
            ->where('f.team_id', $teamId)->where('f.created_at', '>=', now()->subDays(self::DAYS))
            ->get(['f.id', 'f.process_id', 'f.source', 'r.title', 'r.description']);

        $out = [];
        foreach ($doubts->whereNotNull('process_id')->groupBy('process_id') as $processId => $items) {
            $process = Process::query()->find($processId);
            if ($process === null || $process->status === 'archivado' || $items->count() < 2) {
                continue;
            }
            $out[] = [
                'key' => "dudas:proceso:{$processId}", 'type' => 'dudas', 'priority' => $items->count() >= 5 ? 1 : 2,
                'title' => mb_substr(__('asistente.recomendaciones.dudas_proceso_titulo', ['code' => $process->code]), 0, 200),
                'reason' => __('asistente.recomendaciones.dudas_proceso_motivo', ['n' => $items->count(), 'd' => self::DAYS]),
                'process_id' => $process->getKey(), 'suggested_user_id' => $process->owner_user_id,
                'evidence' => ['feedback_ids' => $items->pluck('id')->values()->all()],
            ];
        }
        // Doubts from the assistant are already counted as its questions.
        $loose = $doubts->whereNull('process_id')->where('source', '!=', 'asistente')
            ->map(fn (object $d): array => ['id' => $d->id, 'text' => $d->title.' '.$d->description, 'example' => (string) $d->description]);

        return [...$out, ...self::byTerm($teamId, 'dudas', $loose)];
    }

    /**
     * Groups the texts by topic, greedily: the term found in most texts (twice the weight when no published procedure
     * of the company mentions it: that is the gap) takes every text containing it; repeat while a term joins at least
     * two texts, five topics at most. Each topic is linked to the map entry whose title contains its term, if any.
     *
     * ponytail: term clustering, not semantic; good enough for tens of questions a month.
     *
     * @param  Collection<int, array{id: int, text: string, example?: string}>  $texts
     * @return list<array<string, mixed>>
     */
    private static function byTerm(int|string $teamId, string $type, Collection $texts): array
    {
        $documented = ' '.DB::table('clarto_process_versions as v')->join('clarto_processes as p', 'p.current_version_id', '=', 'v.id')
            ->where('p.team_id', $teamId)->where('p.status', 'publicado')->pluck('v.search_text')->implode(' ');
        $terms = $texts->mapWithKeys(fn (array $t): array => [$t['id'] => Text::terms($t['text'], 30)])->all();
        $groups = [];
        while (count($groups) < 5) {
            $count = array_count_values(array_merge(...array_values($terms ?: [[]])));
            $count = array_filter($count, fn (int $n): bool => $n >= 2);
            if ($count === []) {
                break;
            }
            $score = fn (string $term): float => $count[$term] * (str_contains($documented, ' '.$term) ? 1 : 2) + mb_strlen($term) / 100;
            $best = (string) collect(array_keys($count))->sortByDesc($score)->first();
            $ids = array_keys(array_filter($terms, fn (array $list): bool => in_array($best, $list, true)));
            $groups[$best] = $ids;
            $terms = array_diff_key($terms, array_flip($ids));
        }

        $entries = DB::table('clarto_process_map_entries')->where('team_id', $teamId)->where('status', '!=', 'descartado')->get();
        $out = [];
        foreach ($groups as $term => $list) {
            $term = (string) $term;
            $entry = $entries->first(fn (object $e): bool => Text::coverage([$term], (string) $e->title) > 0);
            [$positionId, $userId] = $entry ? self::ownerOf($teamId, $entry->owner_position_key) : [null, null];
            $first = $texts->firstWhere('id', $list[0]) ?? [];
            $example = (string) ($first['example'] ?? $first['text'] ?? '');
            $out[] = [
                'key' => "{$type}:{$term}", 'type' => $type, 'priority' => count($list) >= 5 ? 1 : 2,
                'title' => mb_substr(__("asistente.recomendaciones.{$type}_titulo", ['x' => mb_strimwidth(trim($example), 0, 90, '…')]), 0, 200),
                'reason' => __("asistente.recomendaciones.{$type}_motivo", ['n' => count($list), 'd' => self::DAYS, 'ejemplo' => mb_strimwidth(trim((string) $example), 0, 140, '…')])
                    .($entry ? ' '.__('asistente.recomendaciones.coincide_mapa', ['key' => $entry->key, 'title' => $entry->title]) : ''),
                'map_entry_id' => $entry->id ?? null, 'template_key' => $entry->template_key ?? null,
                'suggested_position_id' => $positionId, 'suggested_user_id' => $userId,
                'evidence' => [$type === 'preguntas' ? 'query_ids' : 'feedback_ids' => $list],
            ];
        }

        return $out;
    }

    /** Position (by key) and its main active holder. @return array{0: ?int, 1: ?int} */
    private static function ownerOf(int|string $teamId, ?string $positionKey): array
    {
        $positionId = $positionKey ? DB::table('clarto_positions')->where('team_id', $teamId)->where('key', $positionKey)->value('id') : null;
        if ($positionId === null) {
            return [null, null];
        }
        $today = now()->toDateString();
        $userId = DB::table('clarto_position_user')->where('team_id', $teamId)->where('position_id', $positionId)
            ->where(fn ($q) => $q->whereNull('starts_on')->orWhere('starts_on', '<=', $today))
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $today))
            ->orderByDesc('is_primary')->orderBy('id')->value('user_id');

        return [(int) $positionId, $userId === null ? null : (int) $userId];
    }

    private static function authorize(User $by, Recommendation $recommendation): void
    {
        if (! ProcessAccess::canAdminister($by, $recommendation->team_id)) {
            throw new AuthorizationException;
        }
    }
}
