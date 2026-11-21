<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ImprovementRequest;
use App\Models\User;
use App\Processes\Events\ProcessArchived;
use App\Processes\Events\ProcessVersionPublished;
use App\Processes\MermaidSvg;
use App\Processes\Models\Area;
use App\Processes\Models\Position;
use App\Processes\Models\PositionAssignment;
use App\Processes\Models\Process;
use App\Processes\Models\ProcessAttachment;
use App\Processes\Models\ProcessVersion;
use App\Processes\ProcessAccess;
use App\Processes\ProcessDemo;
use App\Processes\ProcessDiff;
use App\Processes\Processes;
use App\Processes\ProcessFeedback;
use App\Processes\ProcessLinks;
use App\Tarmio\TarmioTask;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Liberu\Foundation\Organizations\Models\Team;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * «Procesos y conocimiento» end to end with fictitious companies and people: create → review → publish → search → view,
 * new version with diff, immutability, four-eyes, archive, the access matrix of ejemplos-demo.md §1 (list, search,
 * direct URL, version URL and file URL), an ended assignment, another company, events, doubts and page rendering.
 * Database in a rolled-back transaction, private disk faked in a temporary directory: nothing is kept.
 *
 *   php artisan clarto:procesos-prueba
 */
final class TestProcesses extends Command
{
    protected $signature = 'clarto:procesos-prueba';

    protected $description = 'Prueba «Procesos y conocimiento» (ciclo de vida, permisos, búsqueda, adjuntos, páginas); no guarda nada';

    private int $pass = 0;

    private int $fail = 0;

