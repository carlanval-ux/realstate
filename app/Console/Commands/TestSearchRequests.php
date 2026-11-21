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
use Illuminate\Support\Carbon;
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

    /** @var list<array{0: string, 1: ?string}> any call to GHL (path, status): must stay empty */
    private array $ghlCalls = [];

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
            Carbon::setTestNow();
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
            'plano-orientativo.pdf', 'Puedes responder directamente a este mensaje', 'Equipo CLARTO', '/s/(tu enlace personal)'];
        $missing = array_filter($must, fn (string $s) => ! str_contains($m['email'], $s));
        $this->check('vista previa del correo con el contenido completo', $missing === [], implode(' | ', $missing));
        $wa = implode("\n", $m['whatsapp']);
        $this->check('WhatsApp con el mismo contenido completo', array_filter($must, fn (string $s) => ! str_contains($wa, $s)) === []);
        $this->check('WhatsApp largo en bloques ordenados (1/n…n/n) y enlace al final', count($m['whatsapp']) > 1
            && str_starts_with($m['whatsapp'][0], '(1/'.count($m['whatsapp']).')') && str_contains(end($m['whatsapp']), '/s/'));
        $en = SearchRequests::compose($request, $bruno, 'solicitud');
        $this->check('adaptado al idioma del colaborador (inglés)', str_starts_with($en['email'], 'Hi Bruno.') && str_contains($en['subject'], $request->reference));
        $this->check('enlace al panel después de la referencia (opción adicional)', strpos($m['email'], 'Referencia: '.$request->reference) < strpos($m['email'], '/s/'));

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

        $this->deadlines($teamId, $staff, $ana, $bruno, $carla);
    }

    /** Encargo 2: reference, private answer link, deadline, reminders, expiry, extension/reopening, coverage, TARMIO; nothing reaches GHL. */
    private function deadlines(int $teamId, User $staff, Collaborator $ana, Collaborator $bruno, Collaborator $carla): void
    {
        Auth::login($staff);
        $tz = 'Europe/Madrid';
        // From here on the real staff-only mode: /s/* must be reachable without a session, nothing else.
        config(['app.staff_only' => true, 'ghl.token' => 'token-prueba', 'ghl.api_url' => 'https://ghl.prueba', 'app.url' => 'https://panel.prueba']);
        $this->ghlCalls = [];   // GHL configured (token) on purpose: nothing of this module may reach it

        // ── Reference: correlative per company, no gaps (a rolled back insert gives its number back) ─────────────
        $a = SearchRequest::query()->create(['team_id' => $teamId, 'title' => 'Prueba A', 'operation' => 'compra', 'property_type' => 'piso']);
        try {
            DB::transaction(function () use ($teamId): void {
                SearchRequest::query()->create(['team_id' => $teamId, 'title' => 'Fallida', 'operation' => 'compra', 'property_type' => 'piso']);
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
        }
        $b = SearchRequest::query()->create(['team_id' => $teamId, 'title' => 'Prueba B', 'operation' => 'compra', 'property_type' => 'piso']);
        $otherTeam = (int) Team::query()->max('id') + 1000;
        $c = SearchRequest::query()->create(['team_id' => $otherTeam, 'title' => 'Otra empresa', 'operation' => 'compra', 'property_type' => 'piso']);
        $this->check('referencia correlativa sin huecos ni duplicados (también tras un alta fallida)', (int) substr($b->reference, -6) === (int) substr($a->reference, -6) + 1, $a->reference.' → '.$b->reference);
        $this->check('…y por empresa (otra empresa empieza en 000001)', str_ends_with($c->reference, '-000001'), $c->reference);
        \App\SearchRequests\SearchRequestSettings::query()->updateOrCreate(['team_id' => $teamId], ['default_days' => 10, 'reminder_days' => [7, 2], 'owner_notice_days' => 3, 'timezone' => $tz]);
        $d = SearchRequest::query()->create(['team_id' => $teamId, 'title' => 'Plazo por defecto', 'operation' => 'compra', 'property_type' => 'piso']);
        $this->check('plazo por defecto configurable por empresa (10 días, zona horaria de la empresa)', $d->fresh()->respond_by->toDateString() === now($tz)->addDays(10)->toDateString());

        // ── Private answer link ─────────────────────────────────────────────────────────────────────────────────
        $r = SearchRequest::query()->create(['team_id' => $teamId, 'title' => 'Piso con ascensor en Benimaclet', 'operation' => 'compra', 'property_type' => 'piso',
            'municipality' => 'Valencia', 'budget_max' => 250000, 'respond_by' => now($tz)->addDays(10)->toDateString(), 'owner_id' => $staff->getKey()]);
        SearchRequests::addRecipients($r, [$ana->getKey(), $bruno->getKey()], $staff);
        SearchRequests::send($r, 'solicitud', null, $staff);
        $mail = collect($this->mails())->last(fn ($m) => $m['to'] === $ana->email);
        preg_match('#/s/([A-Za-z0-9_-]{22})\b#', (string) $mail['body'], $mm);
        $code = $mm[1] ?? '';
        $this->check('cada destinatario recibe su enlace privado (22 caracteres, independiente de la referencia)', strlen($code) === 22 && ! str_contains($code, substr($r->reference, -6)));
        $this->check('el vencimiento aparece en el mensaje', str_contains((string) $mail['body'], $r->respond_by->format('d/m/Y')));
        $this->check('solo se guarda el hash: ni el registro de comunicaciones ni la copia guardan el código completo',
            Communication::query()->where('body', 'like', '%'.$code.'%')->doesntExist() && CollaboratorNotice::query()->where('email_body', 'like', '%'.$code.'%')->doesntExist()
            && \App\SearchRequests\SearchRequestLink::query()->where('code_hash', hash('sha256', $code))->exists());
        $page = $this->raw('GET', '/s/'.$code, [], ['HTTP_USER_AGENT' => 'WhatsApp/2.23 (link preview)']);
        $this->check('vista previa del enlace (WhatsApp, escáneres): no cuenta como apertura', $page->getStatusCode() === 200 && \App\SearchRequests\SearchRequestLink::query()->where('code_hash', hash('sha256', $code))->value('opens') === 0);
        $page = $this->raw('GET', '/s/'.$code);
        $recipientAna = $r->recipients()->where('collaborator_id', $ana->getKey())->first();
        $this->check('la página muestra la petición completa con noindex y no-referrer', $page->getStatusCode() === 200 && str_contains((string) $page->getContent(), 'Piso con ascensor en Benimaclet')
            && str_contains((string) $page->headers->get('X-Robots-Tag'), 'noindex') && $page->headers->get('Referrer-Policy') === 'no-referrer');
        $this->check('enviar y abrir no cambian el estado: sigue pendiente de respuesta', $recipientAna->status === 'enviada' && $r->responses()->doesntExist());
        $this->check('el enlace no da acceso a nada más (panel u otras rutas)', $this->raw('GET', '/admin/'.$teamId.'/solicitudes-inmuebles/'.$r->getKey())->getStatusCode() !== 200);
        $post = $this->raw('POST', '/s/'.$code, ['kind' => 'encaja', 'description' => 'Tengo un piso de 85 m² con ascensor', 'location' => 'Benimaclet', 'price' => '239000', 'links' => "https://example.test/p/1\nno-es-url", 'estimated_time' => '1 semana']);
        $first = $r->responses()->where('channel', 'enlace')->first();
        $this->check('respuesta desde el enlace: propuesta vinculada y seguimiento «Respondida»', $post->getStatusCode() === 303 && $first?->kind === 'encaja' && $first->links === ['https://example.test/p/1'] && $recipientAna->fresh()->status === 'respondida');
        $this->raw('POST', '/s/'.$code, ['kind' => 'encaja', 'parent_id' => (string) $first?->getKey(), 'description' => 'Añado: plaza de garaje incluida', 'price' => '245000']);
        $second = $r->responses()->where('parent_id', $first?->getKey())->first();
        $this->check('completar la propuesta crea una versión nueva y conserva la anterior', $second?->version === 2 && $first?->fresh()->description === 'Tengo un piso de 85 m² con ascensor');
        $this->check('una respuesta efectiva no marca la búsqueda como cubierta', $r->fresh()->status === 'en_evaluacion');

        // ── Reminders (7 and 2 days before), only without answer, once ─────────────────────────────────────────
        $r->recipients()->update(['sent_at' => now()->subDays(5)]);
        Carbon::setTestNow(now()->addDays(3)->setTime(10, 0));   // 7 days left
        $before = CollaboratorNotice::query()->where('dedupe_key', 'like', "solicitud:{$r->getKey()}:recordatorio:%")->count();
        SearchRequests::runDeadlines();
        SearchRequests::runDeadlines();
        $reminders = CollaboratorNotice::query()->where('dedupe_key', 'like', "solicitud:{$r->getKey()}:recordatorio:%")->get();
        $this->check('recordatorio a 7 días solo a quien no ha respondido, una vez', $reminders->count() - $before === 1 && $reminders->pluck('collaborator_id')->all() === [$bruno->getKey()]
            && str_contains((string) collect($this->mails())->last()['body'], $r->respond_by->format('d/m/Y')), (string) $reminders->count());

        // ── Owner warned before the deadline while proposals are under review ──────────────────────────────────
        Carbon::setTestNow(now()->addDays(5));   // 2 days left
        $mailsBefore = count($this->mails());
        SearchRequests::runDeadlines();
        SearchRequests::runDeadlines();
        $toOwner = collect($this->mails())->slice($mailsBefore)->filter(fn ($m) => $m['to'] === $staff->email);
        $this->check('aviso al responsable con antelación (en negociación), una vez', $toOwner->count() === 1 && TarmioTask::query()->where('source_id', $r->getKey())->where('kind', 'decidir_plazo')->exists());
        $this->check('recordatorio a 2 días también enviado', CollaboratorNotice::query()->where('dedupe_key', 'like', "solicitud:{$r->getKey()}:recordatorio:%recordatorio-2d-%")->count() === 1);

        // ── Responses racing with the close ────────────────────────────────────────────────────────────────────
        $recipientBruno = $r->recipients()->where('collaborator_id', $bruno->getKey())->first();
        $stale = $recipientBruno->load('request');   // page loaded while still open…
        SearchRequest::query()->whereKey($r->getKey())->update(['status' => 'cerrada']);   // …the team closes it…
        try {
            SearchRequests::respond($stale, ['kind' => 'puede_buscar'], $bruno->user);
            $ok = false;
        } catch (RuntimeException) {
            $ok = true;
        }
        $this->check('respuesta simultánea al cierre: rechazada entera (comprobación en la escritura)', $ok && $r->responses()->where('collaborator_id', $bruno->getKey())->doesntExist());
        SearchRequest::query()->whereKey($r->getKey())->update(['status' => 'en_evaluacion']);

        // ── Expiry ──────────────────────────────────────────────────────────────────────────────────────────────
        Carbon::setTestNow(now()->addDays(3));   // deadline passed
        $pendingNotice = CollaboratorNotice::query()->create(['team_id' => $teamId, 'collaborator_id' => $bruno->getKey(), 'subject_type' => $r->getMorphClass(), 'subject_id' => $r->getKey(),
            'dedupe_key' => 'prueba-pendiente:'.$r->getKey(), 'email_subject' => 'x', 'email_body' => 'x', 'whatsapp_blocks' => ['x'], 'status' => CollaboratorNotice::PENDING]);
        $done = SearchRequests::runDeadlines();
        $r->refresh();
        $this->check('al vencer: «Caducada · oportunidad perdida», motivo «Plazo agotado»', $done['caducadas'] >= 1 && $r->status === 'caducada'
            && $r->outcome === SearchRequests::EXPIRED_OUTCOME && $r->close_reason === SearchRequests::EXPIRED_REASON);
        $this->check('…fuera de las bandejas activas y con el historial completo', SearchRequest::query()->forTeam($teamId)->whereIn('status', SearchRequest::ACTIVE)->whereKey($r->getKey())->doesntExist()
            && $r->events()->whereIn('action', ['enviada', 'respuesta', 'recordatorio', 'aviso_plazo', 'caducada'])->distinct()->count('action') === 5);
        $this->check('…los envíos pendientes se cancelan', $pendingNotice->fresh()->status === CollaboratorNotice::CANCELLED);
        $this->check('…TARMIO refleja el cierre', TarmioTask::query()->where('source_id', $r->getKey())->where('source_type', $r->getMorphClass())->where('title', 'like', '[Caducada%')->count() >= 2);
        $this->check('enlace de una solicitud caducada: ya no admite respuestas', $this->raw('GET', '/s/'.$code)->getStatusCode() === 410
            && $this->raw('POST', '/s/'.$code, ['kind' => 'no_puede'])->getStatusCode() === 410);

        // ── Extend / reopen: same reference, history kept, new date notified, reminders recomputed ───────────────
        $ref = $r->reference;
        $newDate = now($tz)->addDays(14)->toDateString();
        $mailsBefore = count($this->mails());
        SearchRequests::extend($r, $newDate, $staff, 'Ampliamos porque el cliente sigue interesado');
        $r->refresh();
        $notified = collect($this->mails())->slice($mailsBefore);
        $this->check('reabrir: misma referencia, nuevo plazo y estado según las propuestas', $r->reference === $ref && $r->respond_by->toDateString() === $newDate && $r->status === 'en_evaluacion' && $r->close_reason === null);
        $this->check('…se notifica la nueva fecha a los destinatarios con la solicitud completa', $notified->count() === 2
            && $notified->every(fn ($m) => str_contains($m['body'], \Illuminate\Support\Carbon::parse($newDate)->format('d/m/Y')) && str_contains($m['body'], 'Piso con ascensor en Benimaclet')));
        $this->check('…el historial conserva la caducidad y la ampliación', $r->events()->where('action', 'caducada')->exists() && $r->events()->where('action', 'plazo')->exists());
        $this->check('…el enlace antiguo vuelve a funcionar', $this->raw('GET', '/s/'.$code)->getStatusCode() === 200);
        $r->recipients()->update(['sent_at' => now()->subDay()]);
        Carbon::setTestNow(\Illuminate\Support\Carbon::parse($newDate, $tz)->subDays(7)->setTime(10, 0));
        SearchRequests::runDeadlines();
        $this->check('…los recordatorios se recalculan para la nueva fecha', CollaboratorNotice::query()->where('dedupe_key', 'like', "solicitud:{$r->getKey()}:recordatorio:%recordatorio-7d-".str_replace('-', '', $newDate).':%')->count() === 1);

        // ── Revoked link ────────────────────────────────────────────────────────────────────────────────────────
        $recipientAna = $r->recipients()->where('collaborator_id', $ana->getKey())->first();
        SearchRequests::revokeLinks($recipientAna, $staff);
        $this->check('enlace revocado: deja de admitir consultas y respuestas', $this->raw('GET', '/s/'.$code)->getStatusCode() === 410 && $this->raw('POST', '/s/'.$code, ['kind' => 'no_puede'])->getStatusCode() === 410);
        $this->check('un código inventado no revela nada', $this->raw('GET', '/s/'.Str::random(22))->getStatusCode() === 404);

        // ── Covered before the deadline ─────────────────────────────────────────────────────────────────────────
        $bruno->refresh();
        $pending = SearchRequests::respond($r->recipients()->where('collaborator_id', $bruno->getKey())->first()->load('request'), ['kind' => 'puede_buscar', 'description' => 'Lo busco'], $bruno->user);
        $mailsBefore = count($this->mails());
        SearchRequests::selectProposal($r->responses()->where('parent_id', $first?->getKey())->firstOrFail(), null, $staff);
        $r->refresh();
        $stop = collect($this->mails())->slice($mailsBefore);
        $this->check('cubierta antes de tiempo: se cierran las propuestas pendientes', $r->status === 'cubierta' && $pending->fresh()->status === 'descartada');
        $this->check('…y se avisa a los colaboradores para que dejen de buscar (ambos canales)', $stop->count() === 2 && $stop->every(fn ($m) => str_contains($m['body'], 'puedes dejar de buscar') || str_contains($m['body'], 'you can stop looking')));
        Carbon::setTestNow();

        // ── GHL: nothing about requests, proposals, collaborators or their messages (GHL configured on purpose) ─────
        $line = WhatsAppLine::forTeam($teamId);
        WhatsApp::handleEvent(['id' => 'evt_'.Str::ulid(), 'event' => 'message', 'session' => $line->session, 'payload' => [
            'id' => 'false_'.ltrim($ana->whatsapp, '+').'@c.us_'.Str::upper(Str::random(16)), 'from' => ltrim($ana->whatsapp, '+').'@c.us', 'fromMe' => false,
            'body' => 'Sobre '.$r->reference.': tengo otro piso', 'timestamp' => now()->timestamp,
        ]]);
        $this->check('nada de solicitudes, propuestas, colaboradores ni sus mensajes llega a GHL (cierre, caducidad, reapertura, WhatsApp)', $this->ghlCalls === [], json_encode($this->ghlCalls));
        $this->check('la solicitud no tiene campo de oportunidad de GHL', ! \Illuminate\Support\Facades\Schema::hasColumn('clarto_search_requests', 'ghl_opportunity_id'));
    }

    /** @param array<string, string> $data */
    private function raw(string $method, string $uri, array $data = [], array $server = []): \Symfony\Component\HttpFoundation\Response
    {
        Auth::logout();

        return app(HttpKernel::class)->handle(Request::create($uri, $method, $data, [], [], $server + ['HTTP_USER_AGENT' => 'Mozilla/5.0 (prueba)', 'REMOTE_ADDR' => '10.0.0.'.random_int(1, 250)]));
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
            if (str_starts_with($request->url(), 'https://ghl.prueba')) {
                $this->ghlCalls[] = [(string) parse_url($request->url(), PHP_URL_PATH), $request->data()['status'] ?? null];

                return Http::response(['succeded' => true]);
            }
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
