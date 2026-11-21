<?php

declare(strict_types=1);

namespace App\Processes\Assistant;

use App\Ai\AiClient;
use App\Ai\AiUnavailable;
use App\Models\User;
use App\Processes\Models\Process;
use App\Processes\Models\ProcessVersion;
use App\Processes\ProcessAccess;
use App\Processes\ProcessFeedback;
use App\Processes\ProcessLinks;
use App\Processes\Recommendations\Recommendation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Liberu\Foundation\Organizations\Models\Team;

/**
 * «Preguntar a CLARTO» (diseno.md §9).
 *
 * 1. The question is masked (DNI/NIE/CIF, IBAN, e-mail, phone, names of the company's people) before anything else.
 * 2. Retrieval runs ONLY over fragments whose version is in ProcessAccess::visibleQuery($user): current, published and
 *    readable by that person — the filter is part of the same SQL, so nothing else is ever read, scored or sent.
 * 3. No candidate with enough of the question's terms → «sin documentación» (no AI call); several close candidates →
 *    «aclaración» with the procedures as options (no AI call).
 * 4. Only the best procedure's fragments (company limits of fragments and characters, never ai_excluded ones, never
 *    attachments) go to the provider configured in the panel, through AiClient.
 * 5. Citations are rebuilt here from the fragments sent (the model cannot invent links) and re-checked with
 *    ProcessAccess::canView every time they are shown. A practice the model proposes becomes a pending recommendation.
 */
final class Assistant
{
    public const MAX_QUESTION = 1000;

    /** A second procedure scoring at least this share of the first one makes the question ambiguous. */
    public const AMBIGUITY_RATIO = 0.75;

    /** Share of the question's terms a procedure must contain to count as a candidate. */
    public const MIN_COVERAGE = 0.34;

    private const SYSTEM = <<<'TXT'
        Eres «Preguntar a CLARTO», el asistente interno de procedimientos de CLARTO (inmobiliaria, alquileres, consultoría y gestoría).
        Reglas:
        - Responde SOLO con lo que dicen los fragmentos. No uses conocimiento externo ni inventes datos, plazos, enlaces o nombres.
        - Respuesta breve y práctica, en español: como mucho 8 líneas, con los pasos relevantes numerados.
        - Indica de qué fragmento sale cada dato con su marca, por ejemplo [F1].
        - Si los fragmentos no permiten responder, contesta solo: SIN_DOCUMENTACION
        - Si crees útil una práctica que NO está en los fragmentos, no la presentes como norma: escríbela en una línea aparte que empiece por «PROPUESTA:».
        TXT;

