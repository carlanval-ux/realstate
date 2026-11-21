<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Collaborators\Collaborator;
use App\Collaborators\CollaboratorAccess;
use App\Collaborators\CollaboratorNotice;
use App\Models\Communication;
use App\Models\User;
use App\Models\WhatsAppLine;
use App\Modules\ClartoModules;
use App\SearchRequests\SearchRequest;
use App\SearchRequests\SearchRequestInboxItem;
use App\SearchRequests\SearchRequestResponse;
use App\SearchRequests\SearchRequests;
use App\Tarmio\TarmioTask;
use App\WhatsApp\WhatsApp;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Liberu\Foundation\ModuleManager\ModuleActivations;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\RealEstate\Properties\Models\Property;
use RuntimeException;
use Throwable;

/**
 * «Solicitudes de inmuebles» end to end with fictitious collaborators and every provider SIMULATED (WAHA via
 * Http::fake, e-mail to the in-memory "array" mailer, TARMIO simulated). Nothing is kept (rolled back):
 * a request sent to two collaborators; complete e-mail + WhatsApp to both; one reply by e-mail and one by WhatsApp
 * linked, an ambiguous one queued; receipts are not answers; a retry keeps one message; a direct link to a request
 * not sent to you is blocked; a selected proposal creates a property and continues in its record.
 *
 *   php artisan clarto:solicitudes-prueba
 */
final class TestSearchRequests extends Command
{
    protected $signature = 'clarto:solicitudes-prueba';

    protected $description = 'Test property requests to collaborators (e-mail, WhatsApp, replies, access, property) with simulated providers (nothing is kept)';

    private int $pass = 0;

    private int $fail = 0;

    private bool $wahaDown = false;

    /** @var list<array<string, mixed>> */
    private array $whatsappSent = [];

    /** @var list<string> files written to the private disk, removed at the end */
    private array $files = [];

