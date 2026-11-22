<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Acquisition\NurturePlan;
use App\Acquisition\NurtureSlot;
use App\Filament\Resources\ValuationRequestResource;
use App\Models\User;
use App\Valuations\ReportLink;
use App\Valuations\ReportLinks;
use App\Valuations\Retention;
use App\Valuations\ValuationEngine;
use App\Valuations\ValuationRequest;
use App\Valuations\ValuationRun;
use Illuminate\Console\Command;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\RealEstate\Parties\Models\Party;
use Throwable;

/**
 * Retention of valuation requests (App\Valuations\Retention) in a rolled-back transaction, with fictitious people,
 * a fake disk and no outgoing HTTP. Nothing is kept.
 *
 *   php artisan clarto:conservacion-prueba
 */
final class TestValuationRetention extends Command
{
    protected $signature = 'clarto:conservacion-prueba';

    protected $description = 'Test the retention of valuation requests (24 months anonymised, 12 months consent, exceptions, dry run, counts-only log)';

    private int $pass = 0;

    private int $fail = 0;

    private int $team;

    /** @var list<array{message: string, context: array<string, mixed>}> */
    private array $logged = [];

    public function handle(): int
    {
        config([
            'cache.default' => 'array',
            'ghl.token' => null,   // GoHighLevel never called
            'queue.default' => 'sync',
            'valoraciones.conservacion.bloqueo' => ['VAL-PRUEBA-BLOQUEO'],
        ]);
        Storage::fake(ValuationEngine::DISK);
        Http::preventStrayRequests();
        Event::listen(MessageLogged::class, function (MessageLogged $e): void {
            $this->logged[] = ['message' => $e->message, 'context' => $e->context];
        });

        DB::beginTransaction();
        try {
            $this->scenarios();
        } catch (Throwable $e) {
            $this->check('sin excepciones: '.$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine(), false);
        } finally {
            DB::rollBack();
        }

        $this->line(sprintf('RESULT: %d passed, %d failed (datos ficticios, disco simulado, base de datos revertida)', $this->pass, $this->fail));

        return $this->fail === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function scenarios(): void
    {
        $this->team = (int) Team::query()->oldest()->firstOrFail()->getKey();
        $disk = Storage::disk(ValuationEngine::DISK);

        // A. 25 months, nothing since: everything personal goes.
        $a = $this->request('A', 25, '+34600999001', 'ana.ficticia@example.test', ['ghl_contact_id' => 'ghl-prueba-a', 'ghl_opportunity_id' => 'opp-prueba-a',
            'client_ip' => '203.0.113.7', 'attribution' => ['gclid' => 'gclid-ficticio', 'utm' => ['source' => 'google']], 'property_key' => 'rc:0000000AA0000A0001AA']);
        $link = $this->link($a, opened: 25);
        $plan = NurturePlan::query()->create(['team_id' => $this->team, 'contact_id' => 'ghl-prueba-a', 'valuation_request_id' => $a->getKey(), 'phone' => '+34600999001',
            'rama' => 'fria', 'estado' => 'terminado', 'started_at' => now()->subMonths(25), 'eventos' => []]);
        $plan->slots()->create(['codigo' => 'wa_f1', 'evento' => 'c3', 'send_after' => now()->subMonths(25), 'window_end' => now()->subMonths(25), 'estado' => 'enviado']);
        $out = $this->message($a, 'out', 25, 'Hola Ana, su valoración: https://valoraciones.prueba.test/r/codigoficticio', related: true);
        $in = $this->message($a, 'in', 25, 'Gracias, soy Ana Ficticia');
        DB::table('clarto_ghl_whatsapp_orders')->insert(['clave' => 'prueba-'.Str::uuid(), 'contact_id' => 'ghl-prueba-a', 'tipo' => 'w2', 'texto' => 'Hola Ana Ficticia',
            'estado' => 'enviado', 'phone' => '+34600999001', 'created_at' => now()->subMonths(25), 'updated_at' => now()->subMonths(25)]);

        // B–G: old requests that stay.
        $b = $this->request('B', 25, '+34600999002', 'bruno.ficticio@example.test');
        $this->message($b, 'in', 3, 'Hola, sigo pensando en vender');
        $c = $this->request('C', 25, '+34600999003', 'carla.ficticia@example.test');
        $this->link($c, opened: 10);
        $d = $this->request('D', 25, '+34600999004', 'dani.ficticio@example.test');
        $this->request('D2', 6, '+34600999040', 'Dani.Ficticio@example.test');   // same person (e-mail), asked again 6 months ago
        $e = $this->request('E', 25, '+34600999005', 'elena.ficticia@example.test');
        Party::query()->create(['team_id' => $this->team, 'created_by' => (int) (DB::table('users')->min('id') ?? 1), 'type' => 'vendor',
            'name' => 'Elena Ficticia (prueba)', 'phone' => '+34 600 99 90 05']);   // free-form phone, same number
        $f = $this->request('F', 25, '+34600999006', 'fede.ficticio@example.test', ['property_id' => 99999999]);   // won → draft property
        $g = $this->request('G', 25, '+34600999007', 'gema.ficticia@example.test', ['reference' => 'VAL-PRUEBA-BLOQUEO']);

        // H–J: commercial consent.
        $consent = fn (int $months): array => ['version' => 'prueba', 'fecha' => now()->subMonths($months)->toIso8601String(),
            'comercial' => ['email' => true, 'whatsapp' => true, 'version' => 'prueba', 'fecha' => now()->subMonths($months)->toIso8601String()]];
        $h = $this->request('H', 13, '+34600999008', 'hugo.ficticio@example.test', ['consent' => $consent(13), 'ghl_contact_id' => 'ghl-prueba-h']);
        $hPlan = NurturePlan::query()->create(['team_id' => $this->team, 'contact_id' => 'ghl-prueba-h', 'valuation_request_id' => $h->getKey(), 'phone' => '+34600999008',
            'rama' => 'activa', 'estado' => 'activo', 'started_at' => now()->subMonths(13), 'eventos' => []]);
        $hSlot = $hPlan->slots()->create(['codigo' => 'wa1', 'evento' => 'c2', 'send_after' => now()->addDay(), 'window_end' => now()->addDay()->addHour(), 'estado' => 'programado']);
        $i = $this->request('I', 13, '+34600999009', 'ines.ficticia@example.test', ['consent' => $consent(13)]);
        $this->request('I2', 2, '+34600999009', 'ines.ficticia@example.test', ['consent' => $consent(2)]);   // renewed 2 months ago
        $j = $this->request('J', 6, '+34600999010', 'jorge.ficticio@example.test', ['consent' => $consent(6)]);

        // Dry run: counts, changes nothing.
        $before = $this->snapshot();
        Artisan::call('clarto:valoraciones-conservacion', ['--dry-run' => true]);
        $dry = $this->lastLog();
        $this->check('simulacro (--dry-run): no cambia nada (solicitudes, cálculos, PDF, enlaces, planes, mensajes, órdenes)', $this->snapshot() === $before && $disk->exists($this->pdfPath($a)));

        Artisan::call('clarto:valoraciones-conservacion');
        $real = $this->lastLog();
        $this->check('simulacro y ejecución real dan los mismos recuentos', $dry !== null && $dry === $real);
        $this->check('recuentos: al menos A anonimizada, 1 PDF, 1 enlace, 1 plan, 2 mensajes, 1 orden; E cliente; G bloqueo; H consentimiento',
            $real !== null && $real['anonimizadas'] >= 1 && $real['pdf_borrados'] >= 1 && $real['enlaces_borrados'] >= 1 && $real['planes_nutricion_borrados'] >= 1
            && $real['comunicaciones_anonimizadas'] >= 2 && $real['ordenes_whatsapp_borradas'] >= 1 && $real['excluidas_cliente'] >= 1
            && $real['excluidas_bloqueo_legal'] >= 1 && $real['consentimientos_caducados'] >= 1 && $real['planes_nutricion_cancelados'] >= 1);

        // A anonymised.
        $a->refresh();
        $run = $a->currentRun;
        $this->check('A: sin nombre, email, teléfono ni comentario; sin consentimiento, atribución, IP, Catastro, ids de GHL ni clave de inmueble',
            $a->contact === [] && $a->consent === null && $a->attribution === null && $a->client_ip === null && $a->catastro === null
            && $a->ghl_contact_id === null && $a->ghl_opportunity_id === null && $a->property_key === null && $a->delivery === null && $a->anonymized_at !== null);
        $this->check('A: del inmueble queda solo municipio, provincia, tipo y superficie (sin dirección, referencia catastral ni planta)',
            array_diff(array_keys((array) $a->property), Retention::PROPERTY_KEEP) === [] && $a->property['municipio'] === 'VALENCIA'
            && $a->property['tipo'] === 'piso' && (float) $a->property['superficie_construida_m2'] === 85.0);
        $this->check('A: el cálculo conserva estimación, intervalo y fecha; sin resumen, mensaje, sujeto ni ruta de PDF',
            $run->result['precio_central'] === 165000 && $run->result['intervalo'] === ['min' => 150000, 'max' => 180000] && (float) ($run->result['superficie_m2'] ?? 0) === 85.0
            && array_diff(array_keys($run->result), [...Retention::RESULT_KEEP, 'superficie_m2']) === [] && $run->summary === null && $run->pdf_path === null);
        $this->check('A: fechas y referencia intactas (estadística)', $a->reference === 'VAL-PRUEBA-A' && $a->created_at->lt(now()->subMonths(24)));
        $this->check('A: PDF y carpeta borrados del disco', ! $disk->exists($this->pdfPath($a)) && $disk->allFiles('valoraciones/VAL-PRUEBA-A') === []);
        $this->check('A: enlaces /r/{codigo} borrados, enlaces firmados revocados y no se puede emitir uno nuevo',
            ! ReportLink::query()->whereKey($link->getKey())->exists() && $a->report_links_revoked_at !== null && ReportLinks::issue($a, ReportLinks::PANEL) === null);
        $this->check('A: plan de nutrición WhatsApp y sus mensajes programados borrados',
            ! NurturePlan::query()->whereKey($plan->getKey())->exists() && ! NurtureSlot::query()->where('plan_id', $plan->getKey())->exists());
        $msgs = DB::table('clarto_communications')->whereIn('id', [$out, $in])->get();
        $this->check('A: mensajes del registro de comunicaciones (envío y respuesta) sin teléfono ni texto',
            $msgs->count() === 2 && $msgs->every(fn ($m): bool => $m->phone === null && $m->email === null && $m->body === Retention::REDACTED));
        $this->check('A: órdenes de WhatsApp de GHL borradas', ! DB::table('clarto_ghl_whatsapp_orders')->where('contact_id', 'ghl-prueba-a')->exists());
        $stored = json_encode([$a->getAttributes(), $run->getAttributes()], JSON_UNESCAPED_UNICODE);
        $this->check('A: ningún dato personal queda en la fila ni en el cálculo',
            ! Str::contains($stored, ['Ana', 'ana.ficticia', '600999001', 'Ficticia 1', '0000000AA', '203.0.113', 'gclid']));
        $admin = User::query()->get()->first(fn (User $u): bool => $u->isSuperAdmin());
        if ($admin !== null) {
            Auth::setUser($admin);
        }
        $this->check('panel: una solicitud anonimizada queda en solo lectura (sin recalcular, reenviar, enlaces ni GHL), también para el superadministrador',
            $admin !== null && ! ValuationRequestResource::canManage($a) && ValuationRequestResource::canManage($b->refresh()));

        // Exceptions and last contact.
        $kept = fn (ValuationRequest $v): bool => $v->refresh()->anonymized_at === null && ($v->contact['email'] ?? null) !== null && $disk->exists($this->pdfPath($v));
        $this->check('B: respondió por WhatsApp hace 3 meses → último contacto reciente, se conserva', $kept($b));
        $this->check('C: abrió el informe hace 10 meses → se conserva', $kept($c));
        $this->check('D: pidió otra valoración hace 6 meses (mismo email, otras mayúsculas) → se conserva', $kept($d));
        $this->check('E: su teléfono es de un contacto de CLARTO (cliente/propietario) → se conserva', $kept($e));
        $this->check('F: oportunidad ganada convertida en inmueble → se conserva', $kept($f));
        $this->check('G: bloqueo legal (VALORACIONES_CONSERVACION_BLOQUEO) → se conserva', $kept($g));

        // Consent.
        $h->refresh();
        $this->check('H: consentimiento comercial de hace 13 meses caducado (email y WhatsApp a «no», fecha de caducidad; versión y fecha originales guardadas)',
            $h->consent['comercial']['email'] === false && $h->consent['comercial']['whatsapp'] === false && filled($h->consent['comercial']['caducado'] ?? null)
            && $h->consent['comercial']['version'] === 'prueba' && $h->anonymized_at === null && $h->contact['email'] === 'hugo.ficticio@example.test');
        $this->check('H: su plan de nutrición queda cancelado y el WhatsApp programado no saldrá',
            $hPlan->refresh()->estado === 'cancelado' && str_contains((string) $hPlan->motivo, '12 meses') && $hSlot->refresh()->estado === 'cancelado');
        $this->check('I: renovó el consentimiento hace 2 meses con otra solicitud → no caduca', ($i->refresh()->consent['comercial']['email'] ?? null) === true);
        $this->check('J: solicitud de hace 6 meses → intacta', ($j->refresh()->consent['comercial']['whatsapp'] ?? null) === true && $kept($j));

        // Idempotent, and the log holds counts only.
        Artisan::call('clarto:valoraciones-conservacion');
        $again = $this->lastLog();
        $this->check('segunda ejecución: nada más que anonimizar ni caducar (idempotente)', $again !== null && $again['anonimizadas'] === 0 && $again['consentimientos_caducados'] === 0);
        $lines = collect($this->logged)->filter(fn (array $l): bool => str_starts_with($l['message'], 'Valoraciones: conservación'));
        $this->check('registro: solo recuentos, ningún nombre, email, teléfono ni referencia',
            $lines->count() === 3 && $lines->every(fn (array $l): bool => collect($l['context'])->every(fn ($v): bool => is_int($v)))
            && ! Str::contains((string) json_encode($lines->all(), JSON_UNESCAPED_UNICODE), ['Ficticia', 'Ficticio', 'example.test', '600999', 'VAL-PRUEBA']));
        $this->check('programado a diario (clarto-valoraciones-conservacion, 03:40, un solo servidor)',
            collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->contains(fn ($ev): bool => $ev->description === 'clarto-valoraciones-conservacion' && $ev->expression === '40 3 * * *' && $ev->onOneServer));
    }

    /** @param array<string, mixed> $extra */
    private function request(string $tag, int $monthsAgo, string $phone, string $email, array $extra = []): ValuationRequest
    {
        $at = now()->subMonths($monthsAgo);
        $v = ValuationRequest::query()->create($extra + [
            'request_id' => (string) Str::uuid(), 'team_id' => $this->team, 'status' => 'estimada', 'reference' => 'VAL-PRUEBA-'.$tag,
            'property' => ['direccion_confirmada' => 'Calle Ficticia 1, 3º B', 'rc' => '0000000AA0000A0001AA', 'municipio' => 'València', 'provincia' => 'Valencia',
                'codigo_postal' => '46006', 'tipo' => 'piso', 'superficie_construida_m2' => 85, 'planta' => '3'],
            'contact' => ['nombre' => ($tag === 'A' ? 'Ana' : 'Persona '.$tag).' Ficticia', 'email' => $email, 'telefono' => $phone, 'comentario' => 'Texto libre ficticio'],
            'catastro' => ['rc' => '0000000AA0000A0001AA', 'municipio' => 'VALENCIA', 'direccion' => 'CL FICTICIA 1'],
            'consent' => ['version' => 'prueba', 'fecha' => $at->toIso8601String()],
        ]);
        $path = 'valoraciones/'.$v->reference.'/informe-1.pdf';
        Storage::disk(ValuationEngine::DISK)->put($path, "%PDF-1.4\n% informe ficticio de prueba\n%%EOF");
        $run = ValuationRun::query()->create([
            'valuation_request_id' => $v->getKey(), 'number' => 1, 'version' => 'prueba', 'status' => 'estimada', 'trigger' => 'prueba',
            'result' => ['estado' => 'estimada', 'precio_central' => 165000, 'intervalo' => ['min' => 150000, 'max' => 180000], 'suficiencia' => 'alta',
                'subject' => ['direccion' => 'Calle Ficticia 1'], 'calculo' => ['superficie_m2' => 85.0, 'ajustes' => [['concepto' => 'planta 3']]], 'resumen' => 'Resumen para Ana'],
            'summary' => 'Resumen para Ana', 'pdf_path' => $path, 'pdf_pages' => 1,
        ]);
        $v->forceFill(['current_run_id' => $run->getKey()])->save();
        DB::table('clarto_valuation_requests')->where('id', $v->getKey())->update(['created_at' => $at, 'updated_at' => $at]);
        DB::table('clarto_valuation_runs')->where('id', $run->getKey())->update(['created_at' => $at, 'updated_at' => $at]);

        return $v->refresh();
    }

    private function link(ValuationRequest $v, int $opened): ReportLink
    {
        return ReportLink::query()->create(['valuation_request_id' => $v->getKey(), 'valuation_run_id' => $v->current_run_id, 'code_hash' => hash('sha256', Str::random(40)),
            'origin' => 'email', 'expires_at' => now()->subMonths($opened)->addDays(30), 'opens' => 1, 'last_opened_at' => now()->subMonths($opened)]);
    }

    /** Straight into the table: no WAHA/GHL hooks. */
    private function message(ValuationRequest $v, string $direction, int $monthsAgo, string $body, bool $related = false): int
    {
        return (int) DB::table('clarto_communications')->insertGetId([
            'team_id' => $this->team, 'direction' => $direction, 'channel' => 'whatsapp', 'phone' => $v->contact['telefono'], 'body' => $body,
            'status' => $direction === 'in' ? 'recibido' : 'enviado', 'idempotency_key' => 'prueba-conservacion-'.Str::uuid(),
            'purpose' => 'servicio', 'source' => 'valoraciones', 'related_type' => $related ? $v->getMorphClass() : null, 'related_id' => $related ? (string) $v->getKey() : null,
            'created_at' => now()->subMonths($monthsAgo), 'updated_at' => now()->subMonths($monthsAgo),
        ]);
    }

    private function pdfPath(ValuationRequest $v): string
    {
        return 'valoraciones/'.$v->reference.'/informe-1.pdf';
    }

    private function snapshot(): string
    {
        return (string) json_encode([
            DB::table('clarto_valuation_requests')->orderBy('id')->get()->toArray(), DB::table('clarto_valuation_runs')->orderBy('id')->get()->toArray(),
            DB::table('clarto_valuation_report_links')->count(), DB::table('clarto_whatsapp_nurture_plans')->orderBy('id')->get()->toArray(),
            DB::table('clarto_whatsapp_nurture_slots')->orderBy('id')->get()->toArray(), DB::table('clarto_communications')->orderBy('id')->get()->toArray(),
            DB::table('clarto_ghl_whatsapp_orders')->count(), Storage::disk(ValuationEngine::DISK)->allFiles(),
        ]);
    }

    /** @return ?array<string, int> the counts of the last retention log line */
    private function lastLog(): ?array
    {
        $line = collect($this->logged)->last(fn (array $l): bool => str_starts_with($l['message'], 'Valoraciones: conservación'));

        return $line['context'] ?? null;
    }

    private function check(string $label, bool $ok): void
    {
        $ok ? $this->pass++ : $this->fail++;
        $this->line(($ok ? 'PASS' : 'FAIL').' · '.$label);
    }
}
