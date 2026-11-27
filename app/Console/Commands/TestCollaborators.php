<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Collaborators\Collaborations;
use App\Collaborators\Collaborator;
use App\Collaborators\CollaboratorAccess;
use App\Collaborators\CollaboratorNotice;
use App\Collaborators\CollaboratorNotices;
use App\Collaborators\CollaboratorProperty;
use App\Collaborators\PropertyUpdate;
use App\Filament\Colaboradores\Pages\CollaboratorManual;
use App\Filament\Colaboradores\Resources\MyPropertyResource;
use App\Documents\CompanyDocument;
use App\Documents\CompanyDocumentFolder;
use App\Filament\ManualChat;
use App\Filament\ManualGlobalSearchProvider;
use App\Filament\PanelDataSearch;
use App\SearchRequests\SearchRequest;
use App\SearchRequests\SearchRequests;
use App\Filament\Pages\UserManual;
use App\Filament\Colaboradores\Resources\MyPropertyResource\Pages\ListMyProperties;
use App\Models\Communication;
use App\Models\User;
use App\Models\WhatsAppLine;
use App\Modules\ClartoModules;
use App\Tarmio\TarmioTask;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Mail\MailManager;
use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\WhatsApp\WhatsApp;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\RealEstate\Parties\Models\Party;
use Liberu\RealEstate\Properties\Models\Property;
use Throwable;

/**
 * «Colaboradores» end to end with fictitious data and SIMULATED providers (WAHA via Http::fake, e-mail to the
 * in-memory "array" mailer): two collaborators, two properties; each sees only theirs (list, global search, record,
 * API, attachments, export); a direct link to the other's record is blocked; shared updates go once by e-mail and
 * WhatsApp; unassigning revokes access and cancels pending notices; an offer waits for review. Rolled back.
 *
 *   php artisan clarto:colaboradores-prueba
 */
final class TestCollaborators extends Command
{
    protected $signature = 'clarto:colaboradores-prueba';

    protected $description = 'Test collaborators: access isolation, shared updates by e-mail and WhatsApp (simulated), withdrawal (nothing is kept)';

    private int $pass = 0;

    private int $fail = 0;

    private bool $wahaDown = false;

    /** @var list<array<string, mixed>> */
    private array $whatsappSent = [];

    /** @var list<string> */
    private array $files = [];

