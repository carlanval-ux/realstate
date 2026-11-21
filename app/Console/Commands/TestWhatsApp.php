<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Filament\Communications\CommunicationsUi;
use App\Models\WhatsAppLine;
use App\Models\Communication;
use App\WhatsApp\Phone;
use App\WhatsApp\Waha;
use App\WhatsApp\WhatsApp;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\RealEstate\Instructions\Models\Instruction;
use Liberu\RealEstate\Parties\Models\Party;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * WhatsApp (WAHA) end to end with a SIMULATED WAHA (Http::fake that keeps the session state) and the real webhook
 * route. Runs in a rolled-back transaction: nothing is kept and nothing reaches WhatsApp.
 *
 *   php artisan clarto:whatsapp-prueba
 */
final class TestWhatsApp extends Command
{
    protected $signature = 'clarto:whatsapp-prueba';

    protected $description = 'Test the WhatsApp (WAHA) integration with a simulated WAHA (nothing is kept)';

    private int $pass = 0;

    private int $fail = 0;

    /** @var array{exists: bool, status: string, sent: list<array<string, mixed>>, created: ?array<string, mixed>, put: int} */
    private array $waha = ['exists' => false, 'status' => 'STOPPED', 'sent' => [], 'created' => null, 'put' => 0];

    public function handle(): int
    {
        config(['whatsapp.url' => 'http://waha.prueba:3000', 'whatsapp.api_key' => 'clave-prueba', 'whatsapp.webhook_hmac_key' => 'hmac-prueba',
            'whatsapp.webhook_url' => 'http://liberu-app/api/v1/whatsapp/webhook', 'whatsapp.session' => 'default']);

        DB::beginTransaction();
        try {
            $this->scenarios();
        } finally {
            DB::rollBack();
        }

        $this->line(sprintf('RESULT: %d passed, %d failed (WAHA simulated with Http::fake; database rolled back)', $this->pass, $this->fail));

        return $this->fail === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function scenarios(): void
    {
        $this->fakeWaha();
        $team = Team::query()->oldest()->firstOrFail();
        $actor = (int) ($team->user_id ?? 1);
        DB::table('clarto_whatsapp_links')->where('team_id', $team->getKey())->delete();
        $line = WhatsAppLine::forTeam($team->getKey());
        $line->update(['status' => 'SIN_CREAR', 'phone' => null]);
        $waha = new Waha;

        // 1. Session: start, QR, status.
        $waha->start($line->session);
        $created = $this->waha['created'] ?? [];
        $hook = $created['config']['webhooks'][0] ?? [];
        $this->check('vincular: crea la sesión en WAHA con webhook interno firmado (HMAC) y la arranca',
            ($created['name'] ?? null) === 'default' && ($created['start'] ?? false) === true && ($hook['url'] ?? '') === 'http://liberu-app/api/v1/whatsapp/webhook'
            && ($hook['hmac']['key'] ?? '') === 'hmac-prueba' && in_array('message', $hook['events'] ?? [], true));
        $this->check('estado: esperando el escaneo del QR', $line->refreshFromWaha($waha)->status === 'SCAN_QR_CODE' && ! $line->connected());
        $this->check('QR obtenido en el servidor como imagen', str_starts_with((string) $waha->qr($line->session), 'data:image/png;base64,'));

        $this->check('webhook sin firma válida: 401', $this->webhook(['id' => 'evt_x', 'event' => 'session.status', 'session' => 'default', 'payload' => ['status' => 'WORKING']], sign: 'otra')->getStatusCode() === 401);
        $this->check('webhook llegado por el proxy público: 403', $this->webhook(['id' => 'evt_y', 'event' => 'session.status', 'session' => 'default', 'payload' => ['status' => 'WORKING']], viaProxy: true)->getStatusCode() === 403);
        $this->waha['status'] = 'WORKING';
        $this->webhook(['id' => 'evt_'.Str::ulid(), 'event' => 'session.status', 'session' => 'default', 'me' => ['id' => '34960000000@c.us', 'pushName' => 'CLARTO'], 'payload' => ['status' => 'WORKING']]);
        $line->refresh();
        $this->check('escaneado: línea conectada con su número (E.164)', $line->connected() && $line->phone === '+34960000000');

        // 2. Send from the owner's 360 profile (and from the file).
        $make = fn (string $name, string $phone): Party => Party::query()->create(['team_id' => $team->getKey(), 'created_by' => $actor, 'type' => 'vendor', 'name' => $name, 'phone' => $phone]);
        $ana = $make('Ana Prueba Ficticia', '600 111 222');
        $bea = $make('Bea Prueba Ficticia', '+34 611 000 333');
        $carla = $make('Carla Prueba Ficticia', '0034611000333');
        $file = Instruction::query()->create(['team_id' => $team->getKey(), 'created_by' => $actor, 'party_id' => $ana->getKey(), 'subject' => 'Encargo prueba WhatsApp', 'status' => 'draft']);

        $this->check('teléfonos normalizados a E.164', Phone::e164('600 111 222') === '+34600111222' && Phone::e164('0034611000333') === '+34611000333' && Phone::e164('abc') === null);
        $this->check('el expediente abre la conversación con su propietario', CommunicationsUi::party($file)?->is($ana) === true);
        $text = WhatsApp::render("Hola {nombre}, soy {agente} de {empresa}. {enlace_firma}", $ana, $file, 'Agente Prueba');
        $this->check('plantilla rellenada con la ficha; lo desconocido queda entre llaves', str_starts_with($text, 'Hola Ana, soy Agente Prueba de ') && str_contains($text, '{enlace_firma}'));
        $refused = false;
        try {
            WhatsApp::send($ana, '+34600111222', $text, (string) Str::uuid(), $actor);
        } catch (RuntimeException) {
            $refused = true;
        }
        $this->check('no se envía con campos sin completar', $refused && $this->waha['sent'] === []);

        $key = (string) Str::uuid();
        $sent = WhatsApp::send($ana, '600111222', 'Hola Ana, le escribimos de prueba.', $key, $actor, $file);
        $this->check('envío desde la ficha: enviado a 34600111222@c.us con su id de WhatsApp',
            $sent->status === 'enviado' && filled($sent->provider_id) && ($this->waha['sent'][0]['chatId'] ?? '') === '34600111222@c.us' && $sent->instruction_id === $file->getKey());
        $again = WhatsApp::send($ana, '600111222', 'Hola Ana, le escribimos de prueba.', $key, $actor, $file);
        $this->check('mismo envío repetido (doble clic / reintento): no se reenvía', $again->is($sent) && count($this->waha['sent']) === 1);

        $this->webhook(['id' => 'evt_'.Str::ulid(), 'event' => 'message.ack', 'session' => 'default', 'payload' => ['id' => $sent->provider_id, 'ack' => 2]]);
        $this->check('acuse de WhatsApp: entregado', $sent->refresh()->status === 'entregado');

        // 3. Inbound reply → the right owner.
        $reply = $this->inbound('34600111222@c.us', 'Perfecto, gracias');
        $msg = Communication::query()->where('provider_id', $reply['payload']['id'])->first();
        $this->check('respuesta entrante vinculada a la propietaria correcta', $msg?->party_id === $ana->getKey() && $msg->status === 'recibido' && $msg->phone === '+34600111222');
        $this->check('historial de la ficha: enviado y recibido en orden', WhatsApp::conversation($ana)->pluck('direction')->all() === ['out', 'in']);

        // 4. Duplicates: same event again, and the same message under another event id.
        $count = Communication::query()->count();
        $r1 = $this->webhook($reply);
        $r2 = $this->webhook(['id' => 'evt_'.Str::ulid()] + $reply);
        $this->check('webhook duplicado (mismo evento y otro evento del mismo mensaje): sin duplicados',
            json_decode((string) $r1->getContent(), true)['estado'] === 'duplicado' && $r2->getStatusCode() === 200 && Communication::query()->count() === $count);

        // 5. Ambiguous phone → resolution queue.
        $amb = $this->inbound('34611000333@c.us', '¿Quién me escribe?');
        $amsg = Communication::query()->where('provider_id', $amb['payload']['id'])->first();
        $this->check('teléfono de dos contactos: a la cola de resolución con ambos candidatos',
            $amsg?->party_id === null && $amsg->status === Communication::UNLINKED && collect($amsg->candidates)->sort()->values()->all() === collect([$bea->getKey(), $carla->getKey()])->sort()->values()->all());
        $unknown = $this->inbound('34699999999@c.us', 'Vi su anuncio');
        $this->check('número desconocido: a la cola, sin crear contactos (la captación sigue en GHL)',
            Communication::query()->where('provider_id', $unknown['payload']['id'])->value('status') === Communication::UNLINKED && Party::query()->where('phone', 'like', '%699999999%')->doesntExist());
        $linked = WhatsApp::link($team->getKey(), '+34611000333', (int) $carla->getKey());
        $next = $this->inbound('34611000333@c.us', 'Soy Carla');
        $this->check('resuelto por el equipo: mensajes vinculados y los siguientes llegan a Carla',
            $linked === 1 && $amsg->refresh()->party_id === $carla->getKey() && Communication::query()->where('provider_id', $next['payload']['id'])->value('party_id') === $carla->getKey());

        // 6. Disconnect, then reconnect.
        $waha->logout($line->session);
        $this->webhook(['id' => 'evt_'.Str::ulid(), 'event' => 'session.status', 'session' => 'default', 'payload' => ['status' => 'STOPPED']]);
        $blocked = false;
        try {
            WhatsApp::send($ana, '+34600111222', 'No debería salir', (string) Str::uuid(), $actor);
        } catch (RuntimeException) {
            $blocked = true;
        }
        $this->check('desconectada: no se envía nada', $blocked && $line->refresh()->status === 'STOPPED' && count($this->waha['sent']) === 1);
        $waha->start($line->session);
        $this->check('reconectar: reconfigura el webhook y arranca la sesión existente', $this->waha['put'] === 1 && $line->refreshFromWaha($waha)->status === 'SCAN_QR_CODE');
        $this->waha['status'] = 'WORKING';
        $this->webhook(['id' => 'evt_'.Str::ulid(), 'event' => 'session.status', 'session' => 'default', 'me' => ['id' => '34960000000@c.us'], 'payload' => ['status' => 'WORKING']]);
        $after = WhatsApp::send($ana, '+34600111222', 'De nuevo conectados', (string) Str::uuid(), $actor);
        $this->check('tras reconectar: se envía de nuevo', $after->status === 'enviado' && count($this->waha['sent']) === 2);
    }

    /** WAHA simulated: keeps whether the session exists, its status and what was sent. */
    private function fakeWaha(): void
    {
        Http::preventStrayRequests();
        Http::fake(function (ClientRequest $request) {
            if (! $request->hasHeader('X-Api-Key', 'clave-prueba')) {
                return Http::response(['message' => 'Unauthorized'], 401);
            }
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $session = fn () => Http::response(['name' => 'default', 'status' => $this->waha['status'], 'me' => $this->waha['status'] === 'WORKING' ? ['id' => '34960000000@c.us', 'pushName' => 'CLARTO'] : null]);

            return match (true) {
                $request->method() === 'POST' && $path === '/api/sessions' => (function () use ($request, $session) {
                    $this->waha = ['exists' => true, 'status' => 'SCAN_QR_CODE', 'created' => $request->data()] + $this->waha;

                    return $session();
                })(),
                $request->method() === 'GET' && $path === '/api/sessions/default' => $this->waha['exists'] ? $session() : Http::response(['message' => 'not found'], 404),
                $request->method() === 'PUT' && $path === '/api/sessions/default' => (function () use ($session) {
                    $this->waha['put']++;

                    return $session();
                })(),
                $request->method() === 'POST' && in_array($path, ['/api/sessions/default/start', '/api/sessions/default/restart'], true) => (function () use ($session) {
                    $this->waha['status'] = 'SCAN_QR_CODE';

                    return $session();
                })(),
                $request->method() === 'POST' && in_array($path, ['/api/sessions/default/logout', '/api/sessions/default/stop'], true) => (function () use ($session) {
                    $this->waha['status'] = 'STOPPED';

                    return $session();
                })(),
                $path === '/api/default/auth/qr' => $this->waha['status'] === 'SCAN_QR_CODE'
                    ? Http::response(['mimetype' => 'image/png', 'data' => base64_encode('qr-prueba')])
                    : Http::response(['message' => 'not scanning'], 422),
                $request->method() === 'POST' && $path === '/api/sendText' => $this->waha['status'] !== 'WORKING'
                    ? Http::response(['message' => 'session not working'], 422)
                    : (function () use ($request) {
                        $this->waha['sent'][] = $request->data();

                        return Http::response(['id' => 'true_'.$request->data()['chatId'].'_'.Str::upper(Str::random(20)), 'body' => $request->data()['text']], 201);
                    })(),
                default => Http::response(['message' => 'unexpected '.$request->method().' '.$path], 500),
            };
        });
    }

    /** @return array<string, mixed> the event delivered */
    private function inbound(string $from, string $body): array
    {
        $event = ['id' => 'evt_'.Str::ulid(), 'timestamp' => now()->getTimestampMs(), 'event' => 'message', 'session' => 'default', 'engine' => 'GOWS',
            'payload' => ['id' => 'false_'.$from.'_'.Str::upper(Str::random(20)), 'timestamp' => now()->timestamp, 'from' => $from, 'fromMe' => false, 'body' => $body, 'hasMedia' => false]];
        $this->webhook($event);

        return $event;
    }

    /** @param array<string, mixed> $event */
    private function webhook(array $event, ?string $sign = null, bool $viaProxy = false): Response
    {
        $body = (string) json_encode($event);
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_WEBHOOK_HMAC' => hash_hmac('sha512', $body, $sign ?? 'hmac-prueba'), 'HTTP_X_WEBHOOK_HMAC_ALGORITHM' => 'sha512'];
        if ($viaProxy) {
            $server['HTTP_X_FORWARDED_FOR'] = '203.0.113.7';
        }

        return app(Kernel::class)->handle(Request::create('/api/v1/whatsapp/webhook', 'POST', [], [], [], $server, $body));
    }

    private function check(string $label, bool $ok): void
    {
        $ok ? $this->pass++ : $this->fail++;
        $this->line(($ok ? 'PASS  ' : 'FAIL  ').$label);
    }
}
