<?php

declare(strict_types=1);

namespace App\Processes\Assistant\Console;

use App\Filament\Processes\Assistant\AskClarto;
use App\Filament\Processes\Assistant\RecommendationsTable;
use App\Models\AiSetting;
use App\Models\ImprovementRequest;
use App\Models\User;
use App\Modules\ClartoModules;
use App\Processes\Assistant\Assistant;
use App\Processes\Assistant\AssistantQuery;
use App\Processes\Assistant\AssistantSettings;
use App\Processes\Assistant\ProcessIndex;
use App\Processes\Assistant\ReindexProcess;
use App\Processes\Events\ProcessArchived;
use App\Processes\Events\ProcessVersionPublished;
use App\Processes\Models\Process;
use App\Processes\Models\ProcessVersion;
use App\Processes\ProcessExtensions;
use App\Processes\ProcessLinks;
use App\Processes\Processes;
use App\Processes\Recommendations\Recommendation;
use App\Processes\Recommendations\Recommendations;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Liberu\Foundation\Organizations\Models\Team;
use Livewire\Livewire;
use Throwable;

/**
 * «Preguntar a CLARTO» and the documentation recommendations end to end, with a fictitious company, people and the
 * four demo procedures of ejemplos-demo.md (SIS-91, ALQ-91, restricted ADF-91 with the ZAFIRO-7 mark, ALQ-92 v1/v2).
 * The AI provider is faked (Http::fake, stray requests refused): every request body is inspected for what must not be
 * sent. Database in a rolled-back transaction: nothing is kept.
 *
 *   php artisan clarto:procesos-asistente-prueba
 */
final class TestAssistantCommand extends Command
{
    protected $signature = 'clarto:procesos-asistente-prueba';

    protected $aliases = ['clarto:asistente-prueba'];

    protected $description = 'Test «Preguntar a CLARTO» and documentation recommendations (AI faked); nothing is kept';

    private int $pass = 0;

    private int $fail = 0;

    /** @var array<string, User> */
    private array $people = [];

    /** @var array<string, int> position key => id */
    private array $positions = [];

    /** @var array<string, Process> */
    private array $processes = [];

    private Team $team;