    public static function ask(User $user, int|string $teamId, string $question, ?int $contextVersionId = null): AssistantQuery
    {
        if (! ProcessAccess::isStaff($user, $teamId)) {
            throw new AuthorizationException;
        }
        $question = Text::maskWithNames(mb_substr(trim($question), 0, self::MAX_QUESTION), self::peopleNames($teamId));
        if ($question === '') {
            throw ValidationException::withMessages(['question' => __('asistente.errores.vacia')]);
        }
        $started = microtime(true);
        $log = fn (array $attributes): AssistantQuery => AssistantQuery::query()->create($attributes + [
            'team_id' => $teamId, 'user_id' => $user->getKey(), 'question' => $question, 'context_version_id' => $contextVersionId,
            'latency_ms' => (int) round((microtime(true) - $started) * 1000),
        ]);

        if (! AssistantSettings::activeFor($teamId)) {
            return $log(['outcome' => 'desactivado', 'answer' => __('asistente.respuestas.desactivado')]);
        }
        $settings = AssistantSettings::forTeam($teamId);
        $terms = Text::terms($question);
        $ranked = $terms === [] ? collect() : self::rank(self::retrieve($user, $teamId, $terms), $terms);
        $candidates = $ranked->map(fn (array $c): array => ['process_version_id' => $c['version_id'], 'score' => round($c['score'], 3)])->take(5)->values()->all();

        if ($ranked->isEmpty()) {
            return $log(['outcome' => 'sin_documentacion', 'answer' => __('asistente.respuestas.sin_documentacion'), 'candidates' => []]);
        }

        $context = $contextVersionId === null ? null : $ranked->firstWhere('version_id', $contextVersionId);
        if ($context === null && $ranked->count() > 1 && $ranked[1]['score'] >= self::AMBIGUITY_RATIO * $ranked[0]['score']) {
            return $log(['outcome' => 'aclaracion', 'answer' => __('asistente.respuestas.aclaracion'), 'candidates' => $candidates]);
        }
        $winner = $context ?? $ranked[0];
        $version = ProcessVersion::query()->findOrFail($winner['version_id']);
        $process = Process::query()->findOrFail($winner['process_id']);

        if ($process->ai_excluded) {   // only its title is cited: nothing of it is sent to the provider
            return $log(['outcome' => 'respondida', 'answer' => __('asistente.respuestas.excluido'), 'candidates' => $candidates,
                'cited' => [['process_version_id' => $version->getKey(), 'anchor' => 'resumen']]]);
        }

        [$fragments, $prompt] = self::prompt($question, $winner['hits'], $process, $version, $settings);
        try {
            $reply = AiClient::make()->complete('writing', self::SYSTEM, $prompt, $settings->model());
        } catch (AiUnavailable $e) {
            report($e);

            return $log(['outcome' => 'error', 'answer' => __('asistente.respuestas.error'), 'candidates' => $candidates, 'chars_sent' => mb_strlen(self::SYSTEM.$prompt),
                'cited' => [['process_version_id' => $version->getKey(), 'anchor' => $fragments[0]->anchor]]]);
        }

        $content = trim($reply['content']);
        if (preg_match('/^\W*SIN_DOCUMENTACI[OÓ]N/iu', $content) === 1 || (str_contains(mb_strtoupper($content), 'SIN_DOCUMENTACI') && ! str_contains($content, '[F'))) {
            return $log(['outcome' => 'sin_documentacion', 'answer' => __('asistente.respuestas.sin_documentacion'), 'candidates' => $candidates,
                'model' => $reply['model'], 'chars_sent' => mb_strlen(self::SYSTEM.$prompt)]);
        }

        preg_match_all('/^\s*\**PROPUESTA:\**\s*(.+)$/mu', $content, $proposals);
        $content = (string) preg_replace('/^\s*\**PROPUESTA:.*$/mu', '', $content);
        [$answer, $cited] = self::cite($content, $fragments, $version);
        if ($proposals[1] !== []) {
            $answer .= "\n\n".__('asistente.respuestas.propuesta_pendiente');
        }

        $query = $log(['outcome' => 'respondida', 'answer' => $answer, 'cited' => $cited, 'candidates' => $candidates,
            'model' => $reply['model'], 'chars_sent' => mb_strlen(self::SYSTEM.$prompt)]);
        foreach ($proposals[1] as $proposal) {
            self::suggestPractice($query, $process, $version, Text::mask(trim($proposal)));
        }

        return $query;
    }

    /**
     * Fragments of versions the person can read right now, best first. The access filter is in the same query.
     *
     * @param  list<string>  $terms
     * @return Collection<int, object{id: int, process_id: int, process_version_id: int, anchor: string, heading: string, body: string, score: float}>
     */
    public static function retrieve(User $user, int|string $teamId, array $terms): Collection
    {
        $visible = ProcessAccess::visibleQuery($user, $teamId)->select('clarto_process_versions.id');
        $query = DB::table(ProcessIndex::TABLE)->where(ProcessIndex::TABLE.'.team_id', $teamId)
            ->whereIn(ProcessIndex::TABLE.'.process_version_id', $visible)
            ->select(ProcessIndex::TABLE.'.*');

        if (ProcessIndex::hasFts()) {
            $match = implode(' OR ', array_map(fn (string $t): string => '"'.str_replace('"', '', $t).'"*', $terms));

            return $query->join(ProcessIndex::FTS, ProcessIndex::FTS.'.rowid', '=', ProcessIndex::TABLE.'.id')
                ->whereRaw(ProcessIndex::FTS.' MATCH ?', [$match])
                ->selectRaw('-bm25('.ProcessIndex::FTS.', 2.0, 1.0) as score')
                ->orderByDesc('score')->limit(60)->get();
        }

        // ponytail: LIKE fallback without FTS5 (other drivers): accent-sensitive and scored by terms found.
        return $query->where(function ($q) use ($terms): void {
            foreach ($terms as $term) {
                $q->orWhere(ProcessIndex::TABLE.'.body', 'like', "%{$term}%")->orWhere(ProcessIndex::TABLE.'.heading', 'like', "%{$term}%");
            }
        })->limit(200)->get()->each(function (object $row) use ($terms): void {
            $row->score = Text::coverage($terms, $row->heading.' '.$row->body) * 10;
        })->sortByDesc('score')->take(60)->values();
    }