    public function handle(): int
    {
        $root = sys_get_temp_dir().'/clarto-procesos-prueba-'.bin2hex(random_bytes(4));
        Storage::fake(ProcessAttachment::DISK, ['root' => $root]);
        config(['session.driver' => 'array', 'filament.cache_path' => $root.'/no-filament-cache']);
        Event::fake([ProcessVersionPublished::class, ProcessArchived::class]);

        DB::beginTransaction();
        try {
            $this->scenarios();
        } catch (Throwable $e) {
            $at = collect($e->getTrace())->first(fn (array $f): bool => ($f['file'] ?? '') === __FILE__);
            $this->check('sin errores inesperados: '.$e::class.': '.$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine().' (prueba, línea '.($at['line'] ?? '?').')', false);
        } finally {
            DB::rollBack();
            Auth::forgetGuards();
            File::deleteDirectory($root);
        }

        $this->line(sprintf('RESULT: %d passed, %d failed (database rolled back, private disk faked)', $this->pass, $this->fail));

        return $this->fail === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function scenarios(): void
    {
        $team = $this->team('Inmobiliaria Ficticia Procesos');
        $other = $this->team('Otra Empresa Ficticia');
        $demo = ProcessDemo::load($team);
        $people = $demo['people'];
        foreach ($people as $person) {
            $person->forceFill(['disabled_at' => null])->save();   // the demo people are disabled; the test signs them in
        }
        ['ana' => $ana, 'bruno' => $bruno, 'carla' => $carla, 'diego' => $diego, 'elena' => $elena] = $people;
        $p = $demo['processes'];
        $admin = User::query()->findOrFail($team->user_id);
        $outsider = $this->user('Persona Otra Empresa', $other, 'staff', [ProcessAccess::VIEW]);
        $author = $this->user('Fausto Autor Prueba', $team, 'staff', [ProcessAccess::AUTHOR]);
        PositionAssignment::query()->create(['team_id' => $team->id, 'user_id' => $author->id, 'position_id' => $this->position($team, 'gestor_alquileres')]);

        $this->check('demo: solo las áreas y puestos que usan los ejemplos, marcados «(DEMOSTRACIÓN)» y como propuesta; mapa cargado; 4 ejemplos',
            Area::query()->where('team_id', $team->id)->pluck('code')->sort()->values()->all() === ['ADF', 'ALQ', 'DIR', 'SIS', 'VEN']
            && Position::query()->where('team_id', $team->id)->count() === 6
            && Area::query()->where('team_id', $team->id)->get()->every(fn (Area $a): bool => $a->proposal && str_ends_with($a->name, '(DEMOSTRACIÓN)'))
            && Position::query()->where('team_id', $team->id)->get()->every(fn (Position $x): bool => $x->proposal && str_ends_with($x->name, '(DEMOSTRACIÓN)'))
            && DB::table('clarto_process_map_entries')->where('team_id', $team->id)->count() >= 50
            && array_keys($p) === ['SIS-91', 'ALQ-91', 'ADF-91', 'ALQ-92']
            && collect($p)->every(fn (Process $x): bool => str_starts_with($x->currentVersion->title, 'DEMOSTRACIÓN · ')));
        $this->check('demo: los procesos quedan sin responsable ni aprobador y salen en «Responsables sin asignar»',
            collect($p)->every(fn (Process $x): bool => $x->owner_user_id === null && $x->approver_user_id === null && $x->ownerMissing())
            && substr_count((string) $this->get($this->url('gestion-procesos', $team), User::query()->findOrFail($team->user_id))->getContent(), __('procesos.gestion.sin_asignar')) >= 8);
        $again = ProcessDemo::load($team);
        $this->check('demo idempotente: una segunda carga no crea nada', $again['areas'] + $again['positions'] + $again['map'] === 0 && $again['processes'] === []
            && Process::query()->where('team_id', $team->id)->count() === 4);

        // The administration assigns owners and approvers later, without a new version.
        \Filament\Facades\Filament::setCurrentPanel('admin');
        \Filament\Facades\Filament::setTenant($team, isQuiet: true);
        \Livewire\Livewire::actingAs($admin)->test(\App\Filament\Processes\Pages\ProcessAdmin::class)
            ->call('mountAction', 'assign', ['process' => $p['ALQ-91']->id])
            ->set('mountedActions.0.data.owner_user_id', $carla->id)->set('mountedActions.0.data.approver_user_id', $elena->id)->call('callMountedAction');
        $this->check('«Asignar responsable y aprobador» desde la gestión: ALQ-91 queda con Carla y Elena, sin versión nueva y con historial',
            $p['ALQ-91']->refresh()->owner_user_id === $carla->id && $p['ALQ-91']->approver_user_id === $elena->id
            && $p['ALQ-91']->versions()->count() === 1 && $p['ALQ-91']->events()->where('type', 'responsable_cambiado')->exists());
        $this->check('no se puede asignar como responsable a alguien de otra empresa', $this->rejects(fn () => Processes::updateMetadata($admin, $p['ALQ-92'], ['owner_user_id' => $outsider->id])));
        $this->check('quien solo consulta no puede asignar responsables', $this->denied(fn () => Processes::updateMetadata($ana, $p['ALQ-92'], ['owner_user_id' => $ana->id])));
        Processes::updateMetadata($admin, $p['ALQ-92'], ['owner_user_id' => $carla->id, 'approver_user_id' => $elena->id]);

        // 1. Minimal draft → complete → send → send back → resend → publish v1.
        $draft = Processes::createDraft($author, $team->id, ['title' => 'Prueba · Entregar llaves al inquilino', 'area_id' => $this->area($team, 'ALQ')], 'instruccion_breve');
        $process = $draft->process;
        $this->check('borrador con lo mínimo: código ALQ-xx del área, responsable = autor, pasos guía de la plantilla',
            preg_match('/^ALQ-\d{2}$/', $process->code) === 1 && $process->owner_user_id === $author->id && $process->status === 'borrador'
            && count($draft->steps) === 3 && $draft->status === 'borrador' && $draft->publicationGaps() !== []);
        $this->check('no se puede enviar a revisión incompleto', $this->rejects(fn () => Processes::submit($author, $draft)));
        $this->check('quien solo consulta no ve el borrador (ni en la lista ni por enlace)', ! ProcessAccess::canView($ana, $draft) && $this->get(ProcessLinks::version($draft), $ana)->getStatusCode() === 404);

        $steps = [
            ['key' => Processes::newKey(), 'title' => 'Comprobar el acta de entrada', 'actor_position_id' => $this->position($team, 'gestor_alquileres'), 'action' => 'Revise el **acta**.', 'done_when' => 'Acta firmada.', 'blocks' => []],
            ['key' => Processes::newKey(), 'title' => 'Entregar las llaves', 'actor_position_id' => null, 'actor_label' => 'Inquilino', 'action' => 'Entregue dos juegos de llaves.', 'done_when' => 'Recibí firmado.', 'blocks' => []],
        ];
        Processes::saveDraft($author, $draft, ['summary' => 'Entrega de llaves de prueba.', 'objective' => 'Entregar las llaves.', 'expected_result' => 'Llaves entregadas.',
            'trigger' => 'Firma del contrato.', 'steps' => $steps, 'keywords' => ['llaves', 'depósito'], 'change_summary' => 'Primera versión.']);
        Processes::updateMetadata($author, $process, ['approver_user_id' => $elena->id], [$this->position($team, 'gestor_alquileres')]);
        $draft->refresh();
        $this->check('completado: sin huecos para publicar', $draft->publicationGaps() === []);
        Processes::submit($author, $draft);
        $this->check('enviado a revisión: queda bloqueado para editar', $draft->refresh()->status === 'en_revision' && $this->denied(fn () => Processes::saveDraft($author, $draft, ['summary' => 'x'])));
        $this->check('devolver exige comentario', $this->rejects(fn () => Processes::sendBack($elena, $draft, ' ')));
        Processes::sendBack($elena, $draft, 'Añada el plazo de la entrega.');
        $this->check('devuelto con comentarios: vuelve a borrador con review_notes', $draft->refresh()->status === 'borrador' && $draft->review_notes === 'Añada el plazo de la entrega.');
        $steps[1]['deadline'] = 'El día de la firma';
        Processes::saveDraft($author, $draft, ['steps' => $steps]);
        Processes::submit($author, $draft->refresh());

        // 4. Four-eyes.
        $this->check('doble control: el autor (sin ser administrador) no puede publicar su versión',
            ! ProcessAccess::canPublish($author, $draft->refresh()) && $this->denied(fn () => Processes::publish($author, $draft)));
        $this->check('quien solo revisa no puede publicar', ! ProcessAccess::canPublish($bruno, $draft));
        $this->afterCommitNow(fn () => Processes::publish($elena, $draft->refresh()));
        $v1 = $draft->refresh();
        $process->refresh();
        $this->check('publicada v1: número 1, aprobador, proceso publicado y próxima revisión a 12 meses',
            $v1->status === 'publicada' && $v1->number === 1 && $v1->approved_by === $elena->id && ! $v1->self_approved && $process->status === 'publicado'
            && $process->current_version_id === $v1->id && $process->next_review_on?->toDateString() === now()->addMonths(12)->toDateString()
            && ($v1->meta_snapshot['area']['code'] ?? null) === 'ALQ');
        $this->check('evento ProcessVersionPublished de v1 (sin versión sustituida)',
            Event::dispatched(ProcessVersionPublished::class, fn (ProcessVersionPublished $e): bool => $e->version->id === $v1->id && $e->replaced === null)->isNotEmpty());
        $this->check('historial: creado, datos cambiados, enviado, devuelto, enviado y publicado',
            $process->events()->pluck('type')->reverse()->values()->all() === ['creado', 'metadatos_cambiados', 'enviado_revision', 'devuelto', 'enviado_revision', 'publicado']);

        // Search and view.
        $search = fn (User $u, string $q): array => $this->search($u, $team, $q);
        $this->check('búsqueda sin tildes ni mayúsculas: «DEPOSITO» encuentra la nueva (palabra clave «depósito») y ALQ-91',
            in_array($process->code, $search($ana, 'DEPOSITO'), true) && in_array('ALQ-91', $search($ana, 'deposito'), true));
        $view = $this->get(ProcessLinks::process($process), $ana);
        $this->check('consulta: Ana abre el procedimiento publicado (200) con pasos e índice', $view->getStatusCode() === 200
            && str_contains((string) $view->getContent(), 'Entregar las llaves') && str_contains((string) $view->getContent(), 'id="paso-'.$steps[0]['key'].'"'));
        $this->check('«Procesos de mi puesto»: la nueva sale para la gestora (Carla), no para Ana',
            str_contains((string) $this->get($this->url('procesos', $team), $carla)->getContent(), 'Prueba · Entregar llaves') && ! in_array($process->id, $this->mine($ana, $team), true));

        // 3. Immutability.
        $this->check('la versión publicada no se puede modificar (excepción)', $this->throws(fn () => $v1->forceFill(['title' => 'Cambio'])->save(), \LogicException::class)
            && ProcessVersion::query()->find($v1->id)->title === 'Prueba · Entregar llaves al inquilino');

        // 2. New version: diff shows the changed step and the new attachment; v1 becomes «sustituida».
        $v2 = Processes::startRevision($author, $process->refresh());
        $this->check('nueva versión: un solo borrador abierto (reabrir devuelve el mismo)', Processes::startRevision($author, $process)->id === $v2->id && $v2->based_on_version_id === $v1->id);
        $steps[1]['action'] = 'Entregue dos juegos de llaves y el mando del garaje.';
        $upload = Storage::disk(ProcessAttachment::DISK)->putFileAs(ProcessAttachment::uploadDirectory($author->id), UploadedFile::fake()->createWithContent('acta.pdf', $this->pdf()), 'acta.pdf');
        $att = ProcessAttachment::store($author, $v2, $upload, 'acta-entrega.pdf');
        Processes::saveDraft($author, $v2, ['steps' => [[...$steps[0], 'blocks' => [['type' => 'file', 'data' => ['attachment_id' => $att->id, 'label' => 'Acta']]]], $steps[1]], 'change_summary' => 'Añade el mando y el acta.']);
        $diff = collect(ProcessDiff::between($v1, $v2->refresh()));
        $this->check('comparador: paso cambiado, adjunto nuevo y resumen de cambios',
            $diff->contains(fn ($c) => $c['field'] === 'steps' && $c['change'] === 'cambiado' && str_contains(implode(' ', $c['added']), 'mando del garaje'))
            && $diff->contains(fn ($c) => $c['field'] === 'attachments' && $c['change'] === 'anadido' && $c['added'] === ['acta-entrega.pdf']));
        $bad = Storage::disk(ProcessAttachment::DISK)->putFileAs(ProcessAttachment::uploadDirectory($author->id), UploadedFile::fake()->createWithContent('x.pdf', 'no es un pdf'), 'x.pdf');
        $this->check('adjunto: tipo comprobado por contenido (texto con extensión .pdf rechazado y borrado)', $this->rejects(fn () => ProcessAttachment::store($author, $v2, $bad, 'x.pdf')) && ! Storage::disk(ProcessAttachment::DISK)->exists($bad));
        $this->check('adjunto: no se puede usar la subida de otra persona', $this->rejects(fn () => ProcessAttachment::store($ana, $v2, $upload, 'x.pdf')));
        Processes::submit($author, $v2->refresh());
        $this->afterCommitNow(fn () => Processes::publish($elena, $v2->refresh()));
        $this->check('publicada v2: v1 pasa a «sustituida» y la vigente es v2', $v1->refresh()->status === 'sustituida' && $v2->refresh()->number === 2 && $process->refresh()->current_version_id === $v2->id);
        $this->check('evento ProcessVersionPublished de v2 con replaced = v1',
            Event::dispatched(ProcessVersionPublished::class, fn (ProcessVersionPublished $e): bool => $e->version->id === $v2->id && $e->replaced?->id === $v1->id)->isNotEmpty());
        $this->check('enlace a v1 sustituida: quien consulta va a la vigente (302); la gestión la ve (200)',
            $this->get(ProcessLinks::version($v1), $ana)->getStatusCode() === 302 && $this->get(ProcessLinks::version($v1), $elena)->getStatusCode() === 200);
        $this->check('adjunto de la vigente: Ana lo descarga (200, nosniff, privado)', ($r = $this->get(ProcessLinks::attachment($att->refresh()), $ana))->getStatusCode() === 200
            && $r->headers->get('X-Content-Type-Options') === 'nosniff' && str_contains((string) $r->headers->get('Cache-Control'), 'private'));
        $copy = ProcessAttachment::query()->where('process_version_id', $v2->id)->first();
        $this->check('archivo por huella: la copia del borrador comparte ruta y el disco público no lo tiene',
            $copy !== null && str_starts_with($copy->path, "procesos/{$team->id}/{$process->id}/") && ! Storage::disk('public')->exists($copy->path));

        // Admin can publish their own version (self-approved); an approver cannot with four-eyes on.
        $this->check('aprobar no incluye redactar: la aprobadora no crea procesos sin «procesos.redactar»', ! ProcessAccess::canCreate($elena, $team->id));
        ProcessDemo::role($elena, $team->id, 'procesos_prueba_redactora', [ProcessAccess::AUTHOR]);
        $own =Processes::createDraft($elena, $team->id, ['title' => 'Prueba · Aprobadora autora', 'area_id' => $this->area($team, 'DIR'), 'approver_user_id' => $elena->id]);
        $this->completeDraft($elena, $own, $team);
        Processes::submit($elena, $own->refresh());
        $this->check('doble control: la aprobadora no publica su propia versión', ! ProcessAccess::canPublish($elena, $own->refresh()));
        Processes::publish($admin, $own);
        $adminOwn = Processes::createDraft($admin, $team->id, ['title' => 'Prueba · Administración autora', 'area_id' => $this->area($team, 'DIR'), 'approver_user_id' => $elena->id]);
        $this->completeDraft($admin, $adminOwn, $team);
        Processes::submit($admin, $adminOwn->refresh());
        Processes::publish($admin, $adminOwn->refresh());
        $this->check('la administración puede publicar la suya: queda «autoaprobado»', $adminOwn->refresh()->self_approved && $adminOwn->status === 'publicada');

        // 5. Restricted ADF-91: the matrix of ejemplos-demo.md §1.
        $adf = $p['ADF-91'];
        $adfVersion = $adf->currentVersion;
        $adfFile = $adfVersion->attachments()->first();
        $urls = fn (): array => [ProcessLinks::process($adf), ProcessLinks::version($adfVersion), ProcessLinks::attachment($adfFile)];
        $codes = fn (User $u): array => array_map(fn (string $url): int => $this->get($url, $u)->getStatusCode(), $urls());
        $listed = fn (User $u): bool => ProcessAccess::visibleQuery($u, $team->id)->where('clarto_processes.id', $adf->id)->exists();
        $this->check('ADF-91 · Ana (asesora): no está en la lista ni en el buscador; proceso, versión y adjunto → 404',
            ! $listed($ana) && ! in_array('ADF-91', $search($ana, 'ZAFIRO-7'), true) && ! in_array('ADF-91', $search($ana, 'caja'), true) && $codes($ana) === [404, 404, 404]);
        $this->check('ADF-91 · la portada y el buscador de Ana no contienen el texto restringido',
            ! str_contains((string) $this->get($this->url('procesos', $team).'?q=zafiro', $ana)->getContent(), 'ZAFIRO')
            && ! str_contains((string) $this->get($this->url('procesos', $team), $ana)->getContent(), 'Cuadre mensual'));
        $this->check('ADF-91 · Bruno (puesto Administración): lista, buscador, proceso, versión y adjunto → 200',
            $listed($bruno) && in_array('ADF-91', $search($bruno, 'zafiro-7'), true) && $codes($bruno) === [200, 200, 200]
            && str_contains((string) $this->get(ProcessLinks::process($adf), $bruno)->getContent(), 'ZAFIRO-7'));
        $this->check('ADF-91 · Carla (excepción allow por persona) → 200', $listed($carla) && $codes($carla) === [200, 200, 200]);
        $this->check('ADF-91 · Diego (Administración, pero deny por persona) → 404 en todo', ! $listed($diego) && ! in_array('ADF-91', $search($diego, 'zafiro'), true) && $codes($diego) === [404, 404, 404]);
        $this->check('ADF-91 · Elena (gestión): enlace, versión y adjunto → 200 (sin aparecer en la lista)', $codes($elena) === [200, 200, 200]);
        $this->check('ADF-91 · la política de Gate delega en ProcessAccess', Gate::forUser($bruno)->allows('view', $adfVersion) && ! Gate::forUser($ana)->allows('view', $adfVersion));
        $this->check('ADF-91 · «Comunicar una duda» desde el proceso restringido: Ana no puede (404)', $this->throws(fn () => ProcessFeedback::submit($ana, $team->id, '¿Y esto?', $adfVersion), \Symfony\Component\HttpKernel\Exception\HttpException::class));

        // 6. An assignment that ended yesterday removes the access at once.
        PositionAssignment::query()->where('user_id', $bruno->id)->update(['ends_on' => now()->subDay()->toDateString()]);
        $this->check('asignación con fecha de fin ayer: Bruno pierde ADF-91 al momento (lista y enlaces 404)', ! $listed($bruno) && $codes($bruno) === [404, 404, 404]);

        // 7. Another company.
        $this->check('persona de otra empresa: 404 en proceso, versión y adjunto; nada en su lista',
            $codes($outsider) === [404, 404, 404] && $this->get(ProcessLinks::process($p['SIS-91']), $outsider)->getStatusCode() === 404
            && ProcessAccess::visibleQuery($outsider, $team->id)->count() === 0 && ! ProcessAccess::canDownload($outsider, $att));
        $this->check('sin sesión: el adjunto no se sirve', $this->get(ProcessLinks::attachment($att), null)->getStatusCode() !== 200);

        // Doubts linked to «Solicitud de mejoras» and the process owner.
        $alq91 = $p['ALQ-91'];
        $request = ProcessFeedback::submit($ana, $team->id, 'No sé dónde se sube el justificante.', $alq91->currentVersion, $alq91->currentVersion->steps[4]['key']);
        $this->check('«Comunicar una duda o mejora»: solicitud «[Procesos] ALQ-91 · …» con proceso, versión y paso',
            str_starts_with($request->title, '[Procesos] ALQ-91 · ') && DB::table('clarto_process_feedback')->where('improvement_request_id', $request->id)
                ->where('process_version_id', $alq91->current_version_id)->where('step_key', 'd2paso05')->where('source', 'lector')->exists());
        $this->check('la responsable del proceso (Carla) ve y puede responder la duda; otra compañera no',
            Gate::forUser($carla)->allows('view', $request) && Gate::forUser($carla)->allows('update', $request) && ! Gate::forUser($diego)->allows('view', $request));

        // 8. Archive.
        $alq92 = $p['ALQ-92'];
        $this->check('archivar exige motivo', $this->rejects(fn () => Processes::archive($carla, $alq92, '')));
        $this->check('quien solo consulta no puede archivar', $this->denied(fn () => Processes::archive($ana, $alq92, 'x')));
        $current92 = $alq92->currentVersion;
        $this->afterCommitNow(fn () => Processes::archive($carla, $alq92, 'Sustituido por el procedimiento de la nueva plataforma.'));
        $this->check('archivado: evento ProcessArchived, versión «archivada», fuera de la lista y del buscador, enlace 404',
            Event::dispatched(ProcessArchived::class, fn (ProcessArchived $e): bool => $e->process->id === $alq92->id && $e->version?->id === $current92->id)->isNotEmpty()
            && $current92->refresh()->status === 'archivada' && ! in_array('ALQ-92', $search($ana, 'viajeros'), true)
            && $this->get(ProcessLinks::process($alq92), $ana)->getStatusCode() === 404 && $this->get(ProcessLinks::process($alq92), $elena)->getStatusCode() === 200);
        $restored = Processes::startRevision($elena, $alq92->refresh());
        $this->check('restaurar: abre un borrador desde la última versión (evento «restaurado»)', $restored->status === 'borrador' && $restored->based_on_version_id === $current92->id
            && $alq92->events()->where('type', 'restaurado')->exists());

        // Review reminders in TARMIO.
        $alq91->forceFill(['next_review_on' => now()->addDays(10)->toDateString()])->save();
        Artisan::call('clarto:procesos-revisiones');
        $task = TarmioTask::query()->where('source_type', $alq91->getMorphClass())->where('source_id', $alq91->id)->where('kind', 'revision_proceso')->first();
        $this->check('revisión periódica: tarea de TARMIO «Revisar procedimiento ALQ-91» a la responsable', $task?->title === 'Revisar procedimiento ALQ-91' && $task->assignee_email === strtolower($carla->email));

        // Rendering of the main pages.
        $page = (string) $this->get(ProcessLinks::process($alq91), $ana)->getContent();
        $this->check('página ALQ-91: resumen, índice, 7 pasos, decisiones, diagrama SVG, adjunto por ruta privada y «Comunicar una duda»',
            str_contains($page, 'id="resumen"') && substr_count($page, 'class="clp-step ') === 7 && str_contains($page, 'id="decision-d2deci01"')
            && str_contains($page, '<svg') && str_contains($page, 'cl-mm-decision') && str_contains($page, '/filament/clarto/procesos/adjunto/')
            && str_contains($page, __('procesos.ver.duda')) && str_contains($page, __('procesos.ver.paso_a_paso')));
        $this->check('diagrama Mermaid: se dibuja en SVG sin JavaScript y el código no válido no se dibuja',
            ($svg = MermaidSvg::render($alq91->currentVersion->diagram_mermaid)) !== null && substr_count($svg, '<text') >= 11
            && MermaidSvg::render("flowchart TD\n A[x] --> B\n click A \"javascript:alert(1)\"") === null && MermaidSvg::render('sequenceDiagram') === null);
        foreach (['procesos' => 'Procesos de mi puesto', 'gestion-procesos' => __('procesos.gestion.pendientes'), 'procesos-areas' => 'ALQ', 'procesos-asignaciones' => 'Ana Demo'] as $slug => $needle) {
            $response = $this->get($this->url($slug, $team), $admin);
            $this->check("página /{$slug} para la administración (200)", $response->getStatusCode() === 200 && str_contains((string) $response->getContent(), $needle));
        }
        $editor = $this->get(ProcessLinks::process($alq92).'/editar', $elena);
        $this->check('editor del borrador restaurado (200) con pestañas y completitud', $editor->getStatusCode() === 200 && str_contains((string) $editor->getContent(), __('procesos.editor.tab_pasos')));
        $this->check('Ana no entra en la gestión ni en «Áreas y puestos» (403)', $this->get($this->url('gestion-procesos', $team), $ana)->getStatusCode() === 403
            && $this->get($this->url('procesos-areas', $team), $ana)->getStatusCode() === 403);
        $this->check('Ana no puede editar ALQ-91 (404)', $this->get(ProcessLinks::process($alq91).'/editar', $ana)->getStatusCode() === 404);
        $this->interactions($team, $people, $author, $p['ALQ-91'], $process);

        $this->check('los dos eventos se lanzan solo tras confirmar la transacción (ShouldDispatchAfterCommit)',
            is_subclass_of(ProcessVersionPublished::class, \Illuminate\Contracts\Events\ShouldDispatchAfterCommit::class)
            && is_subclass_of(ProcessArchived::class, \Illuminate\Contracts\Events\ShouldDispatchAfterCommit::class));
    }

    /**
     * The Livewire screens as a person uses them: personal checklist and favourite, «Comunicar una duda o mejora»,
     * the editor (save with a new file block → private attachment, then send to review) and the review modal.
     *
     * @param  array<string, User>  $people
     */
    private function interactions(Team $team, array $people, User $author, Process $alq91, Process $process): void
    {
        \Filament\Facades\Filament::setCurrentPanel('admin');
        \Filament\Facades\Filament::setTenant($team, isQuiet: true);
        $view = \App\Filament\Processes\Pages\ProcessView::class;
        $editor = \App\Filament\Processes\Pages\ProcessEditor::class;

        \Livewire\Livewire::actingAs($people['carla'])->test($view, ['process' => $alq91->id])
            ->call('toggleStep', 'd2paso01')->call('toggleStep', 'd2paso02')->call('toggleStep', 'd2paso01')->call('toggleFavorite')->call('confirmRead');
        $carla = \App\Processes\Models\ProcessUserState::query()->where('user_id', $people['carla']->id)->where('process_id', $alq91->id)->first();
        $this->check('lista personal: Carla marca y desmarca pasos, favorito y «He leído la versión 1»; Ana no ve sus marcas',
            $carla?->checked_steps === ['d2paso02'] && $carla->favorite && $carla->read_confirmed_version_id === $alq91->current_version_id
            && \App\Processes\Models\ProcessUserState::query()->where('user_id', $people['ana']->id)->where('process_id', $alq91->id)->value('checked_steps') === null);
        rescue(fn () => \Livewire\Livewire::actingAs($people['ana'])->test($view, ['process' => $alq91->id])->call('toggleStep', 'no-existe'), report: false);
        $this->check('lista personal: un paso que no existe no se guarda',
            ! in_array('no-existe', (array) \App\Processes\Models\ProcessUserState::query()->where('user_id', $people['ana']->id)->where('process_id', $alq91->id)->value('checked_steps'), true));

        $before = ImprovementRequest::query()->count();
        \Livewire\Livewire::actingAs($people['ana'])->test($view, ['process' => $alq91->id])
            ->call('mountAction', 'duda')->set('mountedActions.0.data.text', 'Duda enviada desde la página.')->set('mountedActions.0.data.step', 'd2paso03')->call('callMountedAction');
        $this->check('«Comunicar una duda o mejora» desde la página: crea la solicitud vinculada al paso',
            ImprovementRequest::query()->count() === $before + 1 && DB::table('clarto_process_feedback')->where('process_id', $alq91->id)->where('step_key', 'd2paso03')->exists());

        $upload = Storage::disk(ProcessAttachment::DISK)->putFileAs(ProcessAttachment::uploadDirectory($author->id), UploadedFile::fake()->createWithContent('guia.pdf', $this->pdf()), 'guia.pdf');
        $component = \Livewire\Livewire::actingAs($author)->test($editor, ['process' => $process->id]);
        $draft = $process->refresh()->openVersion;
        $component->set('data.summary', 'Resumen cambiado desde el editor.')->set('data.change_summary', 'Añade la guía.')
            ->set('data.blocks', ['b1' => ['type' => 'file', 'data' => ['upload' => ['f1' => $upload], 'upload_name' => [$upload => 'guia-llaves.pdf'], 'label' => 'Guía']]])
            ->call('save');
        $draft = $draft?->refresh();
        $block = $draft?->blocks[0] ?? [];
        $this->check('editor: «Nueva versión» abre un borrador y «Guardar» guarda el texto y convierte la subida en adjunto privado',
            $draft !== null && $draft->status === 'borrador' && $draft->summary === 'Resumen cambiado desde el editor.'
            && ($block['type'] ?? null) === 'file' && ! isset($block['data']['upload'])
            && ProcessAttachment::query()->whereKey($block['data']['attachment_id'] ?? 0)->where('process_version_id', $draft->id)->value('original_name') === 'guia-llaves.pdf');
        \Livewire\Livewire::actingAs($author)->test($editor, ['process' => $process->id])->call('saveAndSubmit');
        $this->check('editor: «Guardar y enviar a revisión» deja la versión en revisión', $draft?->refresh()->status === 'en_revision');

        \Livewire\Livewire::actingAs($people['elena'])->test($view, ['process' => $process->id, 'v' => $draft->id])
            ->call('mountAction', 'revision')->set('mountedActions.0.data.decision', 'publicar')->call('callMountedAction');
        $this->check('revisión desde la página: la aprobadora publica la v3 con el comparador', $draft->refresh()->status === 'publicada' && $draft->number === 3);
    }

    /**
     * The test runs inside a transaction that is never committed, so after-commit events would never be recorded:
     * while $fn runs, the event fake sees a transaction manager that runs its callbacks at once (the database keeps
     * its own manager).
     */
    private function afterCommitNow(callable $fn): void
    {
        $real = app('db.transactions');
        app()->instance('db.transactions', new class extends \Illuminate\Database\DatabaseTransactionsManager
        {
            public function addCallback($callback)
            {
                $callback();
            }
        });
        try {
            $fn();
        } finally {
            app()->instance('db.transactions', $real);
        }
    }

    private function completeDraft(User $by, ProcessVersion $draft, Team $team): void
    {
        Processes::saveDraft($by, $draft, ['summary' => 'Prueba.', 'objective' => 'Probar.', 'expected_result' => 'Probado.', 'trigger' => 'Siempre.',
            'steps' => [['key' => Processes::newKey(), 'title' => 'Hacer', 'actor_position_id' => $this->position($team, 'direccion'), 'action' => 'Hacerlo.', 'done_when' => 'Hecho.']]]);
        Processes::updateMetadata($by, $draft->process, [], [$this->position($team, 'direccion')]);
    }

    /** @return list<string> codes found by the home search (same query as ProcessHome) */
    private function search(User $user, Team $team, string $q): array
    {
        $query = ProcessAccess::visibleQuery($user, $team->id);
        foreach (preg_split('/\s+/u', ProcessVersion::normalize($q)) ?: [] as $term) {
            $query->where('clarto_process_versions.search_text', 'like', '%'.$term.'%');
        }

        return Process::query()->whereIn('id', $query->pluck('clarto_process_versions.process_id'))->pluck('code')->all();
    }

    /** @return list<int> */
    private function mine(User $user, Team $team): array
    {
        $positions = ProcessAccess::positionIds($user, $team->id);

        return ProcessAccess::visibleQuery($user, $team->id)->whereExists(fn ($s) => $s->selectRaw('1')->from('clarto_process_positions')
            ->whereColumn('clarto_process_positions.process_id', 'clarto_processes.id')->whereIn('position_id', $positions ?: [0]))->pluck('clarto_process_versions.process_id')->map('intval')->all();
    }

    private function url(string $slug, Team $team): string
    {
        return url("/admin/{$team->id}/{$slug}");
    }

    private function area(Team $team, string $code): int
    {
        return (int) Area::query()->where('team_id', $team->id)->where('code', $code)->value('id');
    }

    private function position(Team $team, string $key): int
    {
        return (int) Position::query()->where('team_id', $team->id)->where('key', $key)->value('id');
    }

    private function pdf(): string
    {
        return \App\Signing\SimplePdf::make('Acta de entrega', 'Documento ficticio de prueba.');
    }

    private function team(string $name): Team
    {
        $owner = $this->newUser("Titular {$name}");
        $team = Team::withoutEvents(fn () => Team::query()->forceCreate(['user_id' => $owner->id, 'name' => $name, 'personal_team' => false]));
        $owner->forceFill(['current_team_id' => $team->id])->saveQuietly();
        ProcessDemo::role($owner, $team->id, 'admin');

        return $team;
    }

    /** @param list<string> $permissions */
    private function user(string $name, Team $team, string $role, array $permissions = []): User
    {
        $user = $this->newUser($name);
        $user->forceFill(['current_team_id' => $team->id])->saveQuietly();
        DB::table('team_user')->insert(['team_id' => $team->id, 'user_id' => $user->id, 'role' => 'editor', 'created_at' => now(), 'updated_at' => now()]);
        ProcessDemo::role($user, $team->id, $role);
        if ($permissions !== []) {
            ProcessDemo::role($user, $team->id, 'procesos_prueba_'.$role.'_'.$user->id, $permissions);
        }

        return $user;
    }

    private function newUser(string $name): User
    {
        return User::withoutEvents(fn () => User::query()->forceCreate([
            'name' => $name, 'email' => 'prueba-procesos-'.bin2hex(random_bytes(5)).'@example.test', 'password' => bcrypt(bin2hex(random_bytes(16))),
            'locale' => 'es', 'email_verified_at' => now(),
        ]));
    }

    private function get(string $uri, ?User $user): Response
    {
        Auth::forgetGuards();
        app('session')->driver()->flush();
        if ($user !== null) {
            Auth::guard('web')->setUser($user->refresh());
        }

        return app(Kernel::class)->handle(Request::create($uri, 'GET'));
    }

    private function rejects(callable $fn): bool
    {
        return $this->throws($fn, ValidationException::class);
    }

    private function denied(callable $fn): bool
    {
        return $this->throws($fn, AuthorizationException::class);
    }

    private function throws(callable $fn, string $class): bool
    {
        try {
            $fn();
        } catch (Throwable $e) {
            return $e instanceof $class;
        }

        return false;
    }

    private function check(string $label, bool $ok): void
    {
        $ok ? $this->pass++ : $this->fail++;
        $this->line(($ok ? 'PASS  ' : 'FAIL  ').$label);
    }
}