    public function handle(): int
    {
        config(['session.driver' => 'array', 'queue.default' => 'sync', 'cache.default' => 'array', 'permission.cache.store' => 'array',
            'modules.enabled' => [...(array) config('modules.enabled', []), 'clarto-procesos', 'clarto-procesos-asistente']]);
        app()->forgetScopedInstances();
        Http::preventStrayRequests();
        Http::fake(['openrouter.ai/*' => fn (HttpRequest $request) => Http::response(self::fakeReply($request))]);
        if (! Route::has(ProcessLinks::ROUTE)) {   // the procedure page arrives with Agente 2's screens; links only need the name
            Route::get('admin/{tenant}/procesos/ver', fn () => '')->name(ProcessLinks::ROUTE);
            app('router')->getRoutes()->refreshNameLookups();
        }

        DB::beginTransaction();
        try {
            $this->scenarios();
        } catch (Throwable $e) {
            $this->check('sin errores inesperados: '.$e::class.': '.$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine(), false);
        } finally {
            DB::rollBack();
            Auth::forgetGuards();
        }

        $this->line(sprintf('RESULT: %d passed, %d failed (database rolled back, AI faked)', $this->pass, $this->fail));

        return $this->fail === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function scenarios(): void
    {
        $this->setUp();
        $t = $this->team->getKey();
        [$ana, $bruno, $carla, $diego, $elena, $otro] = [$this->people['ana'], $this->people['bruno'], $this->people['carla'], $this->people['diego'], $this->people['elena'], $this->people['otro']];

        // Module, provider and index.
        $this->check('módulo procesos_asistente implementado y activo para la empresa', ClartoModules::isImplemented(AssistantSettings::MODULE) && AssistantSettings::activeFor($t));
        $this->check('escucha ProcessVersionPublished y ProcessArchived', Event::hasListeners(ProcessVersionPublished::class) && Event::hasListeners(ProcessArchived::class));
        $this->check('pestañas «recomendaciones» y «consultas» en la gestión', ($tabs = array_keys(ProcessExtensions::adminTabs())) && in_array('recomendaciones', $tabs, true) && in_array('consultas', $tabs, true));
        $this->check('índice con FTS5 en SQLite', ProcessIndex::hasFts());
        $count = ProcessIndex::rebuild($t);
        $chunks = DB::table(ProcessIndex::TABLE)->where('team_id', $t)->get();
        $v1 = $this->version('ALQ-92', 1);
        $this->check("reindexado: {$count} fragmentos, solo versiones vigentes (no ALQ-92 v1)", $count > 0 && $chunks->where('process_version_id', $v1->getKey())->isEmpty()
            && $chunks->pluck('process_version_id')->unique()->sort()->values()->all() === collect($this->processes)->map(fn (Process $p) => (int) $p->current_version_id)->sort()->values()->all());
        $this->check('el índice guarda el texto enmascarado y sin rutas de adjuntos', ! $chunks->contains(fn ($c) => str_contains($c->body, '600 123 456') || str_contains($c->body, 'soporte@example.test') || str_contains($c->body, 'procesos/'))
            && $chunks->contains(fn ($c) => str_contains($c->body, '[teléfono]') && str_contains($c->body, '[correo]')));
        $this->check('reindexar dos veces da el mismo resultado', ProcessIndex::rebuild($t) === $count);

        // 1. Restricted ADF-91: Ana and Diego get nothing (not in the provider request, answer, log or citations); Bruno and Carla do.
        $adf = $this->processes['ADF-91'];
        $question = '¿Cómo se cuadra la caja de oficina a fin de mes?';
        foreach (['ana' => false, 'diego' => false, 'bruno' => true, 'carla' => true] as $who => $allowed) {
            $before = count(Http::recorded());
            $q = Assistant::ask($this->people[$who], $t, $question);
            $sent = $this->sentSince($before);
            $cites = collect((array) $q->cited)->pluck('process_version_id')->contains((int) $adf->current_version_id);
            $candidate = collect((array) $q->candidates)->pluck('process_version_id')->contains((int) $adf->current_version_id);
            $leak = str_contains($sent, 'ZAFIRO') || str_contains((string) $q->answer, 'ZAFIRO') || str_contains(json_encode($q->getAttributes()), 'ZAFIRO');
            $this->check($allowed
                ? "ADF-91 con permiso ({$who}): respondida con ADF-91 citado y su texto enviado al proveedor"
                : "ADF-91 sin permiso ({$who}): ni texto, ni cita, ni candidato, ni ZAFIRO-7 en la petición, la respuesta o el registro",
                $allowed ? ($q->outcome === 'respondida' && $cites && str_contains($sent, 'ZAFIRO-7') && str_contains((string) $q->answer, 'ZAFIRO-7')) : (! $cites && ! $candidate && ! $leak));
            if ($who === 'bruno') {
                $this->check('enlaces citados vueltos a comprobar: Ana no ve la respuesta ni el enlace de la consulta de Bruno; Bruno sí',
                    Assistant::visibleAnswer($q, $ana) === null && Assistant::citations($q, $ana) === []
                    && Assistant::visibleAnswer($q, $bruno) !== null && count(Assistant::citations($q, $bruno)) === 1);
                $this->check('las citas enlazan a la versión y su apartado, nunca a un adjunto',
                    collect(Assistant::citations($q, $bruno))->every(fn (array $l): bool => str_contains($l['url'], 'v='.$adf->current_version_id) && str_contains($l['url'], '#') && ! str_contains($l['url'], 'adjunto')));
            }
        }
        $this->check('persona de otra empresa: no puede preguntar', $this->denied(fn () => Assistant::ask($otro, $t, $question)));

        // 2. ALQ-92: answers with v2 (24 h), never v1 (48 h).
        $before = count(Http::recorded());
        $q = Assistant::ask($carla, $t, '¿En cuánto tiempo hay que comunicar los viajeros?');
        $sent = $this->sentSince($before);
        $v2 = $this->version('ALQ-92', 2);
        $this->check('viajeros: responde con ALQ-92 v2 (24 horas) y nunca con v1 (48 horas)', $q->outcome === 'respondida'
            && collect((array) $q->cited)->pluck('process_version_id')->unique()->values()->all() === [(int) $v2->getKey()]
            && str_contains($sent, '24 horas') && ! str_contains($sent, '48 horas') && ! str_contains((string) $q->answer, '48'));
        $this->check('la respuesta enlaza a la versión 2 con su ancla', ($link = Assistant::citations($q, $carla)[0]['url'] ?? '') !== '' && str_contains($link, 'v='.$v2->getKey()) && str_contains($link, '#'));
        $this->check('el registro guarda modelo, caracteres enviados y tiempo', filled($q->model) && $q->chars_sent > 0 && $q->latency_ms !== null);

        // 3. Ambiguous → clarification with the candidates as options, nothing sent; choosing one answers within it.
        $before = count(Http::recorded());
        $q = Assistant::ask($carla, $t, '¿Qué plazo tengo?');
        $options = collect(Assistant::options($q, $carla))->pluck('version_id');
        $this->check('«¿qué plazo tengo?»: pide aclaración con ALQ-91 y ALQ-92 como opciones, sin llamar al proveedor',
            $q->outcome === 'aclaracion' && $options->contains((int) $this->processes['ALQ-91']->current_version_id) && $options->contains((int) $v2->getKey()) && count(Http::recorded()) === $before);
        $q = Assistant::ask($carla, $t, '¿Qué plazo tengo?', (int) $v2->getKey());
        $this->check('al elegir ALQ-92 responde dentro de ese procedimiento', $q->outcome === 'respondida' && ($q->cited[0]['process_version_id'] ?? null) === (int) $v2->getKey());

        // 4. Proposal of a new practice → pending AI recommendation, not a rule in the answer.
        $q = Assistant::ask($carla, $t, '¿Se podría mejorar el envío del parte de viajeros con un recordatorio?');
        $practice = Recommendation::query()->where('team_id', $t)->where('type', 'practica_sugerida')->first();
        $this->check('una práctica nueva queda como recomendación pendiente «Propuesta generada por IA» y no como norma',
            $q->outcome === 'respondida' && ! str_contains((string) $q->answer, 'PROPUESTA') && str_contains((string) $q->answer, __('asistente.respuestas.propuesta_pendiente'))
            && $practice?->status === 'pendiente' && $practice->ai_generated && (int) $practice->process_id === (int) $this->processes['ALQ-92']->getKey()
            && in_array($q->getKey(), (array) ($practice->evidence['query_ids'] ?? []), true));

        // 5. Masking: identifiers in the question never reach the provider or the log.
        $before = count(Http::recorded());
        $q = Assistant::ask($carla, $t, 'El inquilino con DNI 12345678Z, IBAN ES91 2100 0418 4502 0005 1332, correo pepe.perez@example.test y teléfono 612 345 678, ¿cómo le devuelvo la fianza? Pregunta de Bruno Prueba');
        $sent = $this->sentSince($before);
        $secrets = ['12345678Z', 'ES91 2100', '0418 4502', 'pepe.perez', '612 345 678', 'Bruno Prueba'];
        $this->check('DNI, IBAN, correo, teléfono y nombre del equipo enmascarados en la petición al proveedor y en el registro',
            $sent !== '' && collect($secrets)->every(fn (string $s): bool => ! str_contains($sent, $s) && ! str_contains((string) $q->question, $s))
            && str_contains((string) $q->question, '[DNI]') && str_contains((string) $q->question, '[IBAN]'));
        $this->check('a la IA solo van la pregunta y fragmentos del procedimiento elegido (ALQ-91), sin adjuntos ni nombres', str_contains($sent, 'ALQ-91') && ! str_contains($sent, 'ALQ-92')
            && ! str_contains($sent, 'demo-d2') && ! str_contains($sent, 'Carla Prueba') && ! str_contains($sent, '@example.test'));

        // 6. Excluded from the AI: only the title is cited, nothing is sent.
        $this->processes['ALQ-91']->forceFill(['ai_excluded' => true])->save();
        $before = count(Http::recorded());
        $q = Assistant::ask($carla, $t, '¿Cómo devuelvo la fianza al inquilino?');
        $this->check('proceso excluido de la IA: cita solo el título y no envía nada', $q->outcome === 'respondida' && count(Http::recorded()) === $before
            && ($q->cited[0]['process_version_id'] ?? null) === (int) $this->processes['ALQ-91']->current_version_id && $q->answer === __('asistente.respuestas.excluido'));
        $this->processes['ALQ-91']->forceFill(['ai_excluded' => false])->save();

        // 7. Insufficient documentation → doubt to the owner (ImprovementRequest + process feedback row).
        $before = count(Http::recorded());
        $q = Assistant::ask($ana, $t, '¿Cómo se tramita una herencia en el registro civil?');
        $this->check('sin documentación: lo dice y no llama al proveedor', $q->outcome === 'sin_documentacion' && count(Http::recorded()) === $before);
        $request = Assistant::sendToOwner($ana, $q, 'Nos ha pasado con un cliente esta semana.');
        $link = DB::table('clarto_process_feedback')->where('improvement_request_id', $request->getKey())->first();
        $this->check('«Enviar la duda al responsable» crea la solicitud de mejora y la fila de clarto_process_feedback (asistente)',
            $request instanceof ImprovementRequest && str_starts_with($request->title, '[Procesos]') && $link?->source === 'asistente'
            && (int) $link->assistant_query_id === (int) $q->getKey() && (int) $q->fresh()->improvement_request_id === (int) $request->getKey());
        $this->check('enviarla otra vez no la duplica', Assistant::sendToOwner($ana, $q->fresh())->getKey() === $request->getKey());
        $this->check('otra persona no puede enviar ni valorar la consulta de Ana', $this->denied(fn () => Assistant::sendToOwner($bruno, $q)) && $this->denied(fn () => Assistant::rate($bruno, $q, 1)));

        // 8. Rating and the log of questions without a useful answer.
        $rated = AssistantQuery::query()->where('user_id', $carla->getKey())->where('outcome', 'respondida')->latest('id')->firstOrFail();
        Assistant::rate($carla, $rated, -1, 'No me sirve; mi DNI es 87654321X');
        $rated->refresh();
        $unanswered = AssistantQuery::query()->where('team_id', $t)->unanswered()->pluck('id');
        $this->check('valoración «no útil» guardada con el comentario enmascarado', $rated->rating === -1 && $rated->rated_at !== null && ! str_contains((string) $rated->rating_comment, '87654321X'));
        $this->check('registro de consultas sin respuesta útil: la valorada como no útil y la de sin documentación', $unanswered->contains($rated->getKey()) && $unanswered->contains($q->getKey()));
        $old = AssistantQuery::query()->create(['team_id' => $t, 'user_id' => $ana->getKey(), 'question' => 'antigua', 'outcome' => 'sin_documentacion']);
        $old->forceFill(['created_at' => now()->subDays(200)])->save();
        AssistantQuery::prune();
        $this->check('el registro se borra pasados los días de retención (180)', AssistantQuery::query()->find($old->getKey()) === null && AssistantQuery::query()->find($q->getKey()) !== null);

        // 9. Lifecycle: a new version replaces the fragments; archiving stops using the process at once.
        $sis = $this->processes['SIS-91'];
        $draft = Processes::startRevision($elena, $sis);
        Processes::saveDraft($elena, $draft, ['steps' => [...(array) $draft->steps, ['key' => 'd1paso04', 'title' => 'Anotar el reenvío', 'actor_position_id' => $this->positions['direccion'], 'actor_label' => null,
            'action' => 'Anote el reenvío en el historial del usuario con la palabra TURQUESA-3.', 'done_when' => 'Nota guardada.', 'evidence' => null, 'deadline' => 'En el momento', 'work_link' => null, 'tarmio_kind' => null, 'decision_key' => null, 'blocks' => []]],
            'change_summary' => 'Se añade la anotación del reenvío.']);
        Processes::submit($elena, $draft->fresh());
        $old = $sis->currentVersion;
        Processes::publish($elena, $draft->fresh());
        (new ReindexProcess)->handle(new ProcessVersionPublished($draft->fresh(), $old->fresh()));
        $ids = DB::table(ProcessIndex::TABLE)->where('process_id', $sis->getKey())->pluck('process_version_id')->unique()->values()->all();
        $this->check('al publicar una versión nueva el índice solo tiene esa versión', $ids === [(int) $draft->getKey()]
            && DB::table(ProcessIndex::TABLE)->where('process_id', $sis->getKey())->where('body', 'like', '%TURQUESA-3%')->exists());

        $alq92 = $this->processes['ALQ-92'];
        Processes::archive($elena, $alq92, 'Prueba de archivado');
        $q = Assistant::ask($carla, $t, '¿En cuánto tiempo hay que comunicar los viajeros?');
        $this->check('tras archivar ALQ-92 (aun sin reindexar) no lo recupera ni lo cita', ! collect([...(array) $q->cited, ...(array) $q->candidates])->pluck('process_version_id')->contains((int) $v2->getKey()));
        (new ReindexProcess)->handle(new ProcessArchived($alq92->fresh(), $v2->fresh()));
        $this->check('al archivar se borran sus fragmentos', ! DB::table(ProcessIndex::TABLE)->where('process_id', $alq92->getKey())->exists());

        // 10. Switched off for the company → nothing is sent.
        AssistantSettings::query()->where('team_id', $t)->update(['enabled' => false]);
        $before = count(Http::recorded());
        $q = Assistant::ask($carla, $t, '¿Cómo devuelvo la fianza?');
        $this->check('asistente desactivado en la empresa: «desactivado» y nada enviado', $q->outcome === 'desactivado' && count(Http::recorded()) === $before);
        AssistantSettings::query()->where('team_id', $t)->update(['enabled' => true]);

        // 11. Recommendations.
        $herencia = Assistant::ask($diego, $t, 'Un inquilino ha fallecido: ¿qué hacemos con la herencia y el contrato?');
        $pending = Recommendations::generate($t);
        $recs = Recommendation::query()->where('team_id', $t)->get();
        $alq10 = $recs->firstWhere('title', 'ALQ-10 · Devolución de la fianza al terminar un alquiler');
        $this->check("recomendaciones creadas ({$pending} pendientes): entrada del mapa sin proceso con motivo, prioridad y responsable sugerido",
            $alq10 !== null && $alq10->type === 'mapa' && $alq10->priority === 1 && filled($alq10->reason)
            && (int) $alq10->suggested_position_id === $this->positions['gestor_alquileres'] && (int) $alq10->suggested_user_id === (int) $carla->getKey());
        $this->check('no recomienda lo ya registrado ni lo descartado en el mapa', $recs->filter(fn ($r) => str_starts_with($r->title, 'ALQ-91') || str_starts_with($r->title, 'SIS-02'))->isEmpty());
        $this->check('detecta huecos por puesto, área y línea de negocio', $recs->contains(fn ($r) => $r->type === 'puesto' && (int) $r->suggested_position_id === $this->positions['asesor_inmobiliario'])
            && $recs->contains(fn ($r) => $r->type === 'area' && str_contains($r->title, 'VEN')) && $recs->contains(fn ($r) => $r->type === 'sector'));
        $questions = $recs->where('type', 'preguntas');
        $this->check('tiene en cuenta las preguntas sin respuesta: un tema por grupo (herencia; caja de oficina) con sus consultas como evidencia',
            $questions->contains(fn ($r) => str_contains($r->title, 'herencia') && in_array($herencia->getKey(), (array) ($r->evidence['query_ids'] ?? []), true) && count((array) $r->evidence['query_ids']) === 2)
            && $questions->filter(fn ($r) => str_contains($r->title, 'caja de oficina'))->count() === 1);

        $this->check('solo la administración de procesos decide', $this->denied(fn () => Recommendations::accept($ana, $alq10)) && $this->denied(fn () => Recommendations::openDraft($carla, $alq10)));
        $puesto = $recs->firstWhere('type', 'puesto');
        Recommendations::accept($elena, $puesto);
        $this->check('aceptar', $puesto->fresh()->status === 'aceptada' && (int) $puesto->fresh()->decided_by === (int) $elena->getKey());

        $ven = $recs->first(fn ($r) => str_starts_with($r->title, 'VEN-05'));
        $this->check('descartar exige motivo', $this->rejects(fn () => Recommendations::dismiss($elena, $ven, ' ')));
        Recommendations::dismiss($elena, $ven, 'Lo cubre el manual comercial');
        Recommendations::generate($t);
        $this->check('una recomendación descartada no reaparece', Recommendation::query()->where('team_id', $t)->where('fingerprint', $ven->fingerprint)->count() === 1
            && $ven->fresh()->status === 'descartada' && $ven->fresh()->decision_reason === 'Lo cubre el manual comercial');

        $sis05 = $recs->first(fn ($r) => str_starts_with($r->title, 'SIS-05'));
        $this->check('revisión de duplicados: SIS-05 se parece a SIS-91 y no abre borrador sin confirmarlo',
            collect(Recommendations::similar($t, 'Reenviar el enlace de acceso a una persona del equipo'))->pluck('code')->contains('SIS-91')
            && $this->rejects(fn () => Recommendations::openDraft($elena, $sis05)) && $sis05->fresh()->status === 'pendiente');

        $before = count(Http::recorded());
        Recommendations::propose($elena, $alq10);
        $alq10->refresh();
        $sent = $this->sentSince($before);
        $this->check('«Proponer contenido con IA»: queda marcado como propuesta de IA y solo envía los datos del mapa', $alq10->ai_generated && filled($alq10->body)
            && str_contains($sent, 'Devolución de la fianza') && ! str_contains($sent, 'Carla') && ! str_contains($sent, '@'));
        $this->check('ALQ-10 avisa de que ALQ-91 es parecido (y exige confirmarlo)', collect((array) $alq10->similar)->pluck('code')->contains('ALQ-91')
            && $this->rejects(fn () => Recommendations::openDraft($elena, $alq10)));
        $draft = Recommendations::openDraft($elena, $alq10, similarReviewed: true);
        $alq10->refresh();
        $entry = DB::table('clarto_process_map_entries')->where('team_id', $t)->where('key', 'ALQ-10')->first();
        $this->check('«Abrir borrador» crea un borrador desde la plantilla con ai_assisted y la propuesta marcada, y lo vincula',
            $draft->status === 'borrador' && $draft->ai_assisted && $alq10->status === 'convertida' && (int) $alq10->draft_version_id === (int) $draft->getKey()
            && (int) $entry->process_id === (int) $draft->process_id && str_contains(json_encode($draft->blocks, JSON_UNESCAPED_UNICODE), __('asistente.recomendaciones.propuesta_ia'))
            && $draft->process->code === 'ALQ-10' && $draft->process->template_key === 'financiero');
        Recommendations::generate($t);
        $this->check('la entrada con borrador ya no se recomienda de nuevo y la convertida se conserva', Recommendation::query()->where('team_id', $t)->where('status', 'convertida')->count() === 1
            && Recommendation::query()->where('team_id', $t)->where('status', 'pendiente')->where('title', 'like', 'ALQ-10%')->doesntExist());

        // 12. Screens (Livewire, as the people would use them).
        $this->ui($carla, $elena, $ana);
    }

    private function ui(User $carla, User $elena, User $ana): void
    {
        try {
            Filament::setCurrentPanel(Filament::getPanel('admin'));
            Filament::setTenant($this->team, isQuiet: true);
            Auth::guard('web')->setUser($carla);
            $page = Livewire::test(AskClarto::class)->set('question', '¿Cómo devuelvo la fianza al inquilino?')->call('ask');
            $query = AssistantQuery::query()->find($page->get('queryId'));
            $html = $page->html();
            $this->check('pantalla «Preguntar a CLARTO»: pregunta, respuesta con enlace y valoración', $query?->outcome === 'respondida'
                && str_contains($html, 'ALQ-91') && str_contains($html, __('asistente.valoracion.util')));
            $page->call('rate', 1);
            $this->check('la pantalla guarda la valoración', $query->fresh()->rating === 1);
            Auth::guard('web')->setUser($ana);
            $this->check('la pantalla no muestra a Ana la consulta de Carla', Livewire::test(AskClarto::class)->call('show', $query->getKey())->get('queryId') === null);
            Auth::guard('web')->setUser($elena);
            $this->check('pestaña de recomendaciones visible para administración', RecommendationsTable::allowed() && str_contains(Livewire::test(RecommendationsTable::class)->html(), 'VEN-06'));
            Auth::guard('web')->setUser($ana);
            $this->check('pestaña de recomendaciones cerrada al resto', ! RecommendationsTable::allowed());
        } finally {
            Auth::forgetGuards();
        }
    }

    /** Fictitious company, people, positions, the four demo procedures and a few map entries. */
    private function setUp(): void
    {
        $owner = $this->newUser('Titular Asistente Prueba');
        $this->team = Team::withoutEvents(fn () => Team::query()->forceCreate(['user_id' => $owner->id, 'name' => 'Inmobiliaria Ficticia Asistente', 'personal_team' => false]));
        $other = Team::withoutEvents(fn () => Team::query()->forceCreate(['user_id' => $owner->id, 'name' => 'Otra Empresa Ficticia', 'personal_team' => false]));
        $t = $this->team->getKey();

        $areas = [];
        foreach (['DIR' => ['Dirección y calidad', 'transversal'], 'SIS' => ['Sistemas y datos', 'transversal'], 'VEN' => ['Ventas, propietarios e inmuebles', 'inmobiliaria'],
            'ALQ' => ['Alquileres', 'alquileres'], 'ADF' => ['Administración y finanzas', 'transversal']] as $code => [$name, $line]) {
            $areas[$code] = DB::table('clarto_areas')->insertGetId(['team_id' => $t, 'code' => $code, 'name' => $name, 'business_line' => $line, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach (['direccion' => ['Dirección / Gerencia', 'DIR'], 'sistemas' => ['Responsable de sistemas y datos', 'SIS'], 'asesor_inmobiliario' => ['Asesor/a inmobiliario/a', 'VEN'],
            'gestor_alquileres' => ['Gestor/a de alquileres', 'ALQ'], 'coordinador_vacacional' => ['Coordinador/a de alquiler vacacional', 'ALQ'], 'administracion' => ['Administración y facturación', 'ADF']] as $key => [$name, $area]) {
            $this->positions[$key] = DB::table('clarto_positions')->insertGetId(['team_id' => $t, 'area_id' => $areas[$area], 'key' => $key, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach (['ana' => ['Ana Prueba', 'staff', 'asesor_inmobiliario'], 'bruno' => ['Bruno Prueba', 'staff', 'administracion'], 'carla' => ['Carla Prueba', 'staff', 'gestor_alquileres'],
            'diego' => ['Diego Prueba', 'staff', 'administracion'], 'elena' => ['Elena Prueba', 'admin', 'direccion']] as $key => [$name, $role, $position]) {
            $this->people[$key] = $this->member($name, $this->team, $role);
            DB::table('clarto_position_user')->insert(['team_id' => $t, 'position_id' => $this->positions[$position], 'user_id' => $this->people[$key]->id, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->people['otro'] = $this->member('Otra Persona Prueba', $other, 'staff');
        DB::table('clarto_areas')->where('id', $areas['VEN'])->update(['manager_user_id' => $this->people['elena']->id]);

        AiSetting::current()->forceFill(['provider' => 'openrouter', 'openrouter_api_key' => 'sk-or-prueba-ficticia', 'writing_model' => 'prueba/modelo-ficticio:free', 'paid_fallback_authorized' => false])->save();
        AssistantSettings::query()->create(['team_id' => $t, 'enabled' => true]);

        $step = fn (string $key, string $title, string $actor, string $action, string $done, ?string $deadline, array $blocks = []): array => ['key' => $key, 'title' => $title,
            'actor_position_id' => $this->positions[$actor], 'actor_label' => null, 'action' => $action, 'done_when' => $done, 'evidence' => null, 'deadline' => $deadline,
            'work_link' => null, 'tarmio_kind' => null, 'decision_key' => null, 'blocks' => $blocks];
        $alq92 = fn (string $hours, array $extra): array => ['title' => 'DEMOSTRACIÓN · Comunicar los viajeros de una estancia vacacional',
            'summary' => 'Cómo registrar a los viajeros y enviar su comunicación tras el check-in. (Ejemplo ficticio)', 'objective' => 'Comunicar los datos de los viajeros en plazo.',
            'expected_result' => 'Comunicación enviada y registrada en la estancia.', 'trigger' => 'Llegada de los huéspedes de una reserva vacacional.',
            'steps' => [$step('d4paso01', 'Registrar a los viajeros', 'coordinador_vacacional', 'En la estancia, pestaña **Personas**, añada a cada viajero con su documento.', 'Todos los viajeros mayores de edad registrados.', 'En el check-in'),
                $step('d4paso02', 'Enviar la comunicación', 'coordinador_vacacional', "Pulse **Enviar parte de viajeros** en las **{$hours} horas** siguientes a la llegada.", 'La estancia muestra el parte como enviado.', "{$hours} horas desde la llegada"),
                ...$extra],
            'deadlines' => "{$hours} horas desde la llegada.", 'keywords' => ['viajeros', 'parte de viajeros', 'check-in', 'huéspedes'], 'change_summary' => 'Versión de prueba.'];

        $this->createProcess('SIS-91', 'SIS', 'soporte', 'transversal', 'empresa', 'elena', ['direccion', 'sistemas'], [[
            'title' => 'DEMOSTRACIÓN · Reenviar el enlace de acceso a una persona del equipo',
            'summary' => 'Cuando alguien no recuerda su contraseña o no recibió la invitación, se le envía un enlace nuevo desde Usuarios. (Ejemplo ficticio)',
            'objective' => 'Que la persona recupere el acceso al panel sin que nadie conozca ni escriba su contraseña.', 'expected_result' => 'La persona elige una contraseña nueva con el enlace y entra en el panel.',
            'trigger' => 'Una persona del equipo avisa de que no puede entrar o de que no le llegó la invitación.',
            'steps' => [$step('d1paso01', 'Comprobar la cuenta', 'direccion', 'Abra **Organización → Usuarios** y busque a la persona por nombre o correo.', 'Ha encontrado la ficha y el correo coincide.', 'En el momento'),
                $step('d1paso02', 'Enviar el enlace', 'direccion', 'En la ficha pulse **Enviar enlace de acceso**. Si falla, llame a soporte al 600 123 456 o escriba a soporte@example.test.', 'Aparece el aviso verde de enlace enviado.', 'En el momento')],
            'deadlines' => 'El mismo día del aviso.', 'keywords' => ['contraseña', 'acceso', 'enlace de acceso'], 'change_summary' => 'Primera versión.',
        ]]);
        $this->createProcess('ALQ-91', 'ALQ', 'financiero', 'alquileres', 'empresa', 'carla', ['gestor_alquileres', 'administracion'], [[
            'title' => 'DEMOSTRACIÓN · Devolver la fianza al terminar un alquiler de vivienda habitual',
            'summary' => 'Desde el acta de salida hasta la transferencia al inquilino, con las deducciones justificadas. (Ejemplo ficticio)',
            'objective' => 'Devolver la fianza dentro del plazo legal de un mes desde la entrega de llaves.', 'expected_result' => 'Inquilino con la fianza transferida y justificantes archivados.',
            'trigger' => 'El inquilino entrega las llaves y se firma el acta de salida.',
            'steps' => [$step('d2paso01', 'Revisar el acta de salida', 'gestor_alquileres', 'Compare el acta de salida con el acta e inventario de entrada.', 'Lista de daños anotada.', '2 días hábiles desde la salida'),
                $step('d2paso05', 'Transferir la fianza o el neto', 'administracion', 'Ordene la transferencia al IBAN del inquilino y registre la devolución en **Fianza y garantías**.', 'Devolución registrada.', 'Antes de 1 mes desde la entrega de llaves',
                    [['type' => 'file', 'data' => ['attachment_id' => 999999, 'label' => 'Modelo de liquidación (ficticio)']]])],
            'deadlines' => 'Devolución antes de 1 mes desde la entrega de llaves.', 'keywords' => ['fianza', 'devolver fianza', 'deducciones'], 'change_summary' => 'Primera versión.',
        ]]);
        $this->createProcess('ADF-91', 'ADF', 'financiero', 'transversal', 'restringido', 'bruno', ['administracion'], [[
            'title' => 'DEMOSTRACIÓN · Cuadre mensual de la caja de oficina',
            'summary' => 'Cuadre del efectivo de la oficina con el registro de entradas y salidas, con la clave de comprobación ZAFIRO-7. (Ejemplo ficticio)',
            'objective' => 'Que el efectivo de la oficina coincida con lo registrado cada fin de mes.', 'expected_result' => 'Hoja de cuadre firmada y archivada.',
            'trigger' => 'Último día hábil del mes, al cierre de la oficina.',
            'steps' => [$step('d3paso01', 'Contar el efectivo', 'administracion', 'Cuente el efectivo dos veces y anote el total en la hoja de cuadre. La clave de comprobación es **ZAFIRO-7**.', 'Total anotado.', 'Antes del cierre',
                [['type' => 'file', 'data' => ['attachment_id' => 999998, 'label' => 'Hoja de cuadre (ficticia)']]])],
            'deadlines' => 'Último día hábil del mes.', 'keywords' => ['caja', 'cuadre', 'efectivo', 'cierre de mes'], 'change_summary' => 'Primera versión.',
        ]], [['position', $this->positions['administracion'], 'allow'], ['user', $this->people['carla']->id, 'allow'], ['user', $this->people['diego']->id, 'deny']]);
        $this->createProcess('ALQ-92', 'ALQ', 'operativo', 'alquileres', 'empresa', 'carla', ['coordinador_vacacional', 'gestor_alquileres'], [
            $alq92('48', []),
            $alq92('24', [$step('d4paso03', 'Revisar los rechazos', 'coordinador_vacacional', 'Al día siguiente compruebe que el parte no tiene rechazos.', 'Parte sin rechazos.', '24 horas tras el envío')]),
        ]);

        foreach ([
            ['ALQ-10', 'Devolución de la fianza al terminar un alquiler', 'ALQ', 'financiero', 'alquileres', 'gestor_alquileres', 1, 'financiero', 'por_documentar'],
            ['ALQ-91', 'Devolver la fianza (ya registrado)', 'ALQ', 'financiero', 'alquileres', 'gestor_alquileres', 1, 'financiero', 'por_documentar'],
            ['VEN-05', 'Encargo de venta en exclusiva', 'VEN', 'comercial', 'inmobiliaria', 'asesor_inmobiliario', 2, 'comercial', 'por_documentar'],
            ['VEN-06', 'Visitas con propietario presente', 'VEN', 'comercial', 'inmobiliaria', 'asesor_inmobiliario', 2, 'comercial', 'por_documentar'],
            ['VEN-07', 'Publicación del inmueble en portales', 'VEN', 'comercial', 'inmobiliaria', 'asesor_inmobiliario', 1, 'comercial', 'por_documentar'],
            ['SIS-05', 'Reenviar el enlace de acceso a una persona del equipo', 'SIS', 'soporte', 'transversal', 'sistemas', 3, 'instruccion_breve', 'por_documentar'],
            ['SIS-02', 'Copias de seguridad', 'SIS', 'soporte', 'transversal', 'sistemas', 3, 'instruccion_breve', 'descartado'],
        ] as [$key, $title, $area, $category, $line, $owner, $priority, $template, $status]) {
            DB::table('clarto_process_map_entries')->insert(['team_id' => $t, 'key' => $key, 'title' => $title, 'area_code' => $area, 'category' => $category, 'business_line' => $line,
                'kind' => 'completo', 'owner_position_key' => $owner, 'executing_position_keys' => json_encode([$owner]), 'trigger' => 'Disparador de prueba.', 'liberu_refs' => json_encode(['Contratos y reservas']),
                'priority' => $priority, 'priority_reason' => 'Motivo de prueba.', 'template_key' => $template, 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /**
     * A published process with its versions in order (the last one current; earlier ones replaced), as the demo seed
     * does: rows created directly, no lifecycle event.
     *
     * @param  list<array<string, mixed>>  $versions
     * @param  list<array{0: string, 1: int, 2: string}>  $audiences
     */
    private function createProcess(string $code, string $areaCode, string $category, string $line, string $visibility, string $owner, array $positions, array $versions, array $audiences = []): void
    {
        $t = $this->team->getKey();
        $process = Process::query()->create([
            'team_id' => $t, 'code' => $code, 'area_id' => DB::table('clarto_areas')->where('team_id', $t)->where('code', $areaCode)->value('id'),
            'category' => $category, 'business_line' => $line, 'kind' => 'breve', 'status' => 'publicado', 'visibility' => $visibility,
            'owner_user_id' => $this->people[$owner]->id, 'approver_user_id' => $this->people['elena']->id, 'created_by' => $this->people[$owner]->id,
            'next_review_on' => now()->addYear()->toDateString(),
        ]);
        $process->positions()->sync(array_map(fn (string $k): int => $this->positions[$k], $positions));
        foreach ($audiences as [$type, $target, $effect]) {
            DB::table('clarto_process_audiences')->insert(['process_id' => $process->getKey(), 'type' => $type, 'target_id' => $target, 'effect' => $effect, 'created_at' => now(), 'updated_at' => now()]);
        }
        $last = count($versions);
        foreach ($versions as $i => $content) {
            $version = ProcessVersion::query()->create([...$content, 'team_id' => $t, 'process_id' => $process->getKey(), 'number' => $i + 1,
                'status' => $i + 1 === $last ? 'publicada' : 'sustituida', 'created_by' => $this->people[$owner]->id, 'approved_by' => $this->people['elena']->id,
                'published_at' => now()->subDays($last - $i)]);
        }
        $process->forceFill(['current_version_id' => $version->getKey()])->save();
        $this->processes[$code] = $process->fresh();
    }

    private function version(string $code, int $number): ProcessVersion
    {
        return ProcessVersion::query()->where('process_id', $this->processes[$code]->getKey())->where('number', $number)->firstOrFail();
    }

    /** Bodies of the requests made to the provider since $index, as one string. */
    private function sentSince(int $index): string
    {
        return collect(Http::recorded())->slice($index)->map(fn (array $pair): string => json_encode($pair[0]->data(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))->implode("\n");
    }

    /** The fake model: repeats the first fragment it received (so leaks would show), marks a proposal when asked to improve. */
    private static function fakeReply(HttpRequest $request): array
    {
        $messages = (array) ($request->data()['messages'] ?? []);
        $system = (string) ($messages[0]['content'] ?? '');
        $user = (string) ($messages[1]['content'] ?? '');
        if (str_contains($system, 'consultor de procesos')) {
            $content = "Objetivo: devolver la fianza en plazo.\n1. Gestor/a de alquileres: revisa el acta de salida; termina cuando hay lista de daños.\n2. Administración: transfiere el importe (plazo a confirmar).";
        } else {
            preg_match('/\[F1\][^\n]*\n([^\n]+)/u', $user, $m);
            $content = 'Según el procedimiento: '.mb_substr($m[1] ?? '', 0, 400).' [F1]'.(str_contains($user, 'recordatorio') ? "\nPROPUESTA: Programar un recordatorio automático al coordinador 12 horas antes del plazo." : '');
        }

        return ['id' => 'prueba', 'model' => 'prueba/modelo-ficticio:free', 'choices' => [['message' => ['role' => 'assistant', 'content' => $content]]]];
    }

    private function member(string $name, Team $team, string $role): User
    {
        $user = $this->newUser($name);
        $user->forceFill(['current_team_id' => $team->id])->saveQuietly();
        DB::table('team_user')->insert(['team_id' => $team->id, 'user_id' => $user->id, 'role' => 'editor', 'created_at' => now(), 'updated_at' => now()]);
        $roleId = DB::table('roles')->where(['team_id' => $team->id, 'name' => $role, 'guard_name' => 'web'])->value('id')
            ?? DB::table('roles')->insertGetId(['team_id' => $team->id, 'name' => $role, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('model_has_roles')->insert(['role_id' => $roleId, 'model_type' => $user->getMorphClass(), 'model_id' => $user->id, 'team_id' => $team->id]);

        return $user;
    }

    private function newUser(string $name): User
    {
        return User::withoutEvents(fn () => User::query()->forceCreate([
            'name' => $name, 'email' => 'prueba-asistente-'.bin2hex(random_bytes(5)).'@example.com', 'password' => bcrypt(bin2hex(random_bytes(16))),
            'locale' => 'es', 'email_verified_at' => now(),
        ]));
    }

    private function rejects(callable $fn): bool
    {
        try {
            $fn();
        } catch (ValidationException) {
            return true;
        }

        return false;
    }

    private function denied(callable $fn): bool
    {
        try {
            $fn();
        } catch (AuthorizationException) {
            return true;
        }

        return false;
    }

    private function check(string $label, bool $ok): void
    {
        $ok ? $this->pass++ : $this->fail++;
        $this->line(($ok ? 'PASS  ' : 'FAIL  ').$label);
    }
}
