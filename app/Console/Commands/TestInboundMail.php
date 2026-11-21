<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Collaborators\Collaborator;
use App\Communications\Communications;
use App\InboundMail\ImapMailbox;
use App\InboundMail\InboundMail;
use App\InboundMail\InboundMailAccess;
use App\InboundMail\InboundMailRule;
use App\InboundMail\InboundMails;
use App\InboundMail\InboundMailSettings;
use App\InboundMail\MimeMessage;
use App\Models\AiSetting;
use App\Models\Communication;
use App\Models\User;
use App\Modules\ClartoModules;
use App\SearchRequests\SearchRequest;
use App\SearchRequests\SearchRequests;
use App\Tarmio\TarmioTask;
use App\Valuations\ValuationRequest;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Liberu\Foundation\ModuleManager\ModuleActivations;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\RealEstate\Parties\Models\Party;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * «Correo entrante» end to end with a FAKE mailbox (canned IMAP server on a local socket pair: the real client code
 * talks to it), fake AI (Http::fake) and simulated TARMIO, fictitious people only. Nothing is kept (rolled back).
 *
 *   php artisan clarto:correo-prueba
 */
final class TestInboundMail extends Command
{
    protected $signature = 'clarto:correo-prueba';

    protected $description = 'Test inbound mail triage with a fake IMAP mailbox and fake AI (nothing is kept)';

    private int $pass = 0;

    private int $fail = 0;

    /** @var list<string> user messages sent to the AI */
    private array $aiPrompts = [];

    /** @var array<string, array<string, mixed>> AI answer by a word of the subject */
    private array $aiAnswers = [];

    /** @var list<string> every line the fake IMAP server received */
    private array $imapLog = [];