    public function handle(): int
    {
        config([
            'whatsapp.url' => 'http://waha.prueba:3000', 'whatsapp.api_key' => 'clave-prueba', 'whatsapp.session' => 'default',
            'mail.default' => 'array', 'tarmio.driver' => 'simulado', 'queue.default' => 'sync',
        ]);
        Mail::forgetMailers();
        $this->fakeWaha();

        DB::beginTransaction();
        try {
            $this->scenarios();
        } catch (Throwable $e) {
            $this->check('sin excepciones inesperadas', false, $e::class.': '.$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine());
        } finally {
            DB::rollBack();
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();   // the test's roles and permissions are gone
            foreach ($this->files as $path) {
                Storage::disk('local')->delete($path);
            }
            auth()->guard('web')->logout();
        }

        $this->line(sprintf('RESULT: %d passed, %d failed (WAHA and e-mail simulated; database rolled back)', $this->pass, $this->fail));

        return $this->fail === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function scenarios(): void
    {
        $team = Team::query()->oldest()->firstOrFail();
        $teamId = (int) $team->getKey();
        $staff = User::query()->findOrFail($team->user_id);
        $staff->forceFill(['current_team_id' => $teamId]);
        $this->actAs($staff);
        if (in_array('whatsapp', ClartoModules::keys(), true)) {
            ClartoModules::enable('whatsapp');
        }
        ClartoModules::enable(Collaborations::MODULE);
        WhatsAppLine::forTeam($teamId)->update(['status' => WhatsAppLine::WORKING]);

        // Fictitious data.
        $tag = Str::upper(Str::random(5));
        $p1 = $this->property($teamId, (int) $staff->id, "Piso ficticio Colaboración {$tag} Alfa", 250000);
        $p2 = $this->property($teamId, (int) $staff->id, "Piso ficticio Colaboración {$tag} Beta", 410000);
        $ana = Collaborator::query()->create(['team_id' => $teamId, 'kind' => 'persona', 'name' => 'Ana Ficticia Colaboradora', 'email' => "ana.{$tag}@example.test",
            'whatsapp' => '600 111 222', 'locale' => 'es', 'owner_id' => $staff->id, 'status' => Collaborator::PENDING, 'notes' => 'NOTA-INTERNA-COLABORADOR', 'created_by' => $staff->id]);
        $inmo = Collaborator::query()->create(['team_id' => $teamId, 'kind' => 'empresa', 'name' => 'Inmo Ficticia SL', 'legal_name' => 'Inmobiliaria Ficticia de Pruebas SL',
            'contacts' => [['name' => 'Bruno Contacto', 'role' => 'Gerente', 'email' => "bruno.{$tag}@example.test", 'phone' => '600333444']],
            'email' => "inmo.{$tag}@example.test", 'whatsapp' => '+34 600 333 444', 'locale' => 'en', 'owner_id' => $staff->id, 'status' => Collaborator::PENDING, 'created_by' => $staff->id]);

        // 1. Invitation: panel user without team, role «colaborador», link to the collaborator panel.
        $r1 = Collaborations::invite($ana);
        $r2 = Collaborations::invite($inmo);
        $ana->refresh();
        $inmo->refresh();
        $mails = collect(app(MailManager::class)->mailer('array')->getSymfonyTransport()->messages());
        $invitation = $mails->first(fn ($m) => collect($m->getOriginalMessage()->getTo())->contains(fn ($a) => $a->getAddress() === $ana->email));
        $anaUser = User::query()->findOrFail($ana->user_id);
        $inmoUser = User::query()->findOrFail($inmo->user_id);
        $this->check('invitación enviada a los dos colaboradores', $r1['ok'] && $r2['ok'] && $ana->invited_at !== null, $r1['message'].' / '.$r2['message']);
        $this->check('el enlace de la invitación lleva al panel de colaboradores', $invitation !== null && str_contains((string) $invitation->getOriginalMessage()->getTextBody().(string) $invitation->getOriginalMessage()->getHtmlBody(), '/colaboradores/password-reset'));
        $this->check('usuario del colaborador sin equipo y con rol «colaborador»', $anaUser->current_team_id === null && $anaUser->allTeams()->isEmpty()
            && $anaUser->hasRoleInAnyTeam([CollaboratorAccess::ROLE]) && CollaboratorAccess::isCollaborator($anaUser) && ! CollaboratorAccess::isCollaborator($staff));
        event(new PasswordReset($anaUser));
        event(new PasswordReset($inmoUser));
        $this->check('al crear su contraseña el colaborador queda activo', $ana->refresh()->status === Collaborator::ACTIVE && $inmo->refresh()->status === Collaborator::ACTIVE);

        // 2. Before any assignment the role gives nothing.
        $this->check('sin asignaciones no ve ningún inmueble (el rol no da acceso al inventario)', CollaboratorAccess::scopeProperties(Property::query(), $anaUser)->count() === 0);
        $this->check('no entra en /admin ni en /app; sí en /colaboradores', ! $anaUser->canAccessPanel(Filament::getPanel('admin')) && ! $anaUser->canAccessPanel(Filament::getPanel('app'))
            && $anaUser->canAccessPanel(Filament::getPanel(CollaboratorAccess::PANEL)) && ! $staff->canAccessPanel(Filament::getPanel(CollaboratorAccess::PANEL)));

        // 3. Assignment (announced): «alta» notice once by e-mail and WhatsApp.
        $this->actAs($staff);
        $a1 = Collaborations::assign($p1, $ana, 'aportado', null, true, 'Bienvenida de prueba');
        $a2 = Collaborations::assign($p2, $inmo, 'comercializacion', 405000, true);
        $alta = CollaboratorNotice::query()->where('collaborator_id', $ana->id)->latest('id')->first();
        $this->check('alta: aviso enviado por correo y WhatsApp', $alta?->status === CollaboratorNotice::SENT && $alta->email_status === 'enviado' && $alta->whatsapp_status === 'enviado', (string) $alta?->last_error);
        $this->check('alta: el aviso incluye el resumen completo (precio autorizado, ubicación, firma, tuteo)', str_contains((string) $alta?->email_body, '250.000 €')
            && str_contains((string) $alta?->email_body, 'Equipo CLARTO') && str_contains((string) $alta?->email_body, 'Puedes responder') && str_contains(implode("\n", $alta?->whatsapp_blocks ?? []), '250.000 €'));
        $inmoAlta = CollaboratorNotice::query()->where('collaborator_id', $inmo->id)->latest('id')->first();
        $this->check('alta en el idioma del colaborador (inglés)', str_contains((string) $inmoAlta?->email_body, 'Authorised marketing price: 405.000 €'));
        $this->check('TARMIO: tarea de seguimiento de la colaboración para el responsable', TarmioTask::query()->where('source_type', $a1->getMorphClass())->where('source_id', $a1->id)->where('kind', 'colaboracion_seguimiento')->exists());

        // 4. Each collaborator sees only their own property: list, search, record, API, export.
        $this->actAs($anaUser);
        Filament::setCurrentPanel(Filament::getPanel(CollaboratorAccess::PANEL));
        $own = MyPropertyResource::getEloquentQuery()->pluck('id')->all();
        $this->check('listado: Ana solo ve su inmueble', $own === [$p1->id]);
        $search = MyPropertyResource::getGlobalSearchResults($tag)->map(fn ($r) => $r->title)->implode(' | ');
        $this->check('búsqueda global: solo su inmueble', str_contains($search, $p1->reference()) && ! str_contains($search, $p2->reference()), $search);
        $this->check('ficha: puede ver la suya y no la ajena', MyPropertyResource::canView($p1) && ! MyPropertyResource::canView($p2));
        $list = $this->get('/colaboradores/inmuebles');
        $this->check('página «Mis inmuebles» (HTTP): su inmueble sí, el ajeno no', $list->getStatusCode() === 200 && str_contains((string) $list->getContent(), $p1->title) && ! str_contains((string) $list->getContent(), $p2->title), $this->describe($list));
        $view = $this->get('/colaboradores/inmuebles/'.$p1->id);
        $this->check('su ficha (HTTP 200) con precio autorizado y sin nota interna', $view->getStatusCode() === 200 && str_contains((string) $view->getContent(), '250.000 €') && ! str_contains((string) $view->getContent(), 'NOTA-INTERNA'), $this->describe($view));
        $this->check('enlace directo a la ficha ajena: bloqueado (404)', $this->get('/colaboradores/inmuebles/'.$p2->id)->getStatusCode() === 404);
        $this->check('/admin y /app: bloqueados (403)', $this->get('/admin')->getStatusCode() === 403 && $this->get('/app')->getStatusCode() === 403);
        $this->manualChecks($anaUser, $staff, $teamId);
        $this->dataChatChecks($team, $staff, $ana, $inmo, $p1, $p2, $tag);
        $this->actAs($anaUser);
        Filament::setCurrentPanel(Filament::getPanel(CollaboratorAccess::PANEL));
        $this->check('ficha de inmueble de /admin por enlace directo: bloqueada', in_array($this->get('/admin/'.$teamId.'/properties/'.$p1->id.'/edit')->getStatusCode(), [403, 404], true));
        $api = json_decode((string) $this->get('/api/v1/colaboradores/inmuebles', json: true)->getContent(), true);
        $this->check('API de colaboradores: solo su inmueble', collect($api['data'] ?? [])->pluck('id')->all() === [$p1->id], json_encode($api));
        $this->check('API de colaboradores: ficha ajena 404', $this->get('/api/v1/colaboradores/inmuebles/'.$p2->id, json: true)->getStatusCode() === 404);
        $this->check('API general del inventario: cerrada al colaborador (403)', $this->get('/api/v1/real-estate/properties', json: true)->getStatusCode() === 403);
        $this->check('/storage (fotos y archivos): cerrado al colaborador', $this->get('/_auth/check')->getStatusCode() !== 204);
        app()->setLocale($ana->locale); // the export is in the language of whoever exports (SetLocale in a real request)
        $csv = ListMyProperties::export($anaUser);
        ob_start();
        $csv->sendContent();
        $csvText = (string) ob_get_clean();
        $this->check('exportación CSV: solo su inmueble', str_contains($csvText, $p1->reference()) && ! str_contains($csvText, $p2->reference()));
        $this->check('exportación CSV: relación, disponibilidad y estado con los nombres de la pantalla (no «aportado», «disponible»…)',
            ($row = str_getcsv(explode("\n", trim($csvText))[1] ?? '', ';')) !== [] && ($row[7] ?? null) === 'Aportado por el colaborador'
            && ($row[9] ?? null) === __('colaboradores.availability.'.$a1->refresh()->availability) && ($row[10] ?? null) === __('colaboradores.commercial.'.$a1->commercial_status)
            && ($row[9] ?? '') !== $a1->availability, $csvText);
        $this->textChecks($anaUser, $staff);
        $this->actAs($anaUser);
        Filament::setCurrentPanel(Filament::getPanel(CollaboratorAccess::PANEL));

        // 5. Shared update (price): one notice, e-mail + WhatsApp once; a retry does not duplicate.
        $this->actAs($staff);
        $file = 'colaboradores/'.$teamId.'/prueba-'.$tag.'.pdf';
        Storage::disk('local')->put($file, "%PDF-1.4\n% ficticio\n");
        $this->files[] = $file;
        $sentBefore = count($this->whatsappSent);
        $price = Collaborations::share($p1, ['kind' => 'precio', 'price' => 239000, 'body' => 'Bajamos el precio para cerrar antes del verano.',
            'attachments' => [['path' => $file, 'name' => 'nota-precio.pdf', 'mime' => 'application/pdf', 'size' => 20]]]);
        $notice = $price->notices()->first();
        $comms = Communication::query()->where('related_type', (new CollaboratorNotice)->getMorphClass())->where('related_id', (string) $notice?->id)->count();
        $this->check('novedad compartida: aviso por correo y WhatsApp', $notice?->status === CollaboratorNotice::SENT && $comms === 2 && count($this->whatsappSent) === $sentBefore + 1, (string) $notice?->last_error);
        $this->check('solo a los colaboradores del inmueble (Inmo no recibe nada de Alfa)', $price->notices()->count() === 1 && (int) $notice->collaborator_id === $ana->id);
        $this->check('el precio autorizado que ve Ana cambia a 239.000 €', (float) $a1->refresh()->authorised_price === 239000.0);
        CollaboratorNotices::dispatch($notice);
        CollaboratorNotices::send($ana, $price, "novedad:{$price->id}:colaborador:{$ana->id}", 'x', 'x', ['x']);
        $this->check('reintentar / repetir el envío conserva un solo mensaje por canal', Communication::query()->where('related_type', $notice->getMorphClass())->where('related_id', (string) $notice->id)->count() === 2
            && count($this->whatsappSent) === $sentBefore + 1 && CollaboratorNotice::query()->where('dedupe_key', $notice->dedupe_key)->count() === 1);
        $this->actAs($anaUser);
        $dl = $this->get(route('colaboradores.adjunto', ['update' => $price->id, 'index' => 0], false));
        $this->check('adjunto: Ana lo descarga', $dl->getStatusCode() === 200, $this->describe($dl));
        $this->actAs($inmoUser);
        $this->check('adjunto: Inmo no (404)', $this->get(route('colaboradores.adjunto', ['update' => $price->id, 'index' => 0], false))->getStatusCode() === 404);
        $this->check('Inmo no ve la novedad de Alfa', ! CollaboratorAccess::canSee($inmoUser, $price) && CollaboratorAccess::canSee($anaUser, $price));

        // 6. Internal note: never visible nor sent.
        $this->actAs($staff);
        $note = Collaborations::note($p1, 'NOTA-INTERNA: el propietario acepta bajar a 230.000.');
        $this->check('nota interna: sin avisos y fuera del panel del colaborador', $note->notices()->count() === 0 && ! CollaboratorAccess::canSee($anaUser, $note)
            && ! CollaboratorAccess::scopeUpdates(PropertyUpdate::query(), $anaUser)->whereKey($note->id)->exists());

        // 7. Visit with date, status and result.
        $visit = Collaborations::share($p1, ['kind' => 'visita', 'visit_at' => now('Europe/Madrid')->addDays(2)->setTime(17, 30)->utc(), 'visit_status' => 'programada', 'next_steps' => 'Confirmar asistencia el día antes.']);
        $vn = $visit->notices()->first();
        $this->check('visita: aviso con fecha y estado', $vn?->status === CollaboratorNotice::SENT && str_contains((string) $vn->email_body, 'Programada') && str_contains((string) $vn->email_body, '17:30'));

        // 8. Offer: nothing leaves until the team reviews the terms; the reviewed text is what is sent.
        $offer = Collaborations::share($p1, ['kind' => 'oferta', 'conditions' => 'BORRADOR condiciones', 'body' => 'Hay una oferta.']);
        $this->check('oferta: queda pendiente de revisión, sin avisos', $offer->status === PropertyUpdate::PENDING_REVIEW && $offer->notices()->count() === 0
            && ! CollaboratorAccess::canSee($anaUser, $offer));
        $this->check('TARMIO: tarea de revisión de la oferta', TarmioTask::query()->where('source_type', $offer->getMorphClass())->where('source_id', $offer->id)->where('kind', 'colaboracion_revision')->exists());
        $offer = Collaborations::approve($offer, ['conditions' => 'Oferta de 235.000 € con arras del 10 %, firma en 30 días.']);
        $on = $offer->notices()->first();
        $this->check('oferta revisada: se envía con las condiciones revisadas', $on?->status === CollaboratorNotice::SENT && str_contains((string) $on->email_body, 'arras del 10 %')
            && ! str_contains((string) $on->email_body, 'BORRADOR') && $offer->reviewed_by === $staff->id && $a1->refresh()->commercial_status === 'con_oferta');

        // 9. Unassign: access revoked and pending notices cancelled (and not sent by a later retry).
        $this->wahaDown = true;
        $pending = Collaborations::share($p2, ['kind' => 'visita', 'visit_at' => now()->addDay(), 'visit_status' => 'aplazada', 'body' => 'La visita pasa a la semana que viene.']);
        $this->wahaDown = false;
        $pn = $pending->notices()->first();
        $this->check('WhatsApp caído: el aviso queda pendiente de reintento (correo enviado)', $pn?->status === CollaboratorNotice::PARTIAL && $pn->email_status === 'enviado' && $pn->whatsapp_status === 'error');
        $sentBefore = count($this->whatsappSent);
        $cancelled = Collaborations::revoke($a2, 'Fin de la colaboración (prueba)');
        CollaboratorNotices::retryOpen();
        $this->check('retirar la asignación cancela los avisos pendientes', $cancelled === 1 && $pn->refresh()->status === CollaboratorNotice::CANCELLED && count($this->whatsappSent) === $sentBefore);
        $this->actAs($inmoUser);
        $record = $this->get('/colaboradores/inmuebles/'.$p2->id);
        $apiAfter = (string) $this->get('/api/v1/colaboradores/inmuebles', json: true)->getContent();
        $this->check('retirada: Inmo deja de ver el inmueble (listado, ficha, API)', CollaboratorAccess::scopeProperties(Property::query(), $inmoUser)->count() === 0
            && $record->getStatusCode() === 404 && collect(json_decode($apiAfter, true)['data'] ?? [])->isEmpty(), $this->describe($record).' · '.$apiAfter);
        $this->actAs($staff);
        $late = CollaboratorNotices::send($inmo, $pending, 'prueba-tardia:'.$pending->id, 'x', 'x', ['x']);
        $this->check('un aviso pendiente comprueba el acceso antes de enviarse (sin acceso: cancelado)', $late->status === CollaboratorNotice::CANCELLED);

        // 10. Nothing about collaborators reaches GoHighLevel (owner's decision), even when a collaborator's number or
        //     e-mail is also a GHL contact. Control: the same hooks do reach GHL for an ordinary contact.
        $this->checkNoGhl($teamId, $ana, $inmo, $tag);

        // 11. Deactivation and the bare role.
        Collaborations::deactivate($ana);
        $this->check('colaborador de baja: sin panel y sin inmuebles', ! User::query()->find($anaUser->id)->canAccessPanel(Filament::getPanel(CollaboratorAccess::PANEL))
            && CollaboratorAccess::scopeProperties(Property::query(), $anaUser)->count() === 0);
        $bare = User::query()->create(['name' => 'Rol Suelto Ficticio', 'email' => "rol.{$tag}@example.test"]);
        $roleId = DB::table('roles')->where('team_id', $teamId)->where('name', CollaboratorAccess::ROLE)->value('id');
        DB::table('model_has_roles')->insert(['role_id' => $roleId, 'model_type' => $bare->getMorphClass(), 'model_id' => $bare->id, 'team_id' => $teamId]);
        $this->check('solo el rol «Colaborador» (sin ficha): ningún acceso', CollaboratorAccess::isCollaborator($bare) && CollaboratorAccess::scopeProperties(Property::query(), $bare)->count() === 0
            && ! $bare->canAccessPanel(Filament::getPanel('admin')) && ! $bare->canAccessPanel(Filament::getPanel(CollaboratorAccess::PANEL)));

        // 11. Audit trail.
        $events = DB::table('activity_log')->where('log_name', 'colaboradores')->pluck('event')->unique()->values()->all();
        $this->check('auditoría: invitado, asignado, compartido, revisado, retirado, baja', array_diff(['invitado', 'asignado', 'novedad_compartida', 'novedad_revisada', 'retirado', 'baja', 'nota_interna'], $events) === [], implode(',', $events));
    }

    private function checkNoGhl(int $teamId, Collaborator $ana, Collaborator $inmo, string $tag): void
    {
        config(['ghl.token' => 'token-prueba', 'ghl.api_url' => 'https://ghl.prueba']);
        $ghlCalls = fn (): int => Http::recorded(fn (ClientRequest $r): bool => str_starts_with($r->url(), 'https://ghl.prueba') || str_contains($r->url(), 'leadconnectorhq'))->count();
        $this->check('ningún flujo de colaboradores ha llamado a GoHighLevel (alta, invitación, asignación, avisos, retirada)', $ghlCalls() === 0);

        // Worst case: the collaborator's number and a company contact's e-mail are also GHL contacts (valuation requests).
        $control = '+34600999888';
        foreach ([[$ana->whatsapp, null, 'ghl-colaboradora'], [null, $inmo->contacts[0]['email'], 'ghl-contacto-empresa'], [$control, null, 'ghl-control']] as [$phone, $email, $id]) {
            DB::table('clarto_valuation_requests')->insert(['request_id' => (string) Str::uuid(), 'team_id' => $teamId, 'property' => '{}',
                'contact' => json_encode(array_filter(['telefono' => $phone, 'email' => $email])), 'ghl_contact_id' => $id.'-'.$tag, 'created_at' => now(), 'updated_at' => now()]);
        }
        Bus::fake([CallQueuedClosure::class]);
        $line = WhatsAppLine::forTeam($teamId);
        $inbound = fn (string $phone, string $body) => WhatsApp::handleEvent(['id' => 'evt_'.Str::ulid(), 'event' => 'message', 'session' => $line->session,
            'payload' => ['id' => 'false_'.ltrim($phone, '+').'@c.us_'.Str::upper(Str::random(16)), 'from' => ltrim($phone, '+').'@c.us', 'fromMe' => false, 'body' => $body, 'timestamp' => now()->timestamp]]);

        $inbound((string) $ana->whatsapp, 'Tengo novedades del ático, MÁS ADELANTE te cuento');
        Party::query()->create(['team_id' => $teamId, 'created_by' => (int) Team::query()->find($teamId)?->user_id, 'type' => 'vendor', 'name' => 'Contacto con datos de colaborador', 'phone' => $ana->whatsapp, 'email' => $inmo->contacts[0]['email'],
            'metadata' => ['ghl_contact_id' => 'ghl-colaboradora-'.$tag]]);
        $this->check('WhatsApp entrante de un colaborador: no se reenvía a GHL (ni nota ni etiqueta)', Bus::dispatched(CallQueuedClosure::class)->isEmpty() && $ghlCalls() === 0);
        $this->check('contacto guardado con datos de un colaborador: sin etiqueta «cliente-clarto» en GHL', $ghlCalls() === 0);

        $inbound($control, 'Hola, sigo interesado');
        Party::query()->create(['team_id' => $teamId, 'created_by' => (int) Team::query()->find($teamId)?->user_id, 'type' => 'vendor', 'name' => 'Contacto de control', 'phone' => $control, 'metadata' => ['ghl_contact_id' => 'ghl-control-'.$tag]]);
        $this->check('control: con un contacto normal los mismos ganchos sí van a GHL (la prueba es significativa)', Bus::dispatched(CallQueuedClosure::class)->isNotEmpty() && $ghlCalls() > 0);
    }

    /** Turkish complete, «tú» on the sign-in pages and the «forgotten password» e-mail; staff keep «usted» (control). */
    private function textChecks(User $collaborator, User $staff): void
    {
        $keys = fn (string $file, string $locale): array => array_keys(\Illuminate\Support\Arr::dot((array) trans($file, [], $locale)));
        $missing = fn (string $file): array => array_values(array_diff(array_filter($keys($file, 'en'), fn (string $k): bool => ! str_starts_with($k, 'acceso.')), $keys($file, 'tr')));
        $this->check('turco: textos del panel de colaboradores completos (ninguna clave cae al inglés)', $missing('colaboradores') === [], implode(', ', array_slice($missing('colaboradores'), 0, 10)));
        $this->check('turco: textos de solicitudes completos (ninguna clave cae al inglés)', $missing('solicitudes') === [], implode(', ', array_slice($missing('solicitudes'), 0, 10)));
        $this->check('turco: se puede elegir en la ficha del colaborador', in_array('tr', Collaborator::LOCALES, true)
            && array_keys(\App\Filament\Collaborators\CollaboratorUi::options('locales', Collaborator::LOCALES)) === Collaborator::LOCALES);
        $before = $collaborator->locale;
        $record = Collaborator::query()->where('user_id', $collaborator->id)->firstOrFail();
        $record->update(['locale' => 'tr']);
        $this->check('cambiar el idioma en la ficha cambia el del usuario del panel (SetLocale, manual, correos)', $collaborator->fresh()->locale === 'tr');
        $record->update(['locale' => $before ?? 'es']);

        app('session')->driver()->flush();
        auth()->forgetGuards();
        app()->setLocale('es');
        $login = (string) $this->get('/colaboradores/login', language: 'es-ES')->getContent();
        $this->check('acceso de /colaboradores: tutea («Entra en tu cuenta», «¿Has olvidado tu contraseña?»)', str_contains($login, 'Entra en tu cuenta')
            && str_contains($login, '¿Has olvidado tu contraseña?') && ! str_contains($login, 'su contraseña'), mb_substr(strip_tags($login), 0, 300));
        $staffLogin = (string) $this->get('/admin/login', language: 'es-ES')->getContent();
        $this->check('control: el acceso del personal sigue tratando de usted', str_contains($staffLogin, '¿Ha olvidado su contraseña?') && ! str_contains($staffLogin, 'Entra en tu cuenta'));
        $request = $this->get('/colaboradores/password-reset/request', language: 'es-ES');
        $this->check('«¿Olvidaste tu contraseña?» de /colaboradores (HTTP 200, sin «usted»)', $request->getStatusCode() === 200 && ! str_contains((string) $request->getContent(), 'su contraseña'), $this->describe($request));
        Filament::setCurrentPanel(Filament::getPanel(CollaboratorAccess::PANEL));
        // Signed URL: requested as is (host included), like the link in the e-mail.
        $reset = $this->get(Filament::getResetPasswordUrl(\Illuminate\Support\Facades\Password::broker()->createToken($collaborator), $collaborator), language: 'es-ES');
        $this->check('primera contraseña / contraseña nueva de /colaboradores: «Elige tu contraseña»', $reset->getStatusCode() === 200 && str_contains((string) $reset->getContent(), 'Elige tu contraseña')
            && ! str_contains((string) $reset->getContent(), 'su contraseña'), $this->describe($reset));

        app()->setLocale('es');
        $mail = function (User $to): string {
            $notification = app(\Filament\Auth\Notifications\ResetPassword::class, ['token' => 'prueba']);
            $notification->url = 'https://example.test/restablecer';

            return (string) $notification->toMail($to)->subject;
        };
        $this->check('correo «contraseña olvidada»: tutea al colaborador y no cambia para el personal',
            $mail($collaborator) === __('colaboradores.acceso.mail_subject') && $mail($staff) !== __('colaboradores.acceso.mail_subject'), $mail($collaborator).' / '.$mail($staff));
    }

    /**
     * «Manual de uso» and «Preguntar a CLARTO» in /colaboradores: only the collaborators' manual, never a section of
     * the staff manual (page, chat, global search, staff manual asked directly). With a staff control.
     */
    private function manualChecks(User $collaborator, User $staff, int $teamId): void
    {
        app()->setLocale('es');
        $titles = fn (string $file): array => array_map('trim', preg_match_all('/^#{2,3} (.+)$/m', (string) file_get_contents(resource_path('manual/'.$file)), $m) ? $m[1] : []);
        $staffOnly = array_values(array_diff($titles('es.md'), $titles('colaboradores.es.md')));
        // Headings of the manual's body only (the menu may well say «Colaboradores»).
        $leaks = fn (string $html): array => array_values(array_filter($staffOnly, fn (string $t): bool => str_contains(
            preg_match('#<article class="cl-manual__body">(.*)</article>#s', $html, $body) === 1 ? $body[1] : $html, '>'.e($t).'</h')));
        $questions = ['¿Cómo envío un albarán?', '¿Qué es la documentación de la empresa?', 'fianza alquiler', '¿Cómo respondo a una solicitud de inmueble?', '¿Dónde veo mis inmuebles?', 'contraseña'];

        $this->actAs($staff);
        $this->check('manual (control): el personal sí encuentra su manual interno', UserManual::search('albarán') !== [] && count($staffOnly) > 10);

        $this->actAs($collaborator);
        Filament::setCurrentPanel(Filament::getPanel(CollaboratorAccess::PANEL));
        $this->check('manual: el manual interno está vacío para un colaborador (página, chat y búsqueda)', UserManual::search('albarán') === [] && (new UserManual)->manual() === []);
        $page = $this->get('/colaboradores/manual');
        $html = (string) $page->getContent();
        $this->check('manual: «Manual de uso» de /colaboradores (HTTP 200) con su propio manual', $page->getStatusCode() === 200 && str_contains($html, e($titles('colaboradores.es.md')[0] ?? '¿?')), $this->describe($page));
        $this->check('manual: la página no contiene ningún apartado del manual interno', $leaks($html) === [], implode(' | ', $leaks($html)));
        $this->check('manual: el manual interno por enlace directo está bloqueado', in_array($this->get('/admin/'.$teamId.'/manual')->getStatusCode(), [403, 404], true));

        $chat = new ManualChat;
        $chat->mount();
        $answers = collect($questions)->flatMap(fn (string $q): array => $chat->ask($q)['entries']);
        $this->check('chat: responde con el manual de colaboradores', $answers->isNotEmpty()
            && $answers->every(fn (array $a): bool => str_starts_with($a['url'], CollaboratorManual::getUrl().'#')), $answers->pluck('url')->implode(' '));
        $chatLeaks = $answers->pluck('title')->intersect($staffOnly)->values()->all();
        $this->check('chat: ningún apartado del manual interno', $chatLeaks === [], implode(' | ', $chatLeaks));

        $found = collect($questions)->flatMap(fn (string $q) => collect((new ManualGlobalSearchProvider)->getResults($q)?->getCategories()->get(__('User manual'), []) ?? []));
        $searchLeaks = $found->filter(fn ($r): bool => in_array((string) $r->title, $staffOnly, true) || ! str_contains($r->url, '/'.CollaboratorAccess::PANEL.'/manual'))->map(fn ($r): string => $r->title.' '.$r->url)->values()->all();
        $this->check('búsqueda global: apartados solo del manual de colaboradores', $found->isNotEmpty() && $searchLeaks === [], implode(' | ', $searchLeaks));
    }

    /**
     * «Preguntar a CLARTO» · «En tus datos»: the chat finds records with each screen's own query and policies.
     * Staff see their team's records only (another team's admin never, nor with the other team in the URL, nor
     * without a team), a member without /admin gets nothing, users/roles/teams are never listed; a collaborator only
     * their assigned property and the requests sent to them, never internal notes, never in another panel.
     */
    private function dataChatChecks(Team $teamA, User $owner, Collaborator $ana, Collaborator $inmo, Property $p1, Property $p2, string $tag): void
    {
        app()->setLocale('es');
        $this->actAs($owner);
        if (in_array(SearchRequests::MODULE, ClartoModules::keys(), true)) {
            ClartoModules::enable(SearchRequests::MODULE);
        }

        // Fictitious: an admin of team A, a member of team A without /admin, team B with its admin, a property and a
        // file («expediente») in each team, two requests (one sent to Ana, one draft) and an internal note.
        $adminA = $this->staffUser("Gestora Ficticia A {$tag}", $teamA, ['admin', 'inventario-chat-prueba']);
        $adminNoStockA = $this->staffUser("Administrativa Ficticia A {$tag}", $teamA, ['admin']);
        $memberA = $this->staffUser("Comercial Ficticio A {$tag}", $teamA);
        $ownerB = $this->staffUser("Titular Ficticio B {$tag}", null);
        $teamB = Team::withoutEvents(fn () => Team::query()->forceCreate(['user_id' => $ownerB->id, 'name' => "Inmobiliaria Ficticia B {$tag}", 'personal_team' => false]));
        $adminB = $this->staffUser("Gestor Bermejo{$tag} Ficticio B", $teamB, ['admin', 'inventario-chat-prueba']);
        WhatsAppLine::query()->firstOrCreate(['team_id' => $teamB->id], ['session' => 'prueba-chat-'.Str::lower($tag), 'status' => WhatsAppLine::WORKING]);
        $gamma = $this->property((int) $teamB->id, (int) $adminB->id, "Piso ficticio Colaboración {$tag} Gamma", 199000);
        $docA = $this->companyDocument($teamA, $adminA, "Escritura ficticia Coral{$tag}");
        $docB = $this->companyDocument($teamB, $adminB, "Escritura ficticia Coral{$tag} B");
        $this->actAs($owner);
        $sent = SearchRequest::query()->create(['team_id' => $teamA->id, 'title' => "Busco ático ficticio Opalo{$tag}", 'operation' => 'compra', 'property_type' => 'atico', 'municipality' => 'Valencia', 'owner_id' => $owner->id]);
        SearchRequests::addRecipients($sent, [$ana->id], $owner);
        SearchRequests::send($sent, 'solicitud', null, $owner);
        $draft = SearchRequest::query()->create(['team_id' => $teamA->id, 'title' => "Busco local ficticio Jade{$tag}", 'operation' => 'compra', 'property_type' => 'local', 'municipality' => 'Valencia', 'owner_id' => $owner->id]);
        Collaborations::note($p1, "NOTA-INTERNA del chat: Zafiro{$tag} acepta bajar el precio.");

        $ask = function (User $user, string $panelId, ?Team $tenant, string $question, bool $dropTenant = false): array {
            $this->actAs($user, $tenant);
            $panel = Filament::getPanel($panelId);
            Filament::setCurrentPanel($panel);
            if ($panel->hasTenancy()) {
                $panel->bootTenancy();   // done by IdentifyTenant in a real request: Filament's own tenant scope
                Filament::setTenant($tenant, isQuiet: true);
            }
            $chat = new ManualChat;
            $chat->mount();
            if ($dropTenant) {
                Filament::setTenant(null, isQuiet: true);
            }
            $answer = $chat->ask($question);
            Filament::setTenant(null, isQuiet: true);

            return $answer;
        };
        $urls = fn (array $answer): string => collect($answer['records'])->pluck('url')->implode(' ');
        $titles = fn (array $answer): string => collect($answer['records'])->map(fn (array $r): string => $r['category'].': '.$r['title'])->implode(' | ');
        $has = fn (array $answer, string $text): bool => str_contains($titles($answer).' '.$urls($answer), $text);
        $opens = fn (array $answer, CompanyDocument $doc): bool => str_contains($urls($answer).' ', '/documentacion-empresa/'.$doc->id.' ');
        // Search called while the request is in /admin of $tenant, claiming the chat belongs to $panelId.
        $forced = function (User $user, Team $tenant, string $panelId = 'admin') use (&$question): array {
            $this->actAs($user, $tenant);
            Filament::setCurrentPanel(Filament::getPanel('admin'));
            Filament::setTenant($tenant, isQuiet: true);
            try {
                return PanelDataSearch::search($question, $panelId);
            } finally {
                Filament::setTenant(null, isQuiet: true);
            }
        };
        $question = "¿Dónde está el piso {$tag}?";

        // Staff of team A.
        $a = $ask($adminA, 'admin', $teamA, $question);
        $this->check('chat · personal: encuentra los inmuebles de su equipo («En tus datos»)', $has($a, $p1->reference()) && $has($a, $p2->reference()), $titles($a));
        $this->check('chat · personal: nunca un inmueble de otro equipo', ! $has($a, $gamma->reference()) && ! $has($a, 'Gamma'), $titles($a));
        $this->check('chat · personal: los enlaces abren la ficha en su panel y su equipo', $a['records'] !== []
            && collect($a['records'])->every(fn (array $r): bool => str_contains($r['url'], '/admin/'.$teamA->id.'/')), $urls($a));
        $this->check('chat · personal: también responde con el manual interno', collect($ask($adminA, 'admin', $teamA, '¿Cómo envío un albarán?')['entries'])->isNotEmpty());
        $c = $ask($adminA, 'admin', $teamA, "Coral{$tag}");
        $this->check('chat · personal: expedientes de su equipo sí, de otro equipo no', $opens($c, $docA) && ! $opens($c, $docB), $titles($c).' '.$urls($c));
        $s = $ask($adminA, 'admin', $teamA, "Opalo{$tag}");
        $this->check('chat · personal: solicitudes de su equipo', $has($s, (string) $sent->title), $titles($s));
        $col = $ask($adminA, 'admin', $teamA, 'Ana Ficticia Colaboradora');
        $this->check('chat · personal: colaboradores de su equipo', $has($col, 'Ana Ficticia Colaboradora'), $titles($col));
        $noStock = $ask($adminNoStockA, 'admin', $teamA, $question);
        $this->check('chat · rol sin permiso de inventario: ningún inmueble (como en la pantalla), el resto sí', ! $has($noStock, $p1->reference()) && ! $has($noStock, $p2->reference())
            && $has($ask($adminNoStockA, 'admin', $teamA, 'Ana Ficticia Colaboradora'), 'Ana Ficticia Colaboradora'), $titles($noStock));
        $users = $ask($adminA, 'admin', $teamA, "Bermejo{$tag}");
        $this->check('chat · nunca lista usuarios, roles ni equipos (solo pantallas de trabajo)', $users['records'] === [], $titles($users));

        // Staff of team B: only team B; never team A, not even with team A in the URL.
        $b = $ask($adminB, 'admin', $teamB, $question);
        $this->check('chat · personal de otro equipo: solo lo suyo', $has($b, $gamma->reference()) && ! $has($b, $p1->reference()) && ! $has($b, $p2->reference()), $titles($b));
        $bc = $ask($adminB, 'admin', $teamB, "Coral{$tag}");
        $this->check('chat · personal de otro equipo: sus expedientes sí, los del primero no', $opens($bc, $docB) && ! $opens($bc, $docA) && ! $has($bc, 'Opalo'), $titles($bc).' '.$urls($bc));
        $this->check('chat · personal de otro equipo: tampoco con el equipo ajeno en la dirección', ! $adminB->canAccessTenant($teamA)
            && $ask($adminB, 'admin', $teamA, $question)['records'] === [] && $ask($adminB, 'admin', $teamA, "Opalo{$tag}")['records'] === []);
        $this->check('chat · sin equipo activo en /admin: ningún dato (no se busca sin el filtro del equipo)', $ask($adminA, 'admin', $teamA, $question, dropTenant: true)['records'] === []);

        // A member of team A without /admin: nothing from the inventory in /app nor by forcing /admin.
        $this->check('chat · rol sin acceso a /admin: ningún inmueble (ni en /app ni forzando /admin)', ! $memberA->canAccessPanel(Filament::getPanel('admin'))
            && $ask($memberA, 'app', null, $question)['records'] === [] && $forced($memberA, $teamA) === []);

        // Collaborators: only what CollaboratorAccess grants them.
        $anaAnswer = $ask($ana->user()->firstOrFail(), CollaboratorAccess::PANEL, null, $question);
        $this->check('chat · colaborador: solo su inmueble asignado', $has($anaAnswer, $p1->reference()) && ! $has($anaAnswer, $p2->reference()) && ! $has($anaAnswer, 'Gamma'), $titles($anaAnswer));
        $this->check('chat · colaborador: enlaces solo a su panel', $anaAnswer['records'] !== []
            && collect($anaAnswer['records'])->every(fn (array $r): bool => str_contains($r['url'], '/'.CollaboratorAccess::PANEL.'/')), $urls($anaAnswer));
        $inmoAnswer = $ask($inmo->user()->firstOrFail(), CollaboratorAccess::PANEL, null, $question);
        $this->check('chat · otro colaborador: solo el suyo', $has($inmoAnswer, $p2->reference()) && ! $has($inmoAnswer, $p1->reference()), $titles($inmoAnswer));
        $anaSent = $ask($ana->user()->firstOrFail(), CollaboratorAccess::PANEL, null, "Opalo{$tag}");
        $this->check('chat · colaborador: la solicitud que se le envió sí', $has($anaSent, (string) $sent->title), $titles($anaSent));
        $this->check('chat · colaborador: ni borradores ni solicitudes no enviadas a él', $ask($ana->user()->firstOrFail(), CollaboratorAccess::PANEL, null, "Jade{$tag}")['records'] === []
            && $ask($inmo->user()->firstOrFail(), CollaboratorAccess::PANEL, null, "Opalo{$tag}")['records'] === []);
        $note = $ask($ana->user()->firstOrFail(), CollaboratorAccess::PANEL, null, "Zafiro{$tag}");
        $card = $ask($ana->user()->firstOrFail(), CollaboratorAccess::PANEL, null, 'NOTA-INTERNA-COLABORADOR');
        $this->check('chat · colaborador: nunca notas internas (del inmueble ni de su ficha)', $note['records'] === [] && $card['records'] === []
            && ! str_contains(json_encode($note + $card, JSON_UNESCAPED_UNICODE), 'Zafiro'), $titles($note).' '.$titles($card));
        $this->check('chat · colaborador: nunca expedientes, propietarios ni otros datos del equipo', $ask($ana->user()->firstOrFail(), CollaboratorAccess::PANEL, null, "Coral{$tag}")['records'] === []
            && $ask($ana->user()->firstOrFail(), CollaboratorAccess::PANEL, null, 'Inmo Ficticia')['records'] === []);
        $this->check('chat · colaborador: con una pregunta sobre datos, solo su manual (nunca el interno)', collect($anaAnswer['entries'])
            ->every(fn (array $e): bool => str_starts_with($e['url'], CollaboratorManual::getUrl().'#')), collect($anaAnswer['entries'])->pluck('url')->implode(' '));
        $this->check('chat · colaborador fuera de su panel (/admin forzado): ningún dato', $forced($ana->user()->firstOrFail(), $teamA) === []
            && $forced($ana->user()->firstOrFail(), $teamA, CollaboratorAccess::PANEL) === []);
        $this->check('control: el mismo /admin forzado sí da datos a su personal', $forced($adminA, $teamA) !== []);
        Filament::setCurrentPanel(Filament::getPanel(CollaboratorAccess::PANEL));
        $terms = PanelDataSearch::terms('¿Dónde está el inmueble de la calle Mayor, 12?');
        $this->check('chat · solo busca las palabras que identifican la ficha (no «dónde», «el», «inmueble»…)', $terms === ['calle', 'mayor', '12'], implode(',', $terms));
    }

    /**
     * Staff user of a fictitious team: role «admin» opens /admin, «inventario-chat-prueba» adds the Property permissions
     * the inventory screen asks for (Shield: ViewAny/View/Update:Property); no roles = a plain member.
     *
     * @param  list<string>  $roles
     */
    private function staffUser(string $name, ?Team $team, array $roles = []): User
    {
        $user = User::withoutEvents(fn () => User::query()->forceCreate(['name' => $name, 'email' => 'chat-datos-'.Str::lower(Str::random(10)).'@example.test',
            'password' => bcrypt(Str::random(32)), 'locale' => 'es', 'email_verified_at' => now(), 'current_team_id' => $team?->id]));
        if ($team === null) {
            return $user;
        }
        DB::table('team_user')->insert(['team_id' => $team->id, 'user_id' => $user->id, 'role' => 'editor', 'created_at' => now(), 'updated_at' => now()]);
        foreach ($roles as $role) {
            $roleId = DB::table('roles')->where(['team_id' => $team->id, 'name' => $role, 'guard_name' => 'web'])->value('id');
            if ($roleId === null) {
                $roleId = DB::table('roles')->insertGetId(['team_id' => $team->id, 'name' => $role, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
                foreach ($role === 'inventario-chat-prueba' ? ['ViewAny:Property', 'View:Property', 'Update:Property'] : [] as $permission) {
                    $permissionId = DB::table('permissions')->where(['name' => $permission, 'guard_name' => 'web'])->value('id')
                        ?? DB::table('permissions')->insertGetId(['name' => $permission, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
                    DB::table('role_has_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
                }
            }
            DB::table('model_has_roles')->insert(['role_id' => $roleId, 'model_type' => $user->getMorphClass(), 'model_id' => $user->id, 'team_id' => $team->id]);
        }

        return $user;
    }

    private function companyDocument(Team $team, User $user, string $title): CompanyDocument
    {
        $folder = CompanyDocumentFolder::withoutEvents(fn () => CompanyDocumentFolder::query()->forceCreate(['team_id' => $team->id, 'name' => 'Carpeta ficticia chat']));

        return CompanyDocument::withoutEvents(fn () => CompanyDocument::query()->forceCreate(['team_id' => $team->id, 'folder_id' => $folder->id, 'title' => $title,
            'subject' => 'Sujeto ficticio', 'document_date' => today()->subYear()->toDateString(), 'files' => [], 'created_by' => $user->id]));
    }

    private function property(int $teamId, int $userId, string $title, int $price): Property
    {
        return Property::query()->create([
            'team_id' => $teamId, 'created_by' => $userId, 'title' => $title, 'address' => 'Calle Ficticia 1, 46001 Valencia',
            'postal_code' => '46001', 'property_type' => 'apartment', 'status' => 'available', 'deal_type' => 'sale',
            'price' => $price, 'area_sqft' => 84, 'bedrooms' => 3, 'bathrooms' => 2,
        ]);
    }

    /** $team: the tenant of the request, whose roles and permissions apply (SyncShieldTenant in a real request). */
    private function actAs(User $user, ?Team $team = null): void
    {
        app('session')->driver()->flush();   // one in-process session: AuthenticateSession must not compare with the previous user
        auth()->forgetGuards();   // the Sanctum guard memoises its user (fresh per request in the server)
        auth()->guard('web')->setUser($user);
        auth()->shouldUse('web');
        if ($team !== null) {
            setPermissionsTeamId($team->id);
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
            $user->unsetRelation('roles')->unsetRelation('permissions');
        }
    }

    private function get(string $uri, bool $json = false, ?string $language = null): \Symfony\Component\HttpFoundation\Response
    {
        $request = Request::create($uri, 'GET', server: array_filter(['HTTP_ACCEPT' => $json ? 'application/json' : null, 'HTTP_ACCEPT_LANGUAGE' => $language]));
        $request->setUserResolver(fn ($guard = null) => auth()->guard($guard === 'sanctum' ? 'web' : $guard)->user());
        try {
            return app(Kernel::class)->handle($request);
        } finally {
            Filament::setCurrentPanel(Filament::getPanel(CollaboratorAccess::PANEL));
        }
    }

    private function describe(\Symfony\Component\HttpFoundation\Response $r): string
    {
        return $r->getStatusCode().' '.$r->headers->get('Location').' '.mb_substr(strip_tags((string) $r->getContent()), 0, 200);
    }

    private function fakeWaha(): void
    {
        Http::preventStrayRequests();
        Http::fake(function (ClientRequest $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if (! str_starts_with($request->url(), 'http://waha.prueba:3000')) {
                return Http::response(['message' => 'unexpected'], 500);
            }
            if ($path === '/api/sendText') {
                if ($this->wahaDown) {
                    return Http::response(['message' => 'session not working'], 422);
                }
                $this->whatsappSent[] = $request->data();

                return Http::response(['id' => 'true_'.$request->data()['chatId'].'_'.Str::upper(Str::random(16))], 201);
            }

            return Http::response(['name' => 'default', 'status' => 'WORKING', 'me' => ['id' => '34960000000@c.us']]);
        });
    }

    private function check(string $label, bool $ok, string $detail = ''): void
    {
        $ok ? $this->pass++ : $this->fail++;
        $this->line(($ok ? 'PASS  ' : 'FAIL  ').$label.(! $ok && $detail !== '' ? '  ['.$detail.']' : ''));
    }
}
