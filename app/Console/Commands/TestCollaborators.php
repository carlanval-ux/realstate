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
use App\Filament\Colaboradores\Resources\MyPropertyResource;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Liberu\Foundation\Organizations\Models\Team;
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
        $this->check('ficha de inmueble de /admin por enlace directo: bloqueada', in_array($this->get('/admin/'.$teamId.'/properties/'.$p1->id.'/edit')->getStatusCode(), [403, 404], true));
        $api = json_decode((string) $this->get('/api/v1/colaboradores/inmuebles', json: true)->getContent(), true);
        $this->check('API de colaboradores: solo su inmueble', collect($api['data'] ?? [])->pluck('id')->all() === [$p1->id], json_encode($api));
        $this->check('API de colaboradores: ficha ajena 404', $this->get('/api/v1/colaboradores/inmuebles/'.$p2->id, json: true)->getStatusCode() === 404);
        $this->check('API general del inventario: cerrada al colaborador (403)', $this->get('/api/v1/real-estate/properties', json: true)->getStatusCode() === 403);
        $this->check('/storage (fotos y archivos): cerrado al colaborador', $this->get('/_auth/check')->getStatusCode() !== 204);
        $csv = ListMyProperties::export($anaUser);
        ob_start();
        $csv->sendContent();
        $csvText = (string) ob_get_clean();
        $this->check('exportación CSV: solo su inmueble', str_contains($csvText, $p1->reference()) && ! str_contains($csvText, $p2->reference()));

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

        // 10. Deactivation and the bare role.
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

    private function property(int $teamId, int $userId, string $title, int $price): Property
    {
        return Property::query()->create([
            'team_id' => $teamId, 'created_by' => $userId, 'title' => $title, 'address' => 'Calle Ficticia 1, 46001 Valencia',
            'postal_code' => '46001', 'property_type' => 'apartment', 'status' => 'available', 'deal_type' => 'sale',
            'price' => $price, 'area_sqft' => 84, 'bedrooms' => 3, 'bathrooms' => 2,
        ]);
    }

    private function actAs(User $user): void
    {
        app('session')->driver()->flush();   // one in-process session: AuthenticateSession must not compare with the previous user
        auth()->forgetGuards();   // the Sanctum guard memoises its user (fresh per request in the server)
        auth()->guard('web')->setUser($user);
        auth()->shouldUse('web');
    }

    private function get(string $uri, bool $json = false): \Symfony\Component\HttpFoundation\Response
    {
        $request = Request::create($uri, 'GET', server: $json ? ['HTTP_ACCEPT' => 'application/json'] : []);
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