    public function handle(): int
    {
        config(['mail.default' => 'array', 'queue.default' => 'sync', 'tarmio.driver' => 'simulado', 'app.staff_only' => false]);
        Http::fake(['openrouter.ai/*' => function (ClientRequest $r) {
            $prompt = (string) data_get($r->data(), 'messages.1.content');
            $this->aiPrompts[] = $prompt;
            $answer = collect($this->aiAnswers)->first(fn ($a, string $word) => str_contains($prompt, $word)) ?? ['categoria' => 'otros', 'confianza' => 0.3];

            return Http::response(['model' => 'modelo/prueba', 'choices' => [['message' => ['content' => json_encode($answer)]]]]);
        }, '*' => Http::response([], 404)]);
        app()->instance(ClartoModules::CHOICES, ['clarto-correo' => true, 'clarto-solicitudes' => true, 'clarto-colaboradores' => true] + app(ModuleActivations::class)->all());

        DB::beginTransaction();
        try {
            $this->scenarios();
        } catch (Throwable $e) {
            $this->check('sin excepciones inesperadas', false, $e::class.': '.$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine());
        } finally {
            $paths = InboundMail::query()->pluck('attachments')->flatten(1)->pluck('path')->filter()->all();
            DB::rollBack();
            Storage::disk(InboundMail::DISK)->delete($paths);
            Auth::logout();
        }
        $this->line(sprintf('RESULT: %d passed, %d failed (IMAP, AI and TARMIO simulated; database rolled back)', $this->pass, $this->fail));

        return $this->fail === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function scenarios(): void
    {
        $team = Team::query()->oldest('id')->firstOrFail();
        $teamId = (int) $team->getKey();
        $staff = User::query()->findOrFail($team->user_id);
        $tag = Str::lower(Str::random(6));
        InboundMailSettings::query()->delete();
        InboundMailSettings::query()->create(['team_id' => $teamId, 'polling' => true, 'interval_minutes' => 5, 'ai' => true,
            'area_owners' => ['extranjeria' => "extranjeria.{$tag}@example.test", 'administracion' => "admin.{$tag}@example.test"]]);
        AiSetting::current()->forceFill(['provider' => 'openrouter', 'openrouter_api_key' => 'clave-prueba', 'translation_model' => 'modelo/prueba'])->save();
        $this->check('módulo «Correo entrante» registrado e implementado', in_array('correo', ClartoModules::keys(), true) && ClartoModules::isImplemented('correo'));

        // ── MIME reader ────────────────────────────────────────────────────────────────────────────────────────
        $m = MimeMessage::parse($this->message(['From' => '=?UTF-8?Q?Jos=C3=A9_Ficticio?= <jose.ficticio@example.test>', 'Subject' => '=?UTF-8?B?w5NyZGVuIGRlIHBydWViYQ==?='],
            'multipart', [['text/plain; charset=ISO-8859-1', 'quoted-printable', "Se=F1or, adjunto el plano.\n"], ['application/pdf', 'base64', base64_encode('%PDF-1.4 prueba'), "attachment; filename*=UTF-8''plano%20n%C3%BAmero%201.pdf"]]));
        $this->check('MIME: cabeceras codificadas, texto en ISO-8859-1 y adjunto con nombre RFC 2231',
            $m->from() === ['jose.ficticio@example.test', 'José Ficticio'] && $m->header('Subject') === 'Órden de prueba' && trim($m->text) === 'Señor, adjunto el plano.'
            && ($m->attachments[0]['name'] ?? '') === 'plano número 1.pdf' && ($m->attachments[0]['content'] ?? '') === '%PDF-1.4 prueba', json_encode([$m->from(), $m->header('Subject'), $m->text, $m->attachments[0]['name'] ?? null]));

        // ── Fixtures (fictitious) ──────────────────────────────────────────────────────────────────────────────
        $collabUser = User::query()->create(['name' => 'Colaboradora Ficticia', 'email' => "colab.{$tag}@example.test"]);
        $collab = Collaborator::query()->create(['team_id' => $teamId, 'kind' => 'persona', 'name' => 'Colaboradora Ficticia', 'email' => "colab.{$tag}@example.test",
            'locale' => 'es', 'user_id' => $collabUser->getKey(), 'status' => Collaborator::ACTIVE]);
        Auth::login($staff);
        $request = SearchRequest::query()->create(['team_id' => $teamId, 'title' => 'Piso de prueba para cliente ficticio', 'operation' => 'compra', 'property_type' => 'piso', 'municipality' => 'Valencia']);
        SearchRequests::addRecipients($request, [$collab->getKey()], $staff);
        $request->forceFill(['status' => 'abierta', 'respond_by' => now()->addDays(10)])->save();
        $request->recipients()->update(['status' => 'enviada', 'sent_at' => now()]);
        Auth::logout();

        $valuation = ValuationRequest::query()->create(['request_id' => (string) Str::uuid(), 'reference' => 'VAL-9'.random_int(10000, 99999), 'team_id' => $teamId, 'status' => 'entregada',
            'property' => ['municipio' => 'Valencia'], 'contact' => ['nombre' => 'Propietaria Ficticia', 'email' => "propietaria.{$tag}@example.test"]]);
        $sentToOwner = Communications::record($teamId, 'email', Communication::OUT, ['email' => "propietaria.{$tag}@example.test", 'subject' => 'Tu valoración', 'body' => 'Informe', 'source' => 'valoraciones', 'related' => $valuation]);
        $client = Party::query()->forceCreate(['team_id' => $teamId, 'created_by' => $staff->getKey(), 'type' => 'buyer', 'name' => 'Cliente Ficticio', 'email' => "cliente.{$tag}@example.test"]);

        // Our valuation e-mails get a Message-ID that replies point back to.
        $email = (new Email)->from('info@example.test')->to('x@example.test')->subject('x')->text('x');
        $email->getHeaders()->addTextHeader(Communications::MAIL_HEADER, 'valoracion-'.$valuation->reference);
        InboundMails::stampMessageId(new MessageSending($email));
        $valMsgId = '<'.$email->getHeaders()->get('Message-ID')?->getBodyAsString().'>';
        $valMsgId = '<'.trim($valMsgId, '<>').'>';
        $this->check('Message-ID propio en los correos de valoración', str_starts_with($valMsgId, '<clarto-val-'.$valuation->reference.'.'), $valMsgId);

        // ── First poll: cursor at the newest message, history not imported ─────────────────────────────────────
        $r = InboundMails::poll($this->imap(777, 101, null, []), force: true);
        $s = InboundMailSettings::forTeam($teamId);
        $this->check('primera lectura: cursor en el último correo, sin importar el histórico', $r['estado'] === 'ok' && (int) $s->uidvalidity === 777 && (int) $s->last_uid === 100 && InboundMail::query()->doesntExist(), json_encode($r));

        // ── Second poll: one message per scenario ──────────────────────────────────────────────────────────────
        $this->aiAnswers = [
            'renovación' => ['categoria' => 'consulta', 'area' => 'extranjeria', 'confianza' => 0.92],
            'propuesta comercial' => ['categoria' => 'proveedor', 'confianza' => 0.4],
        ];
        $msgs = [
            101 => $this->message(['From' => "Colaboradora <colab.{$tag}@example.test>", 'Subject' => 'Re: Búsqueda '.$request->reference, 'Message-ID' => "<sol-{$tag}@example.test>"],
                'text', "Tengo un piso en Benimaclet de 95 m² por 240.000 €.\n\n> Mensaje anterior citado"),
            102 => $this->message(['From' => "propietaria.{$tag}@example.test", 'Subject' => 'Re: Tu valoración', 'Message-ID' => "<val-{$tag}@example.test>", 'In-Reply-To' => $valMsgId],
                'text', 'Gracias, ¿podemos hablar del precio?'),
            103 => $this->message(['From' => "Persona Nueva <nueva.{$tag}@example.test>", 'Subject' => 'Consulta renovación de residencia', 'Message-ID' => "<nueva-{$tag}@example.test>"],
                'text', "Hola, necesito la renovación de mi tarjeta. Mi teléfono es 600 123 456, NIE X1234567L, IBAN ES91 2100 0418 4502 0005 1332 y escribidme a otra.{$tag}@example.test"),
            104 => $this->message(['From' => "ofertas@tienda-{$tag}.example", 'Subject' => '¡Descuentos de invierno!', 'Message-ID' => "<pub-{$tag}@example.test>", 'List-Unsubscribe' => '<mailto:baja@tienda.example>'],
                'text', 'Solo hoy 50 %.'),
            105 => $this->message(['From' => "comercial@proveedor-{$tag}.example", 'Subject' => 'Nuestra propuesta comercial', 'Message-ID' => "<amb-{$tag}@example.test>"],
                'multipart', [['text/plain; charset=UTF-8', '8bit', "Le enviamos nuestra tarifa.\n"], ['application/pdf', 'base64', base64_encode('%PDF-1.4 tarifa'), 'attachment; filename="tarifa.pdf"']]),
            106 => $this->message(['From' => "Colaboradora <colab.{$tag}@example.test>", 'Subject' => 'Respuesta automática: fuera de la oficina', 'Message-ID' => "<auto-{$tag}@example.test>",
                'Auto-Submitted' => 'auto-replied'], 'text', 'Estoy fuera hasta el lunes. '.$request->reference),
            107 => $this->message(['From' => "cliente.{$tag}@example.test", 'Subject' => 'Documentación del expediente', 'Message-ID' => "<cli-{$tag}@example.test>"], 'text', 'Os adjunto lo pedido.'),
        ];
        $box = $this->imap(777, 108, array_keys($msgs), $msgs);
        $r = InboundMails::poll($box, force: true);
        $get = fn (string $id) => InboundMail::query()->where('team_id', $teamId)->where('message_id', "<{$id}-{$tag}@example.test>")->first();
        $this->check('segunda lectura: 7 correos nuevos, cursor en el último', $r['estado'] === 'ok' && ($r['nuevos'] ?? 0) === 7 && (int) InboundMailSettings::forTeam($teamId)->last_uid === 107, json_encode($r));

        $sol = $get('sol');
        $this->check('respuesta a solicitud (referencia SOL-) vinculada a la solicitud', $sol?->status === 'enrutado' && $sol->route === 'solicitud_vinculada' && $sol->category === 'solicitud'
            && $request->responses()->where('collaborator_id', $collab->getKey())->where('channel', 'email')->exists(), $sol?->status.' '.$sol?->route.' '.$sol?->reason);

        $val = $get('val');
        $valComm = $val?->communication_id ? Communication::query()->find($val->communication_id) : null;
        $this->check('respuesta de propietario a la valoración (hilo) en su cronología', $val?->route === 'cronologia' && $val->category === 'propietario' && $val->method === 'hilo'
            && $valComm?->direction === Communication::IN && $valComm->related_type === $valuation->getMorphClass() && (string) $valComm->related_id === (string) $valuation->getKey(), $val?->route.' '.$val?->reason);

        $new = $get('nueva');
        $task = TarmioTask::query()->where('source_type', (new InboundMail)->getMorphClass())->where('source_id', $new?->getKey())->first();
        $this->check('consulta nueva → «Entrada» de Extranjería clasificada por IA', $new?->status === 'entrada' && $new->category === 'consulta' && $new->area === 'extranjeria' && $new->method === 'ia', $new?->status.' '.$new?->reason);
        $this->check('…con tarea TARMIO al responsable del área (sin remitente ni asunto)', $task !== null && $task->assignee_email === "extranjeria.{$tag}@example.test"
            && ! str_contains($task->title, 'renovación') && ! str_contains($task->title, 'nueva.'), (string) $task?->title);
        $prompt = collect($this->aiPrompts)->first(fn (string $p) => str_contains($p, 'renovación')) ?? '';
        $this->check('a la IA solo van asunto y extracto sin datos personales', $prompt !== '' && ! preg_match('/600 123 456|X1234567L|ES91|@example\.test|nueva\./', $prompt)
            && str_contains($prompt, '[número]') && str_contains($prompt, '[documento]'), $prompt);

        $pub = $get('pub');
        $this->check('publicidad archivada en el panel sin llamar a la IA', $pub?->status === 'archivado' && $pub->category === 'publicidad'
            && collect($this->aiPrompts)->doesntContain(fn (string $p) => str_contains($p, 'Descuentos')), $pub?->status.' '.$pub?->category);

        $amb = $get('amb');
        $this->check('ambiguo (confianza baja) → «Pendiente de revisar» con la categoría sugerida', $amb?->status === 'revisar' && $amb->suggested_category === 'proveedor' && $amb->reason === 'baja_confianza', $amb?->status.' '.$amb?->reason);
        $file = $amb?->attachments[0] ?? null;
        $this->check('adjunto guardado en el disco privado', $file !== null && $file['name'] === 'tarifa.pdf' && Storage::disk(InboundMail::DISK)->get((string) $file['path']) === '%PDF-1.4 tarifa');

        $auto = $get('auto');
        $this->check('respuesta automática reconocida: archivada y no cuenta como respuesta', $auto?->status === 'archivado' && $auto->category === 'automatico'
            && $request->responses()->count() === 1 && $request->events()->where('action', 'acuse')->exists(), $auto?->status.' '.$request->responses()->count());

        $cli = $get('cli');
        $this->check('cliente con expediente → su cronología', $cli?->route === 'cronologia' && $cli->category === 'cliente' && (int) $cli->party_id === (int) $client->getKey()
            && Communication::query()->whereKey($cli->communication_id)->where('party_id', $client->getKey())->exists(), $cli?->route.' '.$cli?->reason);

        // ── The mailbox is never modified ──────────────────────────────────────────────────────────────────────
        $commands = array_map(fn (string $l) => preg_replace('/^A\d+ /', '', $l), $this->imapLog);
        $readOnly = collect($commands)->every(fn (string $c) => (bool) preg_match('/^(EXAMINE "INBOX"|UID SEARCH (UID \d+:\*|SINCE \S+)|UID FETCH \d+ \(BODY\.PEEK\[\]\)|LOGOUT)$/', $c));
        $this->check('buzón intacto: solo EXAMINE (solo lectura), búsqueda y BODY.PEEK', $readOnly && count($commands) > 3 && ! preg_grep('/STORE|EXPUNGE|MOVE|COPY|DELETE|SELECT|APPEND|\\\\Seen/i', $commands), implode(' | ', $commands));
        $refused = false;
        try {
            (fn () => $this->command('UID STORE 101 +FLAGS (\Seen)'))->call($box);
        } catch (\LogicException) {
            $refused = true;
        }
        $this->check('el cliente IMAP rechaza cualquier orden que modifique el buzón', $refused);

        // ── Idempotent re-poll (UIDVALIDITY changed: re-reads since the last poll) ─────────────────────────────
        $before = [InboundMail::query()->count(), Communication::query()->count(), $request->responses()->count(), TarmioTask::query()->count()];
        $r = InboundMails::poll($this->imap(778, 108, array_keys($msgs), $msgs), force: true);
        $this->check('volver a leer los mismos correos no duplica nada', $r['estado'] === 'ok' && ($r['nuevos'] ?? -1) === 0
            && $before === [InboundMail::query()->count(), Communication::query()->count(), $request->responses()->count(), TarmioTask::query()->count()], json_encode([$r, $before]));

        // ── Review: correct → routed, and becomes a rule for the domain ────────────────────────────────────────
        InboundMails::correct($amb, 'administracion', null, null, InboundMailRule::DOMAIN, $staff);
        $amb->refresh();
        $this->check('corregir desde «Pendiente de revisar» la envía a «Entrada» con tarea', $amb->status === 'entrada' && $amb->category === 'administracion' && $amb->method === 'manual'
            && TarmioTask::query()->where('source_id', $amb->getKey())->where('source_type', $amb->getMorphClass())->value('assignee_email') === "admin.{$tag}@example.test");
        $calls = count($this->aiPrompts);
        $next = InboundMails::ingest($teamId, $this->message(['From' => "facturas@proveedor-{$tag}.example", 'Subject' => 'Requerimiento', 'Message-ID' => "<regla-{$tag}@example.test>"], 'text', 'Texto.'));
        $this->check('la corrección se convierte en regla de dominio (sin IA)', $next->category === 'administracion' && $next->method === 'regla' && count($this->aiPrompts) === $calls, $next->category.' '.$next->method);
        $gmail = InboundMails::ingest($teamId, $this->message(['From' => "fulano.{$tag}@gmail.com", 'Subject' => 'Hola', 'Message-ID' => "<gm-{$tag}@example.test>"], 'text', 'Una pregunta.'));
        $threw = false;
        try {
            InboundMails::correct($gmail, 'consulta', 'fiscal', null, InboundMailRule::DOMAIN, $staff);
        } catch (\RuntimeException) {
            $threw = true;
        }
        $this->check('no se crean reglas para dominios públicos (y el correo no se mueve)', $threw && $gmail->fresh()->status === 'revisar'
            && InboundMailRule::query()->where('value', 'gmail.com')->doesntExist(), $gmail->fresh()->status);

        // ── Screens ────────────────────────────────────────────────────────────────────────────────────────────
        Auth::login($staff);
        \Filament\Facades\Filament::setCurrentPanel('admin');
        \Filament\Facades\Filament::setTenant($team, isQuiet: true);
        $inbox = \Livewire\Livewire::withQueryParams(['bandeja' => 'entrada'])->test(\App\Filament\Pages\InboundMailInbox::class)->html();
        $review = \Livewire\Livewire::withQueryParams(['bandeja' => 'revisar'])->test(\App\Filament\Pages\InboundMailInbox::class)->html();
        $settings = \Livewire\Livewire::test(\App\Filament\Pages\InboundMailSettingsPage::class)->html();
        $preview = (string) (fn (InboundMail $m) => self::preview($m))->call(new \App\Filament\Pages\InboundMailInbox, $new);
        $this->check('pantallas: «Entrada», «Pendiente de revisar» y ajustes se muestran', str_contains($inbox, 'Consulta renovación de residencia') && str_contains($review, 'Hola')
            && ! str_contains($review, 'Consulta renovación') && str_contains($settings, "extranjeria.{$tag}@example.test"),
            json_encode([str_contains($inbox, 'Consulta renovación de residencia'), str_contains($review, 'Hola'), ! str_contains($review, 'Consulta renovación'), str_contains($settings, "extranjeria.{$tag}@example.test")]));
        $this->check('vista del cuerpo: texto y HTML aislado (sin scripts)', str_contains($preview, 'necesito la renovación') && (! str_contains($preview, '<iframe') || str_contains($preview, 'sandbox=""')));
        $html = InboundMails::sanitize('<p onclick="x()">Hola<script>alert(1)</script><img src="https://rastreo.example/p.gif"></p>');
        $this->check('HTML saneado: sin scripts, eventos ni imágenes remotas', ! preg_match('/script|onclick|rastreo/i', $html) && str_contains($html, 'Hola'), $html);
        Auth::logout();

        // ── Permissions: other team, staff without content permission ──────────────────────────────────────────
        $other = $this->team('Empresa Ajena Ficticia');
        $outsider = $this->user('Admin Ajeno Ficticio', $other, 'admin');
        $this->check('otra empresa no ve el correo entrante ni sus adjuntos', ! InboundMailAccess::canView($outsider, $teamId) && $this->get(route('clarto.correo.adjunto', ['mail' => $amb->getKey(), 'index' => 0], false), $outsider) === 404);
        $this->check('el equipo con permiso descarga el adjunto', InboundMailAccess::canReadContent($staff, $teamId) && $this->get(route('clarto.correo.adjunto', ['mail' => $amb->getKey(), 'index' => 0], false), $staff) === 200);
        $viewer = $this->user('Recepción Ficticia', $team, 'staff');
        $before = InboundMailAccess::canView($viewer, $teamId);
        DB::table('role_has_permissions')->insert(['role_id' => DB::table('roles')->where(['team_id' => $teamId, 'name' => 'staff'])->value('id'),
            'permission_id' => DB::table('permissions')->where('name', InboundMailAccess::VIEW)->value('id')]);
        $this->check('personal sin permiso no ve la bandeja; con «correo.ver» la ve pero sin cuerpos ni adjuntos', ! $before && InboundMailAccess::canView($viewer, $teamId)
            && ! InboundMailAccess::canReadContent($viewer, $teamId) && $this->get(route('clarto.correo.adjunto', ['mail' => $amb->getKey(), 'index' => 0], false), $viewer) === 404);
        $this->check('un colaborador nunca ve esta bandeja', ! InboundMailAccess::canView($collabUser, $teamId));

        // ── Retention ──────────────────────────────────────────────────────────────────────────────────────────
        $pub->forceFill(['created_at' => now()->subDays(40)])->saveQuietly();
        $purged = InboundMails::purge(InboundMailSettings::forTeam($teamId));
        $this->check('la publicidad se borra pasados 30 días', $purged === 1 && InboundMail::query()->whereKey($pub->getKey())->doesntExist());
    }

    /**
     * A fake IMAP server: its whole answer is written in advance to one end of a socket pair; the real client
     * reads it from the other end. What the client sent is captured for the read-only checks.
     *
     * @param  list<int>|null  $search
     * @param  array<int, string>  $messages
     */
    private function imap(int $uidvalidity, int $uidnext, ?array $search, array $messages): ImapMailbox
    {
        [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $t = 0;
        $out = "* OK [CAPABILITY IMAP4rev1] servidor de prueba\r\n* ".count($messages)." EXISTS\r\n* OK [UIDVALIDITY {$uidvalidity}] UIDs\r\n* OK [UIDNEXT {$uidnext}] next\r\nA".(++$t)." OK [READ-ONLY] EXAMINE completed\r\n";
        if ($search !== null) {
            $out .= '* SEARCH '.implode(' ', $search)."\r\nA".(++$t)." OK SEARCH completed\r\n";
            foreach ($messages as $uid => $raw) {
                $out .= "* {$uid} FETCH (UID {$uid} BODY[] {".strlen($raw)."}\r\n".$raw.")\r\nA".(++$t)." OK FETCH completed\r\n";
            }
        }
        $out .= "* BYE\r\nA".(++$t)." OK LOGOUT completed\r\n";
        fwrite($server, $out);
        $box = new ImapMailbox($client);
        $this->pendingLog[] = [$server, $box];   // what the client sent is collected once it has logged out

        return $box;
    }

    /** @var list<array{0: resource, 1: ImapMailbox}> */
    private array $pendingLog = [];

    private function flushLog(): void
    {
        foreach ($this->pendingLog as $i => [$server, $box]) {
            if (! empty($box->sent) && str_contains(implode("\n", $box->sent), 'LOGOUT')) {
                stream_set_blocking($server, false);
                array_push($this->imapLog, ...array_filter(explode("\r\n", (string) stream_get_contents($server))));
                unset($this->pendingLog[$i]);
            }
        }
    }

    /**
     * @param  array<string, string>  $headers
     * @param  string|list<array{0: string, 1: string, 2: string, 3?: string}>  $body  text, or parts [type, encoding, body, disposition]
     */
    private function message(array $headers, string $kind, string|array $body): string
    {
        $headers += ['To' => 'info@example.test', 'Date' => now()->toRfc2822String(), 'MIME-Version' => '1.0'];
        if ($kind === 'text') {
            $headers['Content-Type'] = 'text/plain; charset=UTF-8';
            $headers['Content-Transfer-Encoding'] = '8bit';
        } else {
            $boundary = 'b-'.Str::random(12);
            $headers['Content-Type'] = 'multipart/mixed; boundary="'.$boundary.'"';
            $parts = '';
            foreach ($body as $p) {
                $parts .= "--{$boundary}\r\nContent-Type: {$p[0]}\r\nContent-Transfer-Encoding: {$p[1]}\r\n".(isset($p[3]) ? "Content-Disposition: {$p[3]}\r\n" : '')."\r\n{$p[2]}\r\n";
            }
            $body = "Preámbulo MIME\r\n".$parts."--{$boundary}--\r\n";
        }
        $head = '';
        foreach ($headers as $k => $v) {
            $head .= "{$k}: {$v}\r\n";
        }

        return $head."\r\n".str_replace(["\r\n", "\n"], ["\n", "\r\n"], (string) $body);
    }

    private function team(string $name): Team
    {
        $owner = $this->newUser("Titular {$name}");
        $team = Team::withoutEvents(fn () => Team::query()->forceCreate(['user_id' => $owner->id, 'name' => $name, 'personal_team' => false]));
        $owner->forceFill(['current_team_id' => $team->id])->saveQuietly();

        return $team;
    }

    private function user(string $name, Team $team, string $role): User
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
            'name' => $name, 'email' => 'prueba-correo-'.bin2hex(random_bytes(5)).'@example.com', 'password' => bcrypt(bin2hex(random_bytes(16))),
            'locale' => 'es', 'email_verified_at' => now(),
        ]));
    }

    private function get(string $uri, User $user): int
    {
        Auth::login($user);
        $status = app(HttpKernel::class)->handle(Request::create($uri, 'GET'))->getStatusCode();
        Auth::logout();

        return $status;
    }

    private function check(string $label, bool $ok, string $detail = ''): void
    {
        $this->flushLog();
        $ok ? $this->pass++ : $this->fail++;
        $this->line(($ok ? 'PASS  ' : 'FAIL  ').$label.(! $ok && $detail !== '' ? '  ['.$detail.']' : ''));
    }
}