    /**
     * Procedures with enough of the question's terms, best first (score = sum of its three best fragments).
     *
     * @return Collection<int, array{process_id: int, version_id: int, score: float, coverage: float, hits: Collection}>
     */
    private static function rank(Collection $hits, array $terms): Collection
    {
        return $hits->groupBy('process_id')->map(fn (Collection $rows, $processId): array => [
            'process_id' => (int) $processId,
            'version_id' => (int) $rows->first()->process_version_id,
            'score' => (float) $rows->pluck('score')->sortDesc()->take(3)->sum(),
            'coverage' => Text::coverage($terms, $rows->map(fn (object $r): string => $r->heading.' '.$r->body)->implode(' ')),
            'hits' => $rows->sortByDesc('score')->values(),
        ])->filter(fn (array $c): bool => $c['coverage'] >= self::MIN_COVERAGE)->sortByDesc('score')->values();
    }

    /** @return array{0: list<object>, 1: string} fragments sent and the user message */
    private static function prompt(string $question, Collection $hits, Process $process, ProcessVersion $version, AssistantSettings $settings): array
    {
        $fragments = [];
        $chars = 0;
        foreach ($hits as $hit) {
            if (count($fragments) >= max(1, $settings->max_context_chunks) || ($fragments !== [] && $chars + $hit->chars > $settings->max_context_chars)) {
                break;
            }
            $fragments[] = $hit;
            $chars += $hit->chars;
        }
        $text = "Pregunta: {$question}\n\nFragmentos del procedimiento {$process->code} «".Text::mask((string) $version->title)."», versión {$version->number}:\n";
        foreach ($fragments as $i => $fragment) {
            $text .= "\n[F".($i + 1)."] {$fragment->heading}\n".mb_substr($fragment->body, 0, max(200, $settings->max_context_chars))."\n";
        }

        return [$fragments, $text];
    }

    /**
     * Replaces the model's [F#] marks by [1], [2]… of the citations built from the fragments really sent; drops any
     * URL the model wrote (links come only from ProcessLinks).
     *
     * @param  list<object>  $fragments
     * @return array{0: string, 1: list<array{process_version_id: int, anchor: string}>}
     */
    private static function cite(string $content, array $fragments, ProcessVersion $version): array
    {
        $cited = [];
        $content = (string) preg_replace_callback('/\[F(\d+)\]/', function (array $m) use ($fragments, $version, &$cited): string {
            $fragment = $fragments[(int) $m[1] - 1] ?? null;
            if ($fragment === null) {
                return '';
            }
            $entry = ['process_version_id' => (int) $version->getKey(), 'anchor' => (string) $fragment->anchor];
            $n = array_search($entry, $cited, true);
            if ($n === false) {
                $cited[] = $entry;
                $n = count($cited) - 1;
            }

            return '['.($n + 1).']';
        }, $content);
        if ($cited === []) {
            $cited[] = ['process_version_id' => (int) $version->getKey(), 'anchor' => (string) $fragments[0]->anchor];
        }
        $content = (string) preg_replace(['#\(?https?://\S+\)?#u', '/\n{3,}/'], ['', "\n\n"], $content);

        return [Text::mask(trim($content)), $cited];
    }

    private static function suggestPractice(AssistantQuery $query, Process $process, ProcessVersion $version, string $proposal): void
    {
        if ($proposal === '') {
            return;
        }
        Recommendation::query()->firstOrCreate(
            ['team_id' => $query->team_id, 'fingerprint' => hash('sha256', 'practica:'.$process->getKey().':'.Text::fold($proposal))],
            [
                'type' => 'practica_sugerida', 'status' => 'pendiente', 'priority' => 3, 'ai_generated' => true,
                'title' => mb_substr(__('asistente.recomendaciones.practica_titulo', ['code' => $process->code]), 0, 200),
                'reason' => __('asistente.recomendaciones.practica_motivo', ['code' => $process->code, 'n' => $version->number]),
                'process_id' => $process->getKey(), 'suggested_user_id' => $process->owner_user_id, 'body' => $proposal,
                'evidence' => ['query_ids' => [$query->getKey()]],
            ],
        );
    }

