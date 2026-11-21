<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Communication;
use App\Models\WhatsAppLine;
use App\Valuations\ReportLink;
use App\Valuations\ReportLinks;
use App\Valuations\ValuationDelivery;
use App\Valuations\ValuationEngine;
use App\Valuations\ValuationReportMail;
use App\Valuations\ValuationRequest;
use App\Valuations\ValuationRun;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Liberu\Foundation\Organizations\Models\Team;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Public report links https://valoraciones.clarto.es/r/{codigo} (ReportLinks, InformeEnlaceController), in a
 * rolled-back transaction: fictitious request and PDF, simulated WAHA, e-mail faked. Nothing is kept.
 *
 *   php artisan clarto:enlaces-prueba
 */
final class TestReportLinks extends Command
{
    protected $signature = 'clarto:enlaces-prueba';

    protected $description = 'Test the public valuation report links (codes, page, PDF, expiry, revocation, previews, limits, headers, logs)';

    private const HOST = 'valoraciones.prueba.test';

    private int $pass = 0;

    private int $fail = 0;

    /** @var list<string> */
    private array $logged = [];

    /** @var list<array<string, mixed>> */
    private array $wahaSends = [];

    public function handle(): int
    {
        config([
            'cache.default' => 'array',
            'valoraciones.enlaces.host' => self::HOST,
            'valoraciones.enlaces.limite_por_ip' => 8,
            'valoraciones.informe_dias' => 30,
            'whatsapp.url' => 'http://waha-prueba.test',
            'ghl.token' => null,   // GoHighLevel not involved here (clarto:ghl-prueba covers it)
            'queue.default' => 'sync',
        ]);
        Mail::fake();
        Storage::fake(ValuationEngine::DISK);
        Http::preventStrayRequests();
        Http::fake(fn (HttpRequest $r) => $this->waha($r));
        Event::listen(MessageLogged::class, function (MessageLogged $e): void {
            $this->logged[] = $e->message.' '.json_encode($e->context);
        });

        DB::beginTransaction();
        try {
            $this->scenarios();
        } catch (Throwable $e) {
            $this->check('sin excepciones: '.$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine(), false);
        } finally {
            DB::rollBack();
            ReportLinks::forget();
        }

        $this->line(sprintf('RESULT: %d passed, %d failed (datos ficticios, WAHA simulado, base de datos revertida)', $this->pass, $this->fail));

        return $this->fail === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function scenarios(): void
    {
        $team = Team::query()->oldest()->firstOrFail();
        $line = WhatsAppLine::forTeam($team->id);
        $line->forceFill(['status' => 'WORKING'])->save();
        $v = $this->valuation($team->id);

        // 1. Codes.
        $codes = collect(range(1, 2000))->map(fn () => ReportLinks::newCode());
        $this->check('código: 22 caracteres URL-safe, 16 bytes (128 bits) del CSPRNG, 2.000 códigos distintos',
            $codes->every(fn (string $c): bool => (bool) preg_match('/^[A-Za-z0-9_-]{22}$/', $c) && strlen((string) base64_decode(strtr($c, '-_', '+/'), true)) === 16)
            && $codes->unique()->count() === 2000);
        $first = ReportLinks::issue($v, ReportLinks::EMAIL);
        $code = Str::afterLast($first['url'], '/r/');
        $row = DB::table('clarto_valuation_report_links')->where('id', $first['link']->getKey())->first();
        $this->check('se guarda el SHA-256, nunca el código (ninguna columna lo contiene), ligado a solicitud y cálculo',
            $row->code_hash === hash('sha256', $code) && ! str_contains((string) json_encode($row), $code)
            && (int) $row->valuation_run_id === $v->currentRun->getKey() && (int) $row->valuation_request_id === $v->getKey());
        $this->check('el código no deriva de la referencia ni de la solicitud ni de datos del cliente; URL https://<host>/r/{código}',
            $first['url'] === 'https://'.self::HOST.'/r/'.$code && ! str_contains($code, (string) preg_replace('/\D/', '', (string) $v->reference))
            && ! str_contains(Str::lower($code), 'val') && ! str_contains($v->request_id, $code));
        $this->check('caducidad guardada: '.ReportLinks::days().' días',
            abs($first['expires_at']->diffInHours(now()->addDays(30))) < 1);
        $this->check('generador central: correo, WhatsApp y GHL del mismo envío comparten el enlace; otro proceso crea uno nuevo',
            ReportLinks::issue($v, ReportLinks::WHATSAPP)['url'] === $first['url'] && ReportLinks::forGhl($v)['url'] === $first['url']
            && (function () use ($v, $first): bool {
                ReportLinks::forget();
                $other = ReportLinks::issue($v, ReportLinks::WHATSAPP)['url'];
                ReportLinks::forget();

                return $other !== $first['url'] && ReportLink::query()->where('valuation_request_id', $v->getKey())->count() === 2;
            })());
        ReportLinks::forget();
        $first['link']->forceFill(['ghl_at' => now()])->save();
        $this->check('GHL: si ya tiene un enlace vivo de este cálculo, no se reescribe (null = dejar el campo)', ReportLinks::forGhl($v) === null);

        // 2. Page and PDF on the public host.
        $page = $this->get('/r/'.$code);
        $html = (string) $page->getContent();
        $this->check('página: 200 en el host público, sin redirección ni cookies, con intervalo, referencia, zona y fecha de caducidad',
            $page->getStatusCode() === 200 && ! $page->headers->has('Location') && $page->headers->getCookies() === []
            && str_contains($html, '150.000') && str_contains($html, (string) $v->reference) && str_contains($html, 'Valencia')
            && str_contains($html, $first['expires_at']->copy()->timezone('Europe/Madrid')->format('d/m/Y')));
        $this->check('cabeceras: X-Robots-Tag noindex, Referrer-Policy no-referrer, CSP estricta (default-src none, sin http), no-store',
            $this->safeHeaders($page) && str_contains($html, '<meta name="robots" content="noindex'));
        $this->check('sin recursos de terceros ni analítica: sin <script>, sin src/href http(s) (solo tel:, wa.me y mailto: de contacto), enlaces relativos al mismo host',
            ! str_contains($html, '<script') && ! preg_match('/src="https?:/', $html) && ! preg_match('/<link[^>]+href=/', $html)
            && collect(preg_match_all('/href="([^"]+)"/', $html, $m) ? $m[1] : [])->every(fn (string $h): bool => str_starts_with($h, '/r/'.$code.'/') || str_starts_with($h, 'tel:') || str_starts_with($h, 'mailto:') || str_starts_with($h, 'https://wa.me/')));
        $pdf = $this->get('/r/'.$code.'/informe.pdf');
        $download = $this->get('/r/'.$code.'/informe.pdf?descargar=1');
        $this->check('PDF servido desde el host público: inline y ?descargar=1 como adjunto, mismas cabeceras, sin redirección',
            $pdf->getStatusCode() === 200 && $pdf->headers->get('Content-Type') === 'application/pdf' && str_starts_with((string) $pdf->headers->get('Content-Disposition'), 'inline')
            && str_starts_with((string) $download->headers->get('Content-Disposition'), 'attachment') && $this->safeHeaders($pdf) && ! $pdf->headers->has('Location')
            && str_starts_with((string) $pdf->getContent(), '%PDF'));
        $this->check('otro host (el panel) no sirve /r/*: 404', $this->get('/r/'.$code, host: 'panel.clarto.es')->getStatusCode() === 404);
        $unknown = $this->get('/r/'.ReportLinks::newCode());
        $this->check('código desconocido: 404 con página clara y las mismas cabeceras; mal formado: 404',
            $unknown->getStatusCode() === 404 && str_contains((string) $unknown->getContent(), 'Enlace no válido') && $this->safeHeaders($unknown)
            && $this->get('/r/abc')->getStatusCode() === 404);

        // 3. Previews and scanners do not consume or invalidate the link; people are counted.
        $link = $first['link'];
        $link->refresh()->forceFill(['opens' => 0])->save();
        $v->refresh()->forceFill(['report_opens' => 0])->save();
        foreach (['WhatsApp/2.24.1.6 A', 'facebookexternalhit/1.1', 'Mozilla/5.0 (compatible; Proofpoint URL Defense)', 'Mimecast scanner', 'Microsoft Office SafeLinks'] as $agent) {
            $this->get('/r/'.$code, agent: $agent);
            $this->get('/r/'.$code.'/informe.pdf', agent: $agent);
        }
        $this->send('HEAD', '/r/'.$code);
        $afterPreviews = [(int) $link->refresh()->opens, $link->state()];

        $person = $this->get('/r/'.$code);
        $this->check('vistas previas (WhatsApp, Facebook) y escáneres de correo (Proofpoint, Mimecast, SafeLinks) y HEAD: no cuentan ni invalidan; la persona después sí (1)',
            $afterPreviews === [0, 'activo'] && $person->getStatusCode() === 200 && (int) $link->refresh()->opens === 1 && (int) $v->refresh()->report_opens === 1);
        $this->get('/r/'.$code);
        $this->check('GET idempotente: abrir varias veces sigue funcionando (aperturas 2)', $this->get('/r/'.$code.'/informe.pdf')->getStatusCode() === 200 && (int) $link->refresh()->opens === 2);

        // 4. Rate limit.
        $codes429 = collect(range(1, 10))->map(fn () => $this->get('/r/'.ReportLinks::newCode(), ip: '198.51.100.7')->getStatusCode());
        $limited = $this->get('/r/'.$code, ip: '198.51.100.7');
        $this->check('límite por IP (8/min en la prueba): la 9.ª petición → 429 con página y cabeceras; otra IP sigue entrando',
            $codes429->take(8)->every(fn (int $s): bool => $s === 404) && $limited->getStatusCode() === 429 && str_contains((string) $limited->getContent(), 'Demasiadas peticiones')
            && $this->safeHeaders($limited) && $limited->headers->has('Retry-After') && $this->get('/r/'.$code, ip: '198.51.100.8')->getStatusCode() === 200);

        // 5. Delivery: owner's WhatsApp text, e-mail in «tú», register without the whole code.
        ReportLinks::forget();
        $v->forceFill(['delivery' => ValuationDelivery::initial()])->save();
        (new ValuationDelivery)->deliver($v->refresh());
        $sent = (string) ($this->wahaSends[0]['text'] ?? '');
        $waUrl = (string) Str::of($sent)->match('#https://\S+/r/[A-Za-z0-9_-]{22}#');
        $waLink = ReportLinks::resolve(Str::afterLast($waUrl, '/r/'))['link'];
        $expected = "Hola, Marta. Ya tienes disponible la valoración orientativa de tu inmueble en Valencia.\n\n"
            .'Puedes consultar y descargar el informe hasta el '.$waLink?->expires_at->copy()->timezone('Europe/Madrid')->format('d/m/Y').":\n{$waUrl}\n\n"
            ."Es una estimación informativa. Si estás pensando en vender, podemos revisar contigo las características de la vivienda y preparar una valoración personalizada.\n\n"
            ."¿Te viene mejor que te llamemos por la mañana o por la tarde?\n\nEquipo CLARTO";
        $this->check('WhatsApp de entrega = texto del propietario (tú, zona, fecha dd/mm/aaaa, enlace corto, «Equipo CLARTO»), sin franja ni oficina si no hay preferencia', $sent === $expected);
        $mail = Mail::sent(ValuationReportMail::class)->first();
        $this->check('correo: el mismo enlace corto que el WhatsApp, en «tú», con la fecha de caducidad y la misma invitación',
            $mail !== null && $mail->link === $waUrl && str_starts_with($mail->subjectLine(), 'Tu valoración de ')
            && str_contains($mail->plainText(), 'puedes consultarlo y descargarlo hasta el '.$waLink->expires_at->copy()->timezone('Europe/Madrid')->format('d/m/Y'))
            && str_contains($mail->plainText(), 'Si estás pensando en vender, podemos revisar contigo') && ! preg_match('/\b(usted|Le enviamos|su inmueble)\b/u', $mail->plainText())
            && str_contains($mail->render(), $waUrl) && str_contains($mail->render(), 'Vendemos tu vivienda'));
        $register = Communication::query()->where('related_id', (string) $v->getKey())->where('source', 'valoraciones')->pluck('body')->implode("\n");
        $this->check('registro de comunicaciones (correo y WhatsApp): enlace recortado «/r/xxxx…», nunca el código entero',
            $register !== '' && ! str_contains($register, Str::afterLast($waUrl, '/r/')) && substr_count($register, '/r/'.substr(Str::afterLast($waUrl, '/r/'), 0, 4).'…') === 2);
        $morning = ValuationDelivery::whatsAppText((clone $v)->forceFill(['contact' => [...$v->contact, 'franja' => 'Mañanas']]), $v->currentRun, $first);
        $this->check('franja confirmada en el formulario → «Te llamaremos por la mañana» en lugar de la pregunta; nunca se nombra oficina',
            str_contains($morning, 'Te llamaremos por la mañana.') && ! str_contains($morning, '¿Te viene mejor') && ! str_contains($morning, 'oficina'));

        // 6. Expiry.
        Carbon::setTestNow(now()->addDays(31));
        $expired = $this->get('/r/'.$code);
        $this->check('caducado: 410 con página clara («ha caducado» y la fecha), el PDF también 410', $expired->getStatusCode() === 410
            && str_contains((string) $expired->getContent(), 'ha caducado') && str_contains((string) $expired->getContent(), $link->expires_at->copy()->timezone('Europe/Madrid')->format('d/m/Y'))
            && $this->get('/r/'.$code.'/informe.pdf')->getStatusCode() === 410 && $this->safeHeaders($expired));
        Carbon::setTestNow();

        // 7. Old signed links (sent before the short links) keep working until expiry or revocation.
        URL::forceRootUrl((string) config('app.url'));   // as in a queue worker (the requests above changed the URL generator's host)
        $signed = (string) $v->signedReportUrl();
        URL::forceRootUrl(null);
        $signedHost = (string) parse_url($signed, PHP_URL_HOST);
        $relative = Str::after($signed, $signedHost);
        $this->check('enlace firmado antiguo ('.$signedHost.'/api/…): sigue sirviendo el PDF', $this->get($relative, host: $signedHost)->getStatusCode() === 200);

        // 8. Revocation and regeneration.
        $live = ReportLinks::issue($v, ReportLinks::EMAIL);
        $liveCode = Str::afterLast($live['url'], '/r/');
        $n = ReportLinks::revoke($v, null);
        $revoked = $this->get('/r/'.$liveCode);
        $this->check('revocar: todos los enlaces activos de la solicitud → 410 «ya no está disponible» (página y PDF)',
            $n >= 2 && $revoked->getStatusCode() === 410 && str_contains((string) $revoked->getContent(), 'ya no está disponible') && $this->get('/r/'.$liveCode.'/informe.pdf')->getStatusCode() === 410);
        $this->check('revocar: el enlace firmado antiguo también deja de servir (410)', $this->get($relative, host: $signedHost)->getStatusCode() === 410);
        $this->check('revocado: ningún productor emite otro solo (correo/WhatsApp null, GHL vacía el campo)',
            ReportLinks::issue($v->refresh(), ReportLinks::EMAIL) === null && ReportLinks::issue($v, ReportLinks::WHATSAPP) === null && ReportLinks::forGhl($v) === ['url' => '', 'caduca' => '', 'link' => null]);
        $new = ReportLinks::regenerate($v->refresh(), null);
        $newCode = Str::afterLast($new['url'], '/r/');
        $this->check('regenerar: enlace nuevo que funciona, el anterior sigue revocado, GHL y mensajes reciben el nuevo',
            $this->get('/r/'.$newCode)->getStatusCode() === 200 && $this->get('/r/'.$liveCode)->getStatusCode() === 410
            && ReportLinks::issue($v->refresh(), ReportLinks::WHATSAPP)['url'] === $new['url'] && ReportLinks::forGhl($v)['url'] === $new['url'] && $newCode !== $liveCode);

        // 9. Logs: neither Laravel's log nor Telescope keep a whole code.
        $all = collect([$code, $liveCode, $newCode, Str::afterLast($waUrl, '/r/')]);
        $this->check('ningún código completo en los logs de Laravel de esta prueba ('.count($this->logged).' líneas)', $all->every(fn (string $c): bool => ! str_contains(implode("\n", $this->logged), $c)));
        if (class_exists(\Laravel\Telescope\Telescope::class) && property_exists(\Laravel\Telescope\Telescope::class, 'filterUsing')) {
            $entry = new \Laravel\Telescope\IncomingEntry(['uri' => '/r/'.$newCode, 'payload' => ['text' => $new['url']]]);
            $plain = new \Laravel\Telescope\IncomingEntry(['uri' => '/admin']);
            // Other providers add their own filters (observability keeps only failures): look for ours.
            $this->check('Telescope descarta cualquier entrada con un código (petición, llamada saliente); las demás no',
                collect(\Laravel\Telescope\Telescope::$filterUsing)->contains(fn ($f): bool => ! $f($entry) && $f($plain)));
        }
        $this->check('redact(): «…/r/AbCd…» para registros', ReportLinks::redact('ver '.$new['url'].' ya') === 'ver https://'.self::HOST.'/r/'.substr($newCode, 0, 4).'… ya');
    }

    private function valuation(int|string $teamId): ValuationRequest
    {
        $v = ValuationRequest::query()->create([
            'request_id' => (string) Str::uuid(), 'team_id' => $teamId, 'status' => 'estimada', 'reference' => 'VAL-PRUEBA-'.random_int(1000, 9999),
            'property' => ['direccion_confirmada' => 'Calle Ficticia 1', 'municipio' => 'València', 'codigo_postal' => '46006', 'tipo' => 'piso'],
            'contact' => ['nombre' => 'Marta Ficticia', 'email' => 'marta.ficticia@example.com', 'telefono' => '+34600000001'],
            'catastro' => ['municipio' => 'VALENCIA'],
        ]);
        Storage::disk(ValuationEngine::DISK)->put('prueba/enlaces.pdf', "%PDF-1.4\n% informe ficticio de prueba\n%%EOF");
        $run = ValuationRun::query()->create([
            'valuation_request_id' => $v->getKey(), 'number' => 1, 'version' => 'prueba', 'status' => 'estimada', 'trigger' => 'prueba',
            'result' => ['precio_central' => 165000, 'intervalo' => ['min' => 150000, 'max' => 180000], 'suficiencia' => 'alta'],
            'pdf_path' => 'prueba/enlaces.pdf', 'pdf_pages' => 1,
        ]);
        $v->forceFill(['current_run_id' => $run->getKey()])->save();

        return $v->refresh();
    }

    private function safeHeaders(Response $r): bool
    {
        $csp = (string) $r->headers->get('Content-Security-Policy');

        return str_contains((string) $r->headers->get('X-Robots-Tag'), 'noindex') && $r->headers->get('Referrer-Policy') === 'no-referrer'
            && str_starts_with($csp, "default-src 'none'") && ! str_contains($csp, 'http') && str_contains((string) $r->headers->get('Cache-Control'), 'no-store');
    }

    private function get(string $uri, string $host = self::HOST, string $agent = 'Mozilla/5.0 (Linux; Android 14) prueba', ?string $ip = null): Response
    {
        return $this->send('GET', $uri, $host, $agent, $ip);
    }

    private function send(string $method, string $uri, string $host = self::HOST, string $agent = 'Mozilla/5.0 prueba', ?string $ip = null): Response
    {
        return app(Kernel::class)->handle(Request::create('https://'.$host.$uri, $method, [], [], [], [
            'REMOTE_ADDR' => $ip ?? '203.0.113.'.random_int(2, 250), 'HTTP_USER_AGENT' => $agent, 'HTTP_ACCEPT' => 'text/html',
        ]));
    }

    /** Simulated WAHA: line linked, sendText recorded. */
    private function waha(HttpRequest $request): mixed
    {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        if ($request->method() === 'GET' && str_starts_with($path, '/api/sessions/')) {
            return Http::response(['name' => 'default', 'status' => 'WORKING', 'me' => ['id' => '34960303131@c.us']]);
        }
        if ($request->method() === 'POST' && $path === '/api/sendText') {
            $this->wahaSends[] = (array) $request->data();

            return Http::response(['id' => 'true_prueba_'.count($this->wahaSends)], 201);
        }

        return Http::response(['message' => 'no simulado'], 599);
    }

    private function check(string $label, bool $ok): void
    {
        $ok ? $this->pass++ : $this->fail++;
        $this->line(($ok ? 'PASS' : 'FAIL').' · '.$label);
    }
}