    public function handle(): int
    {
        config([
            'whatsapp.url' => 'http://waha.prueba:3000', 'whatsapp.api_key' => 'clave-prueba', 'whatsapp.session' => 'default',
            'mail.default' => 'array', 'queue.default' => 'sync', 'tarmio.driver' => 'simulado',
            'solicitudes.inbound_mail_key' => 'clave-correo-prueba', 'solicitudes.whatsapp_block' => 900,
            // /colaboradores/* is opened in staff-only mode by the «Colaboradores» branch (StaffOnlyPaths); here the file checks test this module's own rule.
            'app.staff_only' => false,
        ]);
        Mail::forgetMailers();
        $this->fakeWaha();
        // Modules switched on for this run only (per-request copy of the administrators' choices).
        app()->instance(ClartoModules::CHOICES, ['clarto-solicitudes' => true, 'clarto-whatsapp' => true, 'clarto-colaboradores' => true] + app(ModuleActivations::class)->all());

        DB::beginTransaction();
        try {
            $this->scenarios();
        } catch (Throwable $e) {
            $this->check('sin excepciones inesperadas', false, $e::class.': '.$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine());
        } finally {
            DB::rollBack();
            Storage::disk(SearchRequest::DISK)->delete($this->files);
            Auth::logout();
        }

        $this->line(sprintf('RESULT: %d passed, %d failed (WAHA, e-mail and TARMIO simulated; database rolled back)', $this->pass, $this->fail));

        return $this->fail === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function scenarios(): void
    {
        $team = Team::query()->oldest('id')->firstOrFail();
        $teamId = (int) $team->getKey();
        $staff = User::query()->findOrFail($team->user_id);
        WhatsAppLine::forTeam($teamId)->update(['status' => WhatsAppLine::WORKING]);

        $tag = Str::lower(Str::random(6));
        $collab = function (string $name, string $phone, string $locale) use ($teamId, $tag): Collaborator {
            $user = User::query()->create(['name' => $name, 'email' => Str::slug($name).".{$tag}@example.test"]);

            return Collaborator::query()->create(['team_id' => $teamId, 'kind' => 'persona', 'name' => $name, 'email' => Str::slug($name).".{$tag}@example.test",
                'whatsapp' => $phone, 'locale' => $locale, 'user_id' => $user->getKey(), 'status' => Collaborator::ACTIVE]);
        };
        $ana = $collab('Ana Prueba Ficticia', '+34600111'.random_int(100, 999), 'es');
        $bruno = $collab('Bruno Test Ficticio', '+34600222'.random_int(100, 999), 'en');
        $carla = $collab('Carla Ajena Ficticia', '+34600333'.random_int(100, 999), 'es');
        $this->check('módulo «Solicitudes» registrado en el gestor de módulos CLARTO', in_array('solicitudes', ClartoModules::keys(), true) && ClartoModules::isImplemented('solicitudes'));

        // ── Draft ───────────────────────────────────────────────────────────────────────────────────────────────
        Auth::login($staff);
        $path = 'clarto/solicitudes/prueba-'.$tag.'.pdf';
        Storage::disk(SearchRequest::DISK)->put($path, '%PDF-1.4 prueba');
        $this->files[] = $path;
        $request = SearchRequest::query()->create([
            'team_id' => $teamId, 'title' => 'Ático con terraza para cliente inversor', 'operation' => 'compra', 'property_type' => 'atico',
            'municipality' => 'Valencia', 'zones' => 'Ruzafa, El Carmen', 'address' => 'Cerca de Gran Vía Marqués del Turia',
            'surface_min' => 90, 'surface_max' => 140, 'budget_min' => 300000, 'budget_max' => 450000, 'bedrooms_min' => 3,
            'features' => 'Ascensor, garaje opcional', 'must_have' => 'Terraza de al menos 20 m²', 'preferences' => 'Orientación sur',
            'needed_by' => now()->addMonths(2)->toDateString(), 'description' => str_repeat('Cliente con financiación aprobada y visita inmediata. ', 12),
            'example_links' => ['https://example.test/anuncio/123'], 'attachments' => [$path], 'attachment_names' => [$path => 'plano-orientativo.pdf'],
            'owner_id' => $staff->getKey(),
        ]);
        SearchRequests::addRecipients($request, [$ana->getKey(), $bruno->getKey()], $staff);
        $this->check('referencia correlativa por empresa SOL-AAAA-NNNNNN', (bool) preg_match('/^SOL-\d{4}-\d{6}$/', $request->reference), $request->reference);
        $this->check('fecha límite por defecto a 21 días', $request->fresh()->respond_by?->toDateString() === now('Europe/Madrid')->addDays(21)->toDateString());
        $this->check('borrador: el colaborador aún no lo ve', ! CollaboratorAccess::canSee($ana->user, $request));

        // ── Preview = what is sent ──────────────────────────────────────────────────────────────────────────────
        $m = SearchRequests::compose($request, $ana, 'solicitud');
        $must = ['Hola, Ana. Desde CLARTO estamos buscando', 'Ático con terraza', $request->reference, 'Valencia', 'Ruzafa, El Carmen', '300.000 € – 450.000 €',
            '90 – 140 m²', 'Terraza de al menos 20 m²', 'Orientación sur', $request->respond_by->format('d/m/Y'), 'https://example.test/anuncio/123',
            'plano-orientativo.pdf', 'Puedes responder directamente a este mensaje', 'Equipo CLARTO', '/colaboradores/solicitudes/'.$request->getKey()];
        $missing = array_filter($must, fn (string $s) => ! str_contains($m['email'], $s));
        $this->check('vista previa del correo con el contenido completo', $missing === [], implode(' | ', $missing));
        $wa = implode("\n", $m['whatsapp']);
        $this->check('WhatsApp con el mismo contenido completo', array_filter($must, fn (string $s) => ! str_contains($wa, $s)) === []);
        $this->check('WhatsApp largo en bloques ordenados (1/n…n/n) y enlace al final', count($m['whatsapp']) > 1
            && str_starts_with($m['whatsapp'][0], '(1/'.count($m['whatsapp']).')') && str_contains(end($m['whatsapp']), '/colaboradores/solicitudes/'));
        $en = SearchRequests::compose($request, $bruno, 'solicitud');
        $this->check('adaptado al idioma del colaborador (inglés)', str_starts_with($en['email'], 'Hi Bruno.') && str_contains($en['subject'], $request->reference));
        $this->check('enlace al panel después de la referencia (opción adicional)', strpos($m['email'], 'Referencia: '.$request->reference) < strpos($m['email'], '/colaboradores/solicitudes/'));

        // ── Send to both ────────────────────────────────────────────────────────────────────────────────────────
        $names = SearchRequests::send($request, 'solicitud', null, $staff);
        $request->refresh();
        $notices = CollaboratorNotice::query()->where('subject_type', $request->getMorphClass())->where('subject_id', $request->getKey())->get();
        $this->check('enviada a los dos colaboradores', count($names) === 2 && $notices->count() === 2 && $notices->every(fn ($n) => $n->status === 'enviado'),
            $notices->map(fn ($n) => $n->status.':'.$n->last_error)->implode(', '));
        $this->check('estado general «Abierta» y seguimiento individual «Enviada»', $request->status === 'abierta' && $request->recipients()->where('status', 'enviada')->count() === 2);
        $mails = $this->mails();
        foreach ([$ana, $bruno] as $c) {
            $mine = collect($mails)->first(fn ($msg) => $msg['to'] === $c->email);
            $this->check("correo completo a {$c->name}", $mine !== null && str_contains($mine['subject'], $request->reference) && str_contains($mine['body'], 'Terraza de al menos 20 m²') && str_contains($mine['body'], '450.000 €'));
            $blocks = array_values(array_filter($this->whatsappSent, fn ($w) => $w['chatId'] === ltrim($c->whatsapp, '+').'@c.us'));
            $this->check("WhatsApp completo a {$c->name} en bloques ordenados", count($blocks) > 1 && str_starts_with($blocks[0]['text'], '(1/') && str_starts_with(end($blocks)['text'], '('.count($blocks).'/')
                && str_contains(implode("\n", array_column($blocks, 'text')), 'Terraza de al menos 20 m²'));
        }
        $anaMail = Communication::query()->where('channel', 'email')->where('email', $ana->email)->where('direction', 'out')->latest('id')->first();
        $brunoMail = Communication::query()->where('channel', 'email')->where('email', $bruno->email)->where('direction', 'out')->latest('id')->first();
        $brunoMsgId = $this->mails()[array_search($bruno->email, array_column($this->mails(), "to"), true)]["message_id"] ?? null;
        $this->check("Message-ID propio del correo (para vincular respuestas por hilo)", $brunoMail !== null && $brunoMsgId === "<".SearchRequests::messageIdFor($brunoMail->getKey()).">", (string) $brunoMsgId);
        $this->check('TARMIO: tarea de seguimiento con la referencia', TarmioTask::query()->where('source_type', $request->getMorphClass())->where('source_id', $request->getKey())->where('kind', 'seguimiento_solicitud')->where('title', 'like', '%'.$request->reference.'%')->exists());
        $this->check('el colaborador ya ve la solicitud enviada', CollaboratorAccess::canSee($ana->user, $request));

        // ── Receipts are not answers ────────────────────────────────────────────────────────────────────────────
        $line = WhatsAppLine::forTeam($teamId);
        $anaWa = Communication::query()->where('channel', 'whatsapp')->where('phone', $ana->whatsapp)->where('direction', 'out')->oldest('id')->first();
        WhatsApp::handleEvent(['id' => 'evt_'.Str::ulid(), 'event' => 'message.ack', 'session' => $line->session, 'payload' => ['id' => $anaWa->provider_id, 'ack' => 3]]);
        $r = SearchRequests::inboundMail(['from' => 'Bruno <'.$bruno->email.'>', 'subject' => 'Read: ['.$request->reference.'] Property wanted', 'text' => 'Your message was read.',
            'message_id' => '<mdn-'.$tag.'@example.test>', 'in_reply_to' => $brunoMsgId, 'content_type' => 'multipart/report; report-type=disposition-notification']);
        $this->check('confirmación de lectura (WhatsApp leído y acuse de correo) no cuenta como aceptación',
            $anaWa->fresh()->status === 'leido' && $r === 'acuse' && $request->recipients()->where('status', 'respondida')->doesntExist() && $request->responses()->doesntExist(), $r);

        // ── Reply by WhatsApp (quoting our message) and by e-mail (thread) ──────────────────────────────────────
        $quoted = Str::afterLast((string) $anaWa->provider_id, '_');
        WhatsApp::handleEvent(['id' => 'evt_'.Str::ulid(), 'event' => 'message', 'session' => $line->session, 'payload' => [
            'id' => 'false_'.ltrim($ana->whatsapp, '+').'@c.us_'.Str::upper(Str::random(16)), 'from' => ltrim($ana->whatsapp, '+').'@c.us', 'fromMe' => false,
            'body' => 'Tengo uno en Ruzafa, 120 m², 420.000 €. Te paso fotos.', 'timestamp' => now()->timestamp, 'replyTo' => ['id' => $quoted, 'body' => '…'],
        ]]);
        $waReply = $request->responses()->where('channel', 'whatsapp')->first();
        $waComm = Communication::query()->where('phone', $ana->whatsapp)->where('direction', 'in')->latest('id')->first();
        $this->check('respuesta por WhatsApp vinculada a la solicitud y al colaborador', $waReply !== null && $waReply->collaborator_id === $ana->getKey() && str_contains((string) $waReply->description, 'Ruzafa'));
        $this->check('…y no queda en «WhatsApp por vincular»', $waComm !== null && $waComm->status !== Communication::UNLINKED);

        $r = SearchRequests::inboundMail(['from' => $bruno->email, 'subject' => 'Re: Property wanted', 'text' => 'I can find it in 3 weeks.',
            'message_id' => '<re-'.$tag.'@example.test>', 'in_reply_to' => $brunoMsgId, 'references' => $brunoMsgId]);
        $this->check('respuesta por correo vinculada por el hilo (In-Reply-To)', $r === 'vinculada' && $request->responses()->where('channel', 'email')->where('collaborator_id', $bruno->getKey())->exists(), $r);
        $this->check('reenvío del mismo correo entrante no duplica', SearchRequests::inboundMail(['from' => $bruno->email, 'subject' => 'Re: Property wanted', 'text' => 'I can find it in 3 weeks.', 'message_id' => '<re-'.$tag.'@example.test>']) === 'duplicada');
        $this->check('seguimiento individual «Respondida»', $request->recipients()->where('status', 'respondida')->count() === 2);

        // ── Ambiguous reply → «Respuestas por vincular» ─────────────────────────────────────────────────────────
        $second = SearchRequest::query()->create(['team_id' => $teamId, 'title' => 'Local para franquicia', 'operation' => 'alquiler', 'property_type' => 'local', 'municipality' => 'Valencia']);
        SearchRequests::addRecipients($second, [$ana->getKey()], $staff);
        SearchRequests::send($second, 'solicitud', null, $staff);
        $this->check('la segunda referencia es la siguiente correlativa', (int) substr($second->reference, -6) === (int) substr($request->reference, -6) + 1, $second->reference);
        WhatsApp::handleEvent(['id' => 'evt_'.Str::ulid(), 'event' => 'message', 'session' => $line->session, 'payload' => [
            'id' => 'false_'.ltrim($ana->whatsapp, '+').'@c.us_'.Str::upper(Str::random(16)), 'from' => ltrim($ana->whatsapp, '+').'@c.us', 'fromMe' => false,
            'body' => 'Mañana te digo algo', 'timestamp' => now()->timestamp,
        ]]);
        $item = SearchRequestInboxItem::query()->where('team_id', $teamId)->where('collaborator_id', $ana->getKey())->latest('id')->first();
        $this->check('respuesta ambigua (dos solicitudes, sin referencia) va a «Respuestas por vincular»', $item !== null && $item->status === 'pendiente'
            && collect($item->candidates)->sort()->values()->all() === collect([$request->getKey(), $second->getKey()])->sort()->values()->all());
        $this->check('…sin crear respuesta automática', $second->responses()->doesntExist() && $request->responses()->count() === 2);
        SearchRequests::linkInboxItem($item, $second, $ana->getKey(), $staff);
        $this->check('el equipo la vincula y queda como respuesta del colaborador', $item->fresh()->status === 'vinculado' && $second->responses()->where('collaborator_id', $ana->getKey())->exists());

        // ── Retry keeps a single message ────────────────────────────────────────────────────────────────────────
        $this->wahaDown = true;
        $mailsBefore = count($this->mails());
        $request->update(['budget_max' => 480000]);
        SearchRequests::send($request, 'actualizacion', null, $staff, 'Presupuesto máximo ampliado a 480.000 €');
        $update = CollaboratorNotice::query()->where('subject_id', $request->getKey())->where('collaborator_id', $ana->getKey())->where('dedupe_key', 'like', '%:actualizacion:%')->firstOrFail();
        $this->check('fallo de WhatsApp registrado (correo enviado, WhatsApp con error)', $update->status === 'parcial' && $update->email_status === 'enviado' && $update->whatsapp_status === 'error', $update->status.' '.$update->last_error);
        $this->wahaDown = false;
        $sentBefore = count($this->whatsappSent);
        SearchRequests::retry($update, $staff);
        SearchRequests::retry($update->fresh(), $staff);
        $blocks = count($update->fresh()->whatsapp_blocks ?? []);
        $keys = Communication::query()->where('related_type', $update->getMorphClass())->where('related_id', (string) $update->getKey())->where('channel', 'whatsapp')->pluck('idempotency_key');
        $this->check('reintento: se envía una vez y un segundo reintento no duplica', $update->fresh()->status === 'enviado' && count($this->whatsappSent) - $sentBefore === $blocks && $keys->count() === $blocks && $keys->unique()->count() === $blocks,
            'blocks='.$blocks.' sent='.(count($this->whatsappSent) - $sentBefore));
        $this->check('…y el correo ya enviado no se repite', count($this->mails()) - $mailsBefore === 2);
        $this->check('versión 2 en el historial con su copia', $request->fresh()->version === 2 && $request->events()->where('action', 'actualizacion')->exists() && str_contains((string) $update->email_body, 'Presupuesto máximo ampliado'));

        // ── Access: only requests sent to you, direct link blocked ──────────────────────────────────────────────
        $this->check('cada colaborador lista solo sus solicitudes', CollaboratorAccess::scope(SearchRequest::query(), $ana->user)->pluck('id')->sort()->values()->all() === collect([$request->getKey(), $second->getKey()])->sort()->values()->all()
            && CollaboratorAccess::scope(SearchRequest::query(), $bruno->user)->pluck('id')->all() === [$request->getKey()]
            && CollaboratorAccess::scope(SearchRequest::query(), $carla->user)->doesntExist());
        $this->check('y solo sus propias respuestas', CollaboratorAccess::scope(SearchRequestResponse::query(), $bruno->user)->pluck('collaborator_id')->unique()->all() === [$bruno->getKey()]);
        $file = route('clarto.solicitudes.archivo.colaborador', ['searchRequest' => $request->getKey(), 'response' => 0, 'index' => 0], false);
        $this->check('enlace directo a un adjunto: bloqueado para un colaborador ajeno', $this->get($file, $carla->user) === 404);
        $this->check('…y abierto para el destinatario', $this->get($file, $ana->user) === 200);
        $this->check('enlace directo a una solicitud ajena bloqueado (servidor)', ! CollaboratorAccess::canSee($carla->user, $request) && ! CollaboratorAccess::canSee($bruno->user, $second));
        $panel = '/colaboradores/solicitudes/'.$second->getKey();
        if (app('router')->has('filament.colaboradores.resources.solicitudes.view')) {
            $this->check('…también la ficha en el panel de colaboradores', in_array($this->get($panel, $bruno->user), [403, 404], true));
        }

        // ── Panel answer, comparison, property ──────────────────────────────────────────────────────────────────
        $recipientAna = \App\SearchRequests\SearchRequestAccess::recipientFor($ana->user, $request);
        $proposal = SearchRequests::respond($recipientAna, ['kind' => 'encaja', 'description' => 'Ático 125 m² con terraza de 30 m²', 'location' => 'Calle Sueca, Ruzafa (Valencia)',
            'price' => 435000, 'features' => '3 hab., 2 baños, ascensor', 'links' => ['https://example.test/inmueble/987'], 'estimated_time' => 'Disponible ya'], $ana->user);
        $this->check('respuesta desde el panel: propuesta vinculada a solicitud y colaborador', $proposal->search_request_id === $request->getKey() && $proposal->collaborator_id === $ana->getKey() && $proposal->kind === 'encaja');
        $this->check('estado general pasa a «En evaluación» (una propuesta no la cubre sola)', $request->fresh()->status === 'en_evaluacion');
        $this->check('TARMIO: tarea para revisar propuestas', TarmioTask::query()->where('source_id', $request->getKey())->where('kind', 'revisar_propuestas')->exists());
        Auth::login($staff);
        $url = SearchRequests::selectProposal($proposal, null, $staff);
        $property = Property::query()->find($proposal->fresh()->property_id);
        $this->check('seleccionar propuesta crea el inmueble (borrador) del equipo', $property !== null && (string) $property->team_id === (string) $teamId && (int) $property->price === 435000);
        $this->check('…y continúa su gestión en la ficha del inmueble', str_contains($url, '/'.$property?->getKey().'/edit') && $request->fresh()->property_id === $property?->getKey() && $request->fresh()->status === 'cubierta');
        $this->check('historial de auditoría completo', $request->events()->pluck('action')->intersect(['creada', 'destinatarios', 'enviada', 'respuesta', 'actualizacion', 'reintento', 'inmueble', 'estado'])->unique()->count() === 8);
        try {
            SearchRequests::respond($recipientAna->fresh(), ['kind' => 'no_puede'], $ana->user);
            $this->check('solicitud cubierta ya no admite respuestas desde el panel', false);
        } catch (RuntimeException) {
            $this->check('solicitud cubierta ya no admite respuestas desde el panel', true);
        }

        // ── Withdraw: access revoked, pending notices cancelled ─────────────────────────────────────────────────
        $recipientBruno = $request->recipients()->where('collaborator_id', $bruno->getKey())->first();
        $pending = CollaboratorNotice::query()->create(['team_id' => $teamId, 'collaborator_id' => $bruno->getKey(), 'subject_type' => $request->getMorphClass(), 'subject_id' => $request->getKey(),
            'dedupe_key' => 'prueba:'.$tag, 'email_subject' => 'x', 'email_body' => 'x', 'whatsapp_blocks' => ['x'], 'status' => CollaboratorNotice::PENDING]);
        SearchRequests::withdraw($recipientBruno, $staff);
        $this->check('retirar destinatario revoca el acceso y cancela sus envíos pendientes', ! CollaboratorAccess::canSee($bruno->user, $request) && $pending->fresh()->status === CollaboratorNotice::CANCELLED);

        // ── Inbound e-mail endpoint ─────────────────────────────────────────────────────────────────────────────
        $this->check('correo entrante: clave incorrecta rechazada', $this->post('/api/v1/solicitudes/correo-entrante', ['from' => $ana->email], 'mala') === 401);
        $this->check('correo entrante con la referencia en el asunto se vincula', $this->post('/api/v1/solicitudes/correo-entrante', ['from' => $ana->email, 'subject' => 'Re: ['.$second->reference.'] Buscamos inmueble',
            'text' => 'Tengo un local de 90 m² en Benimaclet.', 'message_id' => '<api-'.$tag.'@example.test>'], 'clave-correo-prueba') === 200
            && $second->responses()->where('channel', 'email')->exists());
    }

    private function get(string $uri, User $user): int
    {
        Auth::login($user);

        return app(HttpKernel::class)->handle(Request::create($uri, 'GET'))->getStatusCode();
    }

    /** @param array<string, mixed> $json */
    private function post(string $uri, array $json, string $key): int
    {
        $req = Request::create($uri, 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_SOLICITUDES_CLAVE' => $key], json_encode($json));

        return app(HttpKernel::class)->handle($req)->getStatusCode();
    }

    /** @return list<array{to: string, subject: string, body: string}> */
    private function mails(): array
    {
        return collect(app('mailer')->getSymfonyTransport()->messages())->map(fn ($sent) => [
            'to' => $sent->getOriginalMessage()->getTo()[0]->getAddress(),
            'subject' => (string) $sent->getOriginalMessage()->getSubject(),
            'body' => (string) $sent->getOriginalMessage()->getTextBody(),
            'message_id' => '<'.trim((string) $sent->getOriginalMessage()->getHeaders()->get('Message-ID')?->getBodyAsString(), '<>').'>',
        ])->values()->all();
    }

    private function fakeWaha(): void
    {
        Http::preventStrayRequests();
        Http::fake(function (ClientRequest $request) {
            if (! str_starts_with($request->url(), 'http://waha.prueba:3000')) {
                return Http::response(['message' => 'unexpected'], 500);
            }
            if (parse_url($request->url(), PHP_URL_PATH) === '/api/sendText') {
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