    /**
     * Citations the person may open NOW (re-checked with canView): label and link to the procedure version and section.
     *
     * @return list<array{label: string, url: string}>
     */
    public static function citations(AssistantQuery $query, ?User $user): array
    {
        $links = [];
        foreach ((array) $query->cited as $i => $cite) {
            $version = ProcessVersion::query()->find($cite['process_version_id'] ?? 0);
            if ($version === null || ! ProcessAccess::canView($user, $version)) {
                continue;
            }
            $process = Process::query()->find($version->process_id);
            $links[] = [
                'label' => '['.($i + 1).'] '.$process?->code.' · '.$version->title.' · v'.$version->number,
                'url' => ProcessLinks::version($version, $cite['anchor'] ?? null),
            ];
        }

        return $links;
    }

    /** The stored answer, or null when the person can no longer read some cited version (it is not shown then). */
    public static function visibleAnswer(AssistantQuery $query, ?User $user): ?string
    {
        foreach ((array) $query->cited as $cite) {
            $version = ProcessVersion::query()->find($cite['process_version_id'] ?? 0);
            if ($version === null || ! ProcessAccess::canView($user, $version)) {
                return null;
            }
        }

        return $query->answer;
    }

    /**
     * Candidate procedures of an «aclaración» the person can still read.
     *
     * @return list<array{version_id: int, label: string}>
     */
    public static function options(AssistantQuery $query, ?User $user): array
    {
        $options = [];
        foreach ((array) $query->candidates as $candidate) {
            $version = ProcessVersion::query()->find($candidate['process_version_id'] ?? 0);
            if ($version !== null && ProcessAccess::canView($user, $version)) {
                $options[] = ['version_id' => (int) $version->getKey(), 'label' => Process::query()->whereKey($version->process_id)->value('code').' · '.$version->title];
            }
        }

        return $options;
    }

    public static function rate(User $user, AssistantQuery $query, int $rating, ?string $comment = null): void
    {
        self::ensureOwn($user, $query);
        $query->forceFill([
            'rating' => $rating >= 0 ? 1 : -1,
            'rating_comment' => filled($comment) ? Text::mask(mb_substr(trim((string) $comment), 0, 1000)) : null,
            'rated_at' => now(),
        ])->save();
    }

    /** «Enviar la duda al responsable»: an improvement request linked to the cited procedure (if still readable). */
    public static function sendToOwner(User $user, AssistantQuery $query, ?string $note = null): \App\Models\ImprovementRequest
    {
        self::ensureOwn($user, $query);
        if ($query->improvement_request_id !== null && ($existing = \App\Models\ImprovementRequest::query()->find($query->improvement_request_id)) !== null) {
            return $existing;
        }
        $version = ProcessVersion::query()->find($query->cited[0]['process_version_id'] ?? $query->context_version_id ?? 0);
        $version = $version !== null && ProcessAccess::canView($user, $version) ? $version : null;
        $text = trim($query->question.(filled($note) ? "\n\n".Text::mask(mb_substr(trim((string) $note), 0, 2000)) : ''));

        $request = ProcessFeedback::submit($user, $query->team_id, $text, $version, null, 'asistente', (int) $query->getKey());
        $query->forceFill(['improvement_request_id' => $request->getKey()])->save();

        return $request;
    }

    private static function ensureOwn(User $user, AssistantQuery $query): void
    {
        if ((string) $query->user_id !== (string) $user->getKey()) {
            throw new AuthorizationException;
        }
    }

    /** Names of the company's people, masked out of questions (never sent to the provider). @return list<string> */
    private static function peopleNames(int|string $teamId): array
    {
        $team = Team::query()->find($teamId);
        if ($team === null) {
            return [];
        }
        $names = collect($team->allUsers())->pluck('name')->filter()->all();
        $parts = collect($names)->flatMap(fn (string $n): array => preg_split('/\s+/u', $n) ?: [])->filter(fn (string $p): bool => mb_strlen($p) >= 4);

        return collect($names)->sortByDesc(fn (string $n): int => mb_strlen($n))->merge($parts)->unique()->values()->all();
    }
}
