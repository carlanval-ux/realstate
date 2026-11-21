<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Acquisition\GhlHandoff;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Liberu\RealEstate\Instructions\Models\Instruction;
use Liberu\RealEstate\Parties\Models\Party;
use Liberu\RealEstate\Properties\Models\Property;

/**
 * GHL "won" webhook → Liberu file, with SIMULATED GoHighLevel API answers (Http::fake), through the real HTTP
 * route. Runs in a rolled-back transaction: nothing is kept.
 *
 *   php artisan clarto:ghl-prueba
 */
final class TestGhlHandoff extends Command
{
    protected $signature = 'clarto:ghl-prueba';

    protected $description = 'Test the GHL won-opportunity handoff with simulated GHL answers (nothing is kept)';

    private int $pass = 0;

    private int $fail = 0;

    public function handle(): int
    {
        config(['ghl.token' => 'pit-prueba', 'ghl.location_id' => 'LOC-CLARTO', 'ghl.pipeline_id' => 'PIPE-CAPTACION', 'ghl.webhook_secret' => 'secreto-webhook',
            'ghl.api_url' => 'https://services.leadconnectorhq.com',
            'ghl.fields' => ['solicitud_id' => 'f-sol', 'operacion' => 'f-op', 'tipo_inmueble' => 'f-tipo', 'municipio' => 'f-mun', 'zona' => 'f-zona',
                'codigo_postal' => 'f-cp', 'direccion' => 'f-dir', 'superficie_m2' => 'f-m2', 'dormitorios' => 'f-dorm', 'banos' => 'f-banos', 'plazo_venta' => 'f-plazo']]);

        DB::beginTransaction();
        try {
            $this->scenarios();
            $this->captacion();
        } finally {
            DB::rollBack();
        }

        $this->line(sprintf('RESULT: %d passed, %d failed (GoHighLevel simulated with Http::fake; database rolled back)', $this->pass, $this->fail));

        return $this->fail === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function scenarios(): void
    {
        $opp = fn (string $id, string $status, string $contact, string $municipio, int $m2, string $loc = 'LOC-CLARTO', string $pipe = 'PIPE-CAPTACION') => [
            'id' => $id, 'name' => "Valoración · {$municipio}", 'status' => $status, 'contactId' => $contact, 'locationId' => $loc, 'pipelineId' => $pipe,
            'customFields' => [
                ['id' => 'f-sol', 'fieldValue' => 'req-'.$id], ['id' => 'f-op', 'fieldValue' => 'venta'], ['id' => 'f-tipo', 'fieldValue' => 'piso'],
                ['id' => 'f-mun', 'fieldValue' => $municipio], ['id' => 'f-cp', 'fieldValue' => '46520'], ['id' => 'f-dir', 'fieldValue' => 'Calle Ficticia 12, 3º'],
                ['id' => 'f-m2', 'fieldValue' => (string) $m2], ['id' => 'f-dorm', 'fieldValue' => '3'], ['id' => 'f-banos', 'fieldValue' => '2'], ['id' => 'f-plazo', 'fieldValue' => '3_meses'],
            ],
        ];
        $opportunities = [
            'OPP-ABIERTA-1' => $opp('OPP-ABIERTA-1', 'open', 'CON-A', 'Sagunto', 90),
            'OPP-PERDIDA-1' => $opp('OPP-PERDIDA-1', 'lost', 'CON-A', 'Sagunto', 90),
            'OPP-GANADA-1' => $opp('OPP-GANADA-1', 'won', 'CON-A', 'Sagunto', 96),
            'OPP-GANADA-2' => $opp('OPP-GANADA-2', 'won', 'CON-A', 'Valencia', 68),
            'OPP-OTRA-CUENTA' => $opp('OPP-OTRA-CUENTA', 'won', 'CON-B', 'Valencia', 70, loc: 'LOC-TARMIO'),
            'OPP-OTRO-PIPE' => $opp('OPP-OTRO-PIPE', 'won', 'CON-B', 'Valencia', 70, pipe: 'PIPE-VENTAS'),
        ];
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($opportunities) {
            if (! $request->hasHeader('Authorization', 'Bearer pit-prueba') || ! $request->hasHeader('Version')) {
                return Http::response(['message' => 'unauthorized'], 401);
            }
            if (preg_match('#/opportunities/([A-Z0-9-]+)$#', $request->url(), $m)) {
                return isset($opportunities[$m[1]]) ? Http::response(['opportunity' => $opportunities[$m[1]]]) : Http::response(['message' => 'not found'], 404);
            }
            if (preg_match('#/contacts/([A-Z0-9-]+)$#', $request->url(), $m)) {
                return Http::response(['contact' => ['id' => $m[1], 'name' => 'Propietaria Ficticia', 'email' => 'propietaria.ficticia@example.com', 'phone' => '+34600000001']]);
            }

            return Http::response([], 404);
        });

        $kernel = app(Kernel::class);
        $post = fn (array $body, string $secret = 'secreto-webhook') => $kernel->handle(Request::create('/api/v1/ghl/oportunidad-ganada', 'POST', [], [], [], [
            'HTTP_X_CLARTO_WEBHOOK_SECRET' => $secret, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], json_encode($body)));
        $counts = fn (): array => [Party::query()->count(), Property::query()->count(), Instruction::query()->count()];
        $before = $counts();

        $this->check('webhook sin secreto correcto: 401', $post(['opportunity_id' => 'OPP-GANADA-1'], 'otro')->getStatusCode() === 401 && $counts() === $before);

        $r = json_decode((string) $post(['opportunity_id' => 'OPP-ABIERTA-1'])->getContent(), true);
        $this->check('oportunidad abierta: no crea expediente', ($r['estado'] ?? '') === 'ignorado' && $counts() === $before);
        $r = json_decode((string) $post(['opportunity_id' => 'OPP-PERDIDA-1'])->getContent(), true);
        $this->check('oportunidad perdida: no crea expediente', ($r['estado'] ?? '') === 'ignorado' && $counts() === $before);
        $r = json_decode((string) $post(['opportunity_id' => 'OPP-OTRA-CUENTA'])->getContent(), true);
        $this->check('otra subcuenta de GHL: rechazada', str_contains((string) ($r['motivo'] ?? ''), 'subcuenta') && $counts() === $before);
        $r = json_decode((string) $post(['opportunity_id' => 'OPP-OTRO-PIPE'])->getContent(), true);
        $this->check('otro pipeline: rechazado', str_contains((string) ($r['motivo'] ?? ''), 'pipeline') && $counts() === $before);

        $r = json_decode((string) $post(['opportunity_id' => 'OPP-GANADA-1'])->getContent(), true);
        $h = GhlHandoff::query()->where('opportunity_id', 'OPP-GANADA-1')->first();
        $property = Property::query()->find($h?->property_id);
        $party = Party::query()->find($h?->party_id);
        $this->check('ganada: crea propietario, inmueble y encargo', ($r['estado'] ?? '') === 'procesado' && $counts() === [$before[0] + 1, $before[1] + 1, $before[2] + 1]);
        $this->check('propietario con id de contacto GHL', ($party?->metadata['ghl_contact_id'] ?? null) === 'CON-A' && (($party?->type instanceof \BackedEnum ? $party->type->value : $party?->type) === 'vendor'));
        $this->check('inmueble en borrador con los datos (96 m², 3 dorm., CP)', $property?->status?->value === 'draft' && (float) $property->area_sqft === 96.0 && (int) $property->bedrooms === 3 && $property->postal_code === '46520');
        $this->check('ids de GHL conservados en el inmueble y el registro', str_contains((string) $property?->internal_notes, 'OPP-GANADA-1') && $h?->contact_id === 'CON-A');

        $before2 = $counts();
        $r = json_decode((string) $post(['opportunity_id' => 'OPP-GANADA-1'])->getContent(), true);
        $r2 = json_decode((string) $post(['opportunity' => ['id' => 'OPP-GANADA-1']])->getContent(), true);
        $this->check('webhook duplicado (dos veces): no crea otro expediente', ($r['estado'] ?? '') === 'duplicado' && ($r2['estado'] ?? '') === 'duplicado' && $counts() === $before2);

        $r = json_decode((string) $post(['opportunity_id' => 'OPP-GANADA-2'])->getContent(), true);
        $h2 = GhlHandoff::query()->where('opportunity_id', 'OPP-GANADA-2')->first();
        $this->check('mismo propietario, segundo inmueble: otro expediente con el mismo propietario',
            ($r['estado'] ?? '') === 'procesado' && $h2?->party_id === $h?->party_id && $h2?->property_id !== $h?->property_id && $counts() === [$before2[0], $before2[1] + 1, $before2[2] + 1]);

        $this->check('la publicación sigue siendo del equipo (inmuebles en borrador)', Property::query()->whereIn('id', [$h?->property_id, $h2?->property_id])->get()->every(fn ($p) => $p->status->value === 'draft'));
    }

    /** @var array<string, array<string, mixed>> simulated GHL state for the acquisition circuit */
    private array $contacts = [];

    /** @var array<string, array<string, mixed>> */
    private array $opps = [];

    /** @var list<array{contact: string, body: string}> */
    private array $notes = [];

    /** @var list<string> «+tag» / «-tag» per contact, in order */
    private array $tagEvents = [];

    /** @var list<array<string, mixed>> */
    private array $upserts = [];

    private bool $noDuplicateOnce = false;

    private bool $wahaLinked = true;

    /** @var list<array<string, mixed>> */
    private array $wahaSends = [];

    /**
     * The GHL acquisition circuit (validations 1–7 of the brief, the Liberu side) with a stateful simulated GHL:
     * contact + one opportunity per property, retries without duplicates, two properties, replies and «BAJA» by
     * WhatsApp, WhatsApp asked by GHL, channel failure and recovery, won → owner + draft property + TARMIO.
     */
    private function captacion(): void
    {
        config(['cache.default' => 'array', 'queue.default' => 'sync', 'whatsapp.url' => 'http://waha-prueba.test', 'ghl.fields' => [], 'ghl.contact_fields' => [],
            'valoraciones.ghl.fields' => [], 'valoraciones.ghl.stage_id' => 'STAGE-NUEVO', 'ghl.stage_entregada' => 'STAGE-ENTREGADA', 'ghl.alta_desde' => null, 'ghl.asesores' => 'USR-CARLOS',
            'valoraciones.citas' => ['cita_valencia' => 'https://citas.prueba.test/valencia', 'cita_sagunto' => 'https://citas.prueba.test/sagunto', 'cita_bellreguard' => null]]);
        \Illuminate\Support\Facades\Cache::flush();
        \Illuminate\Support\Facades\Queue::fake([\Illuminate\Queue\CallQueuedClosure::class, \App\Tarmio\SyncTarmioTask::class]);
        \Illuminate\Support\Facades\Mail::fake();
        \Illuminate\Support\Facades\Storage::fake(\App\Valuations\ValuationEngine::DISK);
        Http::swap(new \Illuminate\Http\Client\Factory(app('events')));
        Http::preventStrayRequests();
        Http::fake(fn ($request) => str_contains($request->url(), 'waha-prueba.test') ? $this->waha($request) : $this->ghlSim($request));
        $team = \Liberu\Foundation\Organizations\Models\Team::query()->oldest()->firstOrFail();
        \App\Models\WhatsAppLine::forTeam($team->getKey())->forceFill(['status' => 'WORKING'])->save();
        \App\Modules\ClartoModules::enable('whatsapp');   // rolled back with the rest
        $sync = new \App\Valuations\GhlValuationSync;
        $deliver = function (\App\Valuations\ValuationRequest $r) use ($sync): void {
            (new \App\Valuations\ValuationDelivery)->deliver($r->refresh());
            $sync->reportDelivery($r);
        };
        $field = fn (array $entity, string $key) => collect($entity['customFields'] ?? [])->firstWhere('id', 'cf-'.$key)['value']
            ?? collect($entity['customFields'] ?? [])->firstWhere('id', 'of-'.$key)['fieldValue'] ?? null;
        $mails = fn (\App\Valuations\ValuationRequest $r): int => \Illuminate\Support\Facades\Mail::sent(\App\Valuations\ValuationReportMail::class, fn ($m): bool => $m->valuation->is($r))->count();
        $wa = fn (string $needle): int => count(array_filter($this->wahaSends, fn (array $b): bool => str_contains((string) ($b['text'] ?? ''), $needle)));
        // Delivery WhatsApp of a request: the owner's text has no reference, only the short report link (or the reference, in «revisión»).
        $waFor = fn (\App\Valuations\ValuationRequest $r): int => count(array_filter($this->wahaSends, fn (array $b): bool => str_contains((string) ($b['text'] ?? ''), (string) $r->reference)
            || (preg_match('#/r/('.\App\Valuations\ReportLinks::PATTERN.')#', (string) ($b['text'] ?? ''), $m) === 1 && \App\Valuations\ReportLinks::resolve($m[1])['link']?->valuation_request_id === $r->getKey())));
        $closures = function (): void {   // run the queued closures (afterCommit never fires inside the rolled-back transaction)
            foreach (\Illuminate\Support\Facades\Queue::pushed(\Illuminate\Queue\CallQueuedClosure::class) as $job) {
                app()->call([$job, 'handle']);
            }
            \Illuminate\Support\Facades\Queue::fake([\Illuminate\Queue\CallQueuedClosure::class, \App\Tarmio\SyncTarmioTask::class]);
        };
        $sagunto = ['direccion_confirmada' => 'CL CASTELLON 2 Pl:01 Pt:02', 'municipio' => 'SAGUNTO / SAGUNT', 'codigo_postal' => '46520', 'tipo' => 'piso', 'superficie_construida_m2' => 90, 'en_venta' => 'propietario', 'ocupacion' => 'vacia'];
        $rcSagunto = ['rc' => '0002ZR0001001200CXYZ', 'municipio' => 'SAGUNTO / SAGUNT', 'codigo_postal' => '46520', 'direccion' => 'CL CASTELLON 2 Pl:01 Pt:02'];

        // 1. Request → GHL contact + opportunity → e-mail and WhatsApp → «Valoración entregada».
        $r1 = $this->valuationRequest($team, $sagunto, ['plazo_venta' => 'menos_3m', 'relacion' => 'copropietario_heredero', 'comentario' => 'Somos tres hermanos'], $rcSagunto, ['email' => true, 'whatsapp' => true]);
        $sync->sync($r1);
        $deliver($r1);
        $r1->refresh();
        $contact = $this->contacts[$r1->ghl_contact_id] ?? [];
        $opp = $this->opps[$r1->ghl_opportunity_id] ?? [];
        $this->check('1 · un contacto con las 4 etiquetas por la ruta de etiquetas (el upsert no lleva etiquetas)',
            count($this->contacts) === 1 && array_diff(\App\Valuations\GhlValuationSync::TAGS, $contact['tags']) === [] && ! isset($this->upserts[0]['tags']));
        $this->check('1 · una oportunidad con inmueble (rc), oficina Puerto de Sagunto, plazo, relación, situación, intervalo y origen web',
            count($this->opps) === 1 && $field($opp, 'inmueble_clave') === 'rc:0002ZR0001001200CXYZ' && $field($opp, 'oficina') === 'Puerto de Sagunto'
            && $field($opp, 'plazo_venta') === 'Menos de 3 meses' && $field($opp, 'relacion_inmueble') === 'Copropietario o heredero'
            && $field($opp, 'situacion_comercial') === 'Anunciado por su cuenta' && $field($opp, 'ocupacion') === 'Vacío' && $field($opp, 'precio_minimo') === '153000'
            && $field($opp, 'origen') === 'web_valoracion' && str_contains((string) $field($opp, 'alertas'), 'herencia') && $field($opp, 'comentario_propietario') === 'Somos tres hermanos');
        $this->check('1 · ficha del contacto: consentimientos Sí con fecha y versión, fuente original, 1 valoración, 1 inmueble, Caliente, enlace de cita de Sagunto',
            $field($contact, 'consentimiento_comercial_email') === 'Sí' && $field($contact, 'consentimiento_comercial_whatsapp') === 'Sí' && filled($field($contact, 'consentimiento_comercial_email_fecha'))
            && $field($contact, 'consentimiento_comercial_version') === '2026-11-24' && $field($contact, 'fuente_original') === 'Web · valoración'
            && $field($contact, 'num_valoraciones') === '1' && $field($contact, 'num_inmuebles') === '1' && $field($contact, 'temperatura') === 'Caliente'
            && $field($contact, 'val_enlace_cita') === 'https://citas.prueba.test/sagunto?utm_content=correo1' && $field($contact, 'canal_preferido') === 'WhatsApp');
        $this->check('D6 · la oportunidad nace asignada al asesor de captación (GHL_ASESORES; WF-01 no puede asignarla)', ($this->opps[$r1->ghl_opportunity_id]['assignedTo'] ?? null) === 'USR-CARLOS');
        $this->check('1 · correo y WhatsApp enviados una vez; la oportunidad pasa a «Valoración entregada» con el estado de cada canal y una nota',
            $mails($r1) === 1 && $waFor($r1) === 1 && ($this->opps[$r1->ghl_opportunity_id]['pipelineStageId'] ?? '') === 'STAGE-ENTREGADA'
            && $field($this->opps[$r1->ghl_opportunity_id], 'entrega_whatsapp_estado') === 'enviado' && count($this->notes) === 1);

        // 2. The same request again (API retry, «Reintentar GHL», scheduled retry): nothing new.
        $sync->sync($r1->refresh());
        $deliver($r1);
        $r1->refresh()->forceFill(['ghl_opportunity_id' => null])->save();   // id lost after GHL created it
        $sync->sync($r1);
        $this->check('2 · reenvío de la misma solicitud (y id perdido): 0 contactos, oportunidades, correos, WhatsApp ni notas nuevos',
            count($this->contacts) === 1 && count($this->opps) === 1 && $mails($r1) === 1 && $waFor($r1) === 1 && count($this->notes) === 1
            && $r1->refresh()->ghl_opportunity_id === array_key_first($this->opps));

        // 3. Same owner, a second property (València, no cadastral ref.): same contact, a second opportunity.
        $r2 = $this->valuationRequest($team, ['direccion_confirmada' => 'Calle de Cuba 22, 2º 3ª', 'municipio' => 'VALENCIA', 'codigo_postal' => '46006', 'tipo' => 'piso', 'superficie_construida_m2' => 58],
            ['plazo_venta' => '6_12m'], ['municipio' => 'VALENCIA', 'codigo_postal' => '46006'], ['email' => false, 'whatsapp' => false]);
        $sync->sync($r2);
        $contact = $this->contacts[$r2->refresh()->ghl_contact_id];
        $this->check('3 · dos inmuebles: 1 contacto, 2 oportunidades (Sagunto y Valencia centro), val_* del último, 2 inmuebles',
            count($this->contacts) === 1 && count($this->opps) === 2 && $r2->ghl_opportunity_id !== $r1->ghl_opportunity_id
            && $field($this->opps[$r2->ghl_opportunity_id], 'oficina') === 'Valencia centro' && $field($contact, 'val_oficina') === 'Valencia centro'
            && $field($contact, 'num_inmuebles') === '2' && str_starts_with((string) $field($this->opps[$r2->ghl_opportunity_id], 'inmueble_clave'), 'dir:'));
        $this->check('3 · la segunda solicitud sin casillas no quita el consentimiento («Sí» se mantiene) ni baja la temperatura (sigue Caliente)',
            $field($contact, 'consentimiento_comercial_email') === 'Sí' && $field($contact, 'temperatura') === 'Caliente');
        $r3 = $this->valuationRequest($team, $sagunto, ['plazo_venta' => '3_6m'], $rcSagunto, ['email' => true]);
        $sync->sync($r3);
        $opp1 = $this->opps[$r1->ghl_opportunity_id];
        $this->check('3b · la misma vivienda con otra solicitud: la misma oportunidad, historial con las dos y nota «Nueva solicitud»',
            count($this->opps) === 2 && $r3->refresh()->ghl_opportunity_id === $r1->ghl_opportunity_id
            && str_contains((string) $field($opp1, 'solicitudes_ids'), $r1->request_id) && str_contains((string) $field($opp1, 'solicitudes_ids'), $r3->request_id)
            && collect($this->notes)->contains(fn (array $n): bool => str_contains($n['body'], 'Nueva solicitud '.$r3->reference)) && $field($this->contacts[$r3->ghl_contact_id], 'num_valoraciones') === '3');
        $rRev = $this->valuationRequest($team, $sagunto, ['plazo_venta' => '3_6m'], $rcSagunto, ['email' => true], 'revision');
        $sync->sync($rRev);
        $opp1 = $this->opps[$r1->ghl_opportunity_id];
        $c1 = $this->contacts[$rRev->refresh()->ghl_contact_id];
        $this->check('3b · una solicitud posterior en «revision» del mismo inmueble vacía precios e informe (oportunidad y copia val_*): nada mezclado',
            $field($opp1, 'estado_valoracion') === 'revision' && $field($opp1, 'precio_minimo') === '' && $field($opp1, 'informe_url') === ''
            && $field($c1, 'val_referencia') === $rRev->reference && $field($c1, 'val_precio_minimo_txt') === '' && $field($c1, 'val_informe_url') === '');
        $this->opps[$r1->ghl_opportunity_id]['status'] = 'won';
        $r4 =$this->valuationRequest($team, $sagunto, ['plazo_venta' => 'menos_3m'], $rcSagunto, ['email' => true]);
        $this->noDuplicateOnce = true;   // GHL answers 400 OPPORTUNITY_NO_DUPLICATE with meta.existingId
        $sync->sync($r4);
        $this->check('3c · vivienda con la oportunidad ya ganada: GHL rechaza el duplicado y Liberu reutiliza meta.existingId sin error',
            $r4->refresh()->ghl_status === 'sincronizada' && $r4->ghl_opportunity_id === 'OPP-EXISTENTE');

        // 4. Replies by WhatsApp: note + «respondio-whatsapp» (removed and added), grouped in 10 minutes.
        $phone = '+34600111222';
        $in = fn (string $body) => \App\Models\Communication::query()->create(['team_id' => $team->getKey(), 'channel' => 'whatsapp', 'direction' => \App\Models\Communication::IN,
            'phone' => $phone, 'body' => $body, 'status' => 'recibido', 'idempotency_key' => 'in:prueba:'.\Illuminate\Support\Str::uuid(), 'provider_id' => (string) \Illuminate\Support\Str::uuid(), 'sent_at' => now()]);
        $tagsBefore = count($this->tagEvents);
        $in('Sí, quiero venderlo este año');
        $in('¿Pueden venir el jueves?');
        $pushed = \Illuminate\Support\Facades\Queue::pushed(\Illuminate\Queue\CallQueuedClosure::class)->count();
        $closures();
        $events = array_slice($this->tagEvents, $tagsBefore);
        $reply = collect($this->notes)->last();
        $this->check('4 · respuesta por WhatsApp: una nota con los dos mensajes y la etiqueta respondio-whatsapp quitada y puesta (dispara WF-04)',
            $pushed === 2 && str_contains((string) $reply['body'], 'quiero venderlo') && str_contains((string) $reply['body'], 'jueves')
            && $events === ['-respondio-whatsapp', '+respondio-whatsapp']);

        // 5. «BAJA» by WhatsApp; a later WhatsApp asked by GHL is refused.
        $in('BAJA');
        $closures();
        $contact = $this->contacts[$r1->ghl_contact_id];
        $this->check('5b · «BAJA»: DND WhatsApp, etiqueta baja-whatsapp, consentimiento WhatsApp Revocado con fecha, bloqueo local y una sola confirmación',
            ($contact['dndSettings']['WhatsApp']['status'] ?? '') === 'active' && in_array('baja-whatsapp', $contact['tags'], true)
            && $field($contact, 'consentimiento_comercial_whatsapp') === 'Revocado' && $field($contact, 'baja_canal') === 'WhatsApp'
            && \App\Acquisition\GhlWhatsApp::optedOut($team->getKey(), $phone) && $wa('No le enviaremos más mensajes') === 1);
        $post = fn (array $body, string $secret = 'secreto-webhook') => app(Kernel::class)->handle(Request::create('/api/v1/ghl/whatsapp', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode($body + ['customData' => []])));
        $order = fn (string $contactId, string $tipo, string $clave, array $extra = []) => $post(['contact_id' => $contactId, 'customData' => ['clarto_secreto' => 'secreto-webhook', 'tipo' => $tipo, 'texto' => "Mensaje {$tipo} {$clave}", 'clave' => $clave, ...$extra]]);
        $r = json_decode((string) $order($r1->ghl_contact_id, 'a3', 'k-baja')->getContent(), true);
        $this->check('5b · después de la baja, un WhatsApp pedido por GHL: «rechazado: baja» y no se envía', ($r['estado'] ?? '') === 'rechazado' && ($r['motivo'] ?? '') === 'baja' && $wa('k-baja') === 0);

        // L-4: WhatsApp asked by GHL for another (test) contact.
        $r5 = $this->valuationRequest($team, $sagunto + ['direccion_confirmada' => 'CL CASTELLON 4'], ['plazo_venta' => 'menos_3m', 'email' => 'prueba.dos@example.com', 'telefono' => '+34600333444'], [], ['email' => true, 'whatsapp' => false]);
        $sync->sync($r5);
        $c5 = (string) $r5->refresh()->ghl_contact_id;
        $this->check('L-4 · secreto incorrecto: 401', $post(['contact_id' => $c5, 'customData' => ['clarto_secreto' => 'otro', 'tipo' => 'a3', 'texto' => 'x', 'clave' => 'k0']])->getStatusCode() === 401);
        $this->contacts['CT-OTRA'] = ['id' => 'CT-OTRA', 'locationId' => 'LOC-TARMIO', 'email' => 'otra@example.com', 'phone' => '+34600999999', 'tags' => [], 'customFields' => []];
        $this->check('L-4 · contacto de otra subcuenta: 422 y nada enviado', $order('CT-OTRA', 'a3', 'k-otra')->getStatusCode() === 422 && $wa('k-otra') === 0);
        $r = json_decode((string) $order($c5, 'w2', 'k-w2')->getContent(), true);
        $this->check('L-4 · W2 sin consentimiento comercial por WhatsApp: rechazado', ($r['estado'] ?? '') === 'rechazado' && $wa('k-w2') === 0);
        $r = json_decode((string) $order($c5, 'a3', 'k-a3')->getContent(), true);
        $again = json_decode((string) $order($c5, 'a3', 'k-a3')->getContent(), true);
        $this->check('L-4 · A3 enviado una vez (la misma clave otra vez: misma respuesta, ningún envío más) y nota en GHL',
            ($r['estado'] ?? '') === 'enviado' && ($again['estado'] ?? '') === 'enviado' && $wa('k-a3') === 1 && collect($this->notes)->contains(fn ($n) => str_contains($n['body'], 'WhatsApp enviado (A3)')));

        // 6. Channel failure and recovery (WhatsApp line not linked).
        $this->wahaLinked = false;
        \App\Models\WhatsAppLine::forTeam($team->getKey())->forceFill(['status' => 'STOPPED'])->save();
        $r = json_decode((string) $order($c5, 'a4', 'k-a4', ['caduca' => now()->addHour()->toIso8601String()])->getContent(), true);
        $r6 = $this->valuationRequest($team, ['direccion_confirmada' => 'Av. República Argentina 30', 'municipio' => 'GANDIA', 'codigo_postal' => '46702', 'tipo' => 'piso', 'superficie_construida_m2' => 99],
            ['plazo_venta' => '3_6m', 'email' => 'prueba.tres@example.com', 'telefono' => '+34600555666'], [], ['email' => false]);
        $sync->sync($r6);
        $deliver($r6);
        $o6 = $this->opps[$r6->refresh()->ghl_opportunity_id];
        $this->check('6a · línea sin vincular: correo enviado, WhatsApp «linea_no_vinculada», resultado guardado y «Valoración entregada» igualmente (basta un canal); oficina Bellreguard',
            $mails($r6) === 1 && $field($o6, 'entrega_whatsapp_estado') === 'linea_no_vinculada' && $o6['pipelineStageId'] === 'STAGE-ENTREGADA'
            && $r6->currentRun !== null && $field($o6, 'oficina') === 'Bellreguard' && ! in_array('entrega-error', $this->contacts[$r6->ghl_contact_id]['tags'], true));
        $this->check('L-4 · A4 con la línea sin vincular: queda «en_cola»', ($r['estado'] ?? '') === 'en_cola');
        \Illuminate\Support\Carbon::setTestNow(now()->addHours(3));
        $deliver($r6);
        \App\Acquisition\GhlWhatsApp::retryQueued();
        $this->check('6a · a las 2 h sigue fallando: etiqueta entrega-error (WF-10); el A4 caducado: whatsapp-error y nota',
            in_array('entrega-error', $this->contacts[$r6->ghl_contact_id]['tags'], true) && in_array('whatsapp-error', $this->contacts[$c5]['tags'], true)
            && \App\Acquisition\GhlWhatsApp::query()->where('clave', 'k-a4')->value('estado') === 'error');
        $this->wahaLinked = true;
        $deliver($r6);
        $this->check('6b · línea vinculada: el reintento envía UN WhatsApp y quita entrega-error; ningún correo ni oportunidad de más',
            $waFor($r6) === 1 && $mails($r6) === 1 && ! in_array('entrega-error', $this->contacts[$r6->ghl_contact_id]['tags'], true)
            && $field($this->opps[$r6->ghl_opportunity_id], 'entrega_whatsapp_estado') === 'enviado');
        \Illuminate\Support\Carbon::setTestNow();

        // 7. Won → owner (consents copied) + draft property + TARMIO, without duplicates (webhook twice, daily reconciliation).
        $before = [Party::query()->count(), Property::query()->count(), \App\Tarmio\TarmioTask::query()->where('kind', 'alta_propietario')->count()];
        $won = fn (string $id) => json_decode((string) app(Kernel::class)->handle(Request::create('/api/v1/ghl/oportunidad-ganada', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode(['opportunity_id' => $id, 'customData' => ['clarto_secreto' => 'secreto-webhook']])))->getContent(), true);
        $this->opps[$r5->ghl_opportunity_id]['status'] = 'won';
        $first = $won((string) $r5->ghl_opportunity_id);
        $second = $won((string) $r5->ghl_opportunity_id);
        $h = GhlHandoff::query()->where('opportunity_id', $r5->ghl_opportunity_id)->first();
        $profile = \App\Owners\OwnerProfile::query()->where('party_id', $h?->party_id)->first();
        $this->check('L-20 · Ganada sin plan de WhatsApp: igualmente «cliente-clarto» en GHL (para los correos, WF-13), una sola vez', in_array('cliente-clarto', $this->contacts[$r5->ghl_contact_id]['tags'], true)
            && collect($this->notes)->filter(fn (array $n): bool => $n['contact'] === $r5->ghl_contact_id && str_contains($n['body'], 'Cliente de CLARTO'))->count() === 1);
        $this->check('7 · Ganada (secreto en los datos del webhook, L-6): propietario + inmueble en borrador + tarea TARMIO; repetido: «duplicado» sin registros nuevos',
            ($first['estado'] ?? '') === 'procesado' && ($second['estado'] ?? '') === 'duplicado'
            && [Party::query()->count(), Property::query()->count(), \App\Tarmio\TarmioTask::query()->where('kind', 'alta_propietario')->count()] === [$before[0] + 1, $before[1] + 1, $before[2] + 1]);
        $this->check('7 · consentimientos copiados al perfil del propietario (L-10): tratamiento y comercial por correo con fecha y versión; canal WhatsApp',
            $profile?->privacy_consent_at !== null && $profile->privacy_consent_version === '2026-11-24' && $profile->marketing_consent_at !== null
            && $profile->marketing_consent_channel === 'email' && $profile->preferred_channel === 'whatsapp' && str_contains((string) $profile->consent_source, (string) $r5->reference));
        $this->opps[$r6->ghl_opportunity_id]['status'] = 'won';
        $this->opps[$r6->ghl_opportunity_id]['lastStatusChangeAt'] = now()->subDay()->toIso8601String();
        \Illuminate\Support\Facades\Artisan::call('clarto:ghl-ganadas');
        $afterOne = Property::query()->count();
        \Illuminate\Support\Facades\Artisan::call('clarto:ghl-ganadas');
        $this->check('7 · L-8: una ganada cuyo webhook no llegó se procesa en la conciliación diaria; repetida, no duplica',
            GhlHandoff::query()->where('opportunity_id', $r6->ghl_opportunity_id)->value('status') === 'procesado' && Property::query()->count() === $afterOne);

        // L-7 and the requests before the go-live.
        config(['ghl.alta_desde' => now()->addDay()->format('Y-m-d')]);
        $this->check('L-7 · solicitud anterior a GHL_ALTA_DESDE: origen web_valoracion_previa (WF-02 no la nutre)', \App\Valuations\GhlValuationSync::origin($r6) === 'web_valoracion_previa');
        config(['ghl.alta_desde' => null]);

        $this->nutricion($team, $closures, $in);
    }

    /**
     * WhatsApp nurture planned and sent by Liberu (B.3 v2 §5, L-18…L-21): GHL's clock (inicio / correo / parada), working
     * days and holidays, the 17:30–18:30 window, one message per day, idempotency, and every stop.
     */
    private function nutricion(\Liberu\Foundation\Organizations\Models\Team $team, \Closure $closures, \Closure $in): void
    {
        $P = \App\Acquisition\NurturePlan::class;
        $tz = 'Europe/Madrid';
        $at = fn (string $local) => \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse($local, $tz)->timezone(config('app.timezone')));
        $hook = fn (string $contactId, array $data, string $secret = 'secreto-webhook') => json_decode((string) app(Kernel::class)->handle(Request::create('/api/v1/ghl/nutricion', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode(['contact_id' => $contactId, 'customData' => ['clarto_secreto' => $secret, ...$data]])))->getContent(), true);
        $n = 10;
        $lead = function (array $commercial = ['email' => true, 'whatsapp' => true]) use ($team, &$n): array {
            $n++;
            $phone = '+346001000'.$n;
            $r = $this->valuationRequest($team, ['direccion_confirmada' => 'CL PRUEBA '.$n, 'municipio' => 'SAGUNTO / SAGUNT', 'codigo_postal' => '46520', 'tipo' => 'piso'],
                ['plazo_venta' => 'menos_3m', 'email' => "prueba.nutricion{$n}@example.com", 'telefono' => $phone], [], $commercial);
            (new \App\Valuations\GhlValuationSync)->sync($r);
            $id = (string) $r->refresh()->ghl_contact_id;
            $this->contacts[$id]['tags'][] = 'nutricion-activa';   // WF-02 puts it

            return [$id, $phone, $r];
        };
        $run = function (int $times = 6) use ($P): void {
            for ($i = 0; $i < $times; $i++) {
                $P::runDue();
            }
        };
        $slot = fn (string $contactId, string $code) => \App\Acquisition\NurtureSlot::query()->whereHas('plan', fn ($q) => $q->where('contact_id', $contactId))->where('codigo', $code)->latest('id')->first();
        $sent = fn (string $phone): int => count(array_filter($this->wahaSends, fn (array $b): bool => ($b['chatId'] ?? '') === ltrim($phone, '+').'@c.us' && str_contains((string) ($b['text'] ?? ''), 'responda BAJA')));
        $this->wahaLinked = true;
        \App\Models\WhatsAppLine::forTeam($team->getKey())->forceFill(['status' => 'WORKING'])->save();

        // Plan + first slot: Friday 4/12/2026, 8/12 is a holiday → +2 working days = Wednesday 9/12, 17:30–18:30.
        $at('2026-12-04 10:31');
        [$a, $phoneA] = $lead();
        $r = $hook($a, ['evento' => 'inicio', 'rama' => 'activa', 'asesor_nombre' => 'Lucía']);
        $again = $hook($a, ['evento' => 'inicio', 'rama' => 'activa']);
        $c2 = $hook($a, ['evento' => 'correo', 'codigo' => 'c2', 'estado' => 'enviado']);
        $hook($a, ['evento' => 'correo', 'codigo' => 'c2', 'estado' => 'enviado']);
        $this->check('L-18 · inicio crea el plan (un segundo inicio se ignora); correo c2 un viernes → WA1 el miércoles 9 (el 8 es festivo) entre 17:30 y 18:30; repetido: un solo slot',
            ($r['estado'] ?? '') === 'programado' && ($again['estado'] ?? '') === 'ignorado' && preg_match('/^2026-12-09 (17:[3-5][0-9]|18:[0-2][0-9])$/', (string) ($c2['fecha'] ?? '')) === 1
            && \App\Acquisition\NurtureSlot::query()->whereHas('plan', fn ($q) => $q->where('contact_id', $a))->count() === 1);
        $this->check('L-18 · secreto incorrecto: 401', ($hook($a, ['evento' => 'parada'], 'otro')['message'] ?? '') === 'No autorizado.');
        $at('2026-12-09 12:00');
        $run();
        $before = $sent($phoneA);
        $at('2026-12-09 18:25');
        $run();
        $run();
        $wa1 = $slot($a, 'wa1');
        $text = collect($this->wahaSends)->last()['text'] ?? '';
        $this->check('L-19 · antes de la ventana no sale; en la ventana, WA1 una sola vez (reintentos sin duplicar) con el asesor, la zona y la línea de baja; visible en comunicaciones',
            $before === 0 && $sent($phoneA) === 1 && $wa1?->estado === 'enviado' && str_contains($text, 'Le escribimos desde CLARTO') && ! str_contains($text, 'Lucía') && str_contains($text, 'Sagunto')
            && \App\Models\Communication::query()->whereKey($wa1?->communication_id)->where('source', 'nutricion')->exists());

        // A text held back for legal review (NUTRICION_WA_REVISION_LEGAL; none since the owner approved them): planned, never sent.
        config(['valoraciones.nutricion_whatsapp.revision_legal' => ['wa2']]);
        $hook($a, ['evento' => 'correo', 'codigo' => 'c3', 'estado' => 'enviado']);   // 9/12 → WA2 on Friday 11/12
        $at('2026-12-11 18:25');
        $run();
        $this->check('Revisión legal · WA2 (fiscal) programado pero «omitido: pendiente de revisión legal», no se envía',
            $slot($a, 'wa2')?->estado === 'omitido' && $slot($a, 'wa2')?->motivo === 'Pendiente de revisión legal' && $sent($phoneA) === 1);
        config(['valoraciones.nutricion_whatsapp.revision_legal' => []]);

        // Line down during the window → waiting, then skipped at the end of the window with whatsapp-error in GHL.
        $at('2026-12-11 10:40');
        $hook($a, ['evento' => 'correo', 'codigo' => 'c4', 'estado' => 'enviado']);   // → WA3 Tuesday 15/12
        $this->wahaLinked = false;
        \App\Models\WhatsAppLine::forTeam($team->getKey())->forceFill(['status' => 'STOPPED'])->save();
        $at('2026-12-15 18:25');
        $run();
        $waiting = $slot($a, 'wa3')?->estado;
        $at('2026-12-15 18:35');
        $run();
        $this->check('L-19 · línea caída en la ventana: espera; al cerrarse la ventana, «omitido» y whatsapp-error en GHL; nada enviado',
            $waiting === 'programado' && $slot($a, 'wa3')?->estado === 'omitido' && in_array('whatsapp-error', $this->contacts[$a]['tags'], true) && $sent($phoneA) === 1);
        $this->wahaLinked = true;
        \App\Models\WhatsAppLine::forTeam($team->getKey())->forceFill(['status' => 'WORKING'])->save();

        // Stops. Each lead gets a plan and a WA1 slot on Monday 21/12 (c2 on Thursday 17/12).
        $at('2026-12-17 10:31');
        $plan = function (string $rama = 'activa') use ($lead, $hook): array {
            [$id, $phone, $r] = $lead();
            $hook($id, ['evento' => 'inicio', 'rama' => $rama]);
            $hook($id, ['evento' => 'correo', 'codigo' => 'c2', 'estado' => 'enviado']);

            return [$id, $phone, $r];
        };
        [$reply, $replyPhone] = $plan();
        [$baja, $bajaPhone] = $plan();
        [$client, $clientPhone] = $plan();
        [$ghlStop, $ghlStopPhone] = $plan();
        [$parada] = $plan();
        [$later, $laterPhone] = $plan();
        [$fria] = $plan('fria');
        $at('2026-12-18 12:00');
        \App\Communications\Communications::record($team->getKey(), 'whatsapp', \App\Models\Communication::IN, ['phone' => $replyPhone, 'body' => 'Esperaba algo más', 'status' => 'recibido']);
        $in2 = fn (string $phone, string $body) => \App\Models\Communication::query()->create(['team_id' => $team->getKey(), 'channel' => 'whatsapp', 'direction' => \App\Models\Communication::IN,
            'phone' => $phone, 'body' => $body, 'status' => 'recibido', 'idempotency_key' => 'in:prueba:'.\Illuminate\Support\Str::uuid(), 'provider_id' => (string) \Illuminate\Support\Str::uuid(), 'sent_at' => now()]);
        $in2($bajaPhone, 'Baja');
        $in2($laterPhone, 'Mejor más adelante, gracias');
        $closures();
        app(\Liberu\RealEstate\Parties\Application\CreateParty::class)->handle($team->getKey(), (int) ($team->user_id ?? 1), ['type' => 'vendor', 'name' => 'PRUEBA Cliente Ficticio', 'phone' => $clientPhone]);
        $this->contacts[$ghlStop]['tags'][] = 'cita-agendada';   // booked in GHL (WF-07): seen at send time
        $stopped = $hook($parada, ['evento' => 'parada']);
        $fc2 = $hook($fria, ['evento' => 'correo', 'codigo' => 'c3', 'estado' => 'enviado']);
        $at('2026-12-21 18:29');
        $run(12);
        $state = fn (string $id): ?string => $P::query()->where('contact_id', $id)->latest('id')->value('estado');
        $this->check('L-20 · respuesta por WhatsApp: plan cancelado al instante, su WA1 no sale', $state($reply) === 'cancelado' && $slot($reply, 'wa1')?->estado === 'cancelado' && $sent($replyPhone) === 0);
        $this->check('L-20 · «BAJA»: plan cancelado, DND WhatsApp en GHL y ningún mensaje de nutrición', $state($baja) === 'cancelado' && ($this->contacts[$baja]['dndSettings']['WhatsApp']['status'] ?? '') === 'active' && $sent($bajaPhone) === 0);
        $this->check('L-20 · el teléfono pasa a ser cliente en Liberu: plan cancelado al vincular y etiqueta cliente-clarto en GHL',
            $state($client) === 'cancelado' && in_array('cliente-clarto', $this->contacts[$client]['tags'], true) && $sent($clientPhone) === 0);
        $this->check('L-19 · cita reservada en GHL (etiqueta vista antes de enviar): cancelado, nada enviado', $state($ghlStop) === 'cancelado' && $sent($ghlStopPhone) === 0);
        $this->check('L-18 · «parada» de GHL (WF-13): plan cancelado', ($stopped['estado'] ?? '') === 'cancelado' && $state($parada) === 'cancelado');
        $this->check('L-21 · «más adelante» por WhatsApp: etiqueta retomar-mas-adelante en GHL y plan cancelado', in_array('retomar-mas-adelante', $this->contacts[$later]['tags'], true) && $state($later) === 'cancelado');
        $this->check('L-18 · rama Fría: c2 no programa WhatsApp, c3 programa WA-F1', $slot($fria, 'wa1') === null && str_starts_with((string) ($fc2['fecha'] ?? ''), '2026-12-22 1'));   // c3 sent on Friday 18
        $at('2026-12-22 18:29');
        $run();
        $this->check('L-18 · WA-F1 de la rama Fría enviado en su ventana (martes 22, 17:30–18:30)', $slot($fria, 'wa_f1')?->estado === 'enviado');
        [$noConsent, $ncPhone] = $lead(['email' => true, 'whatsapp' => false]);
        $at('2026-12-21 18:00');
        $hook($noConsent, ['evento' => 'inicio', 'rama' => 'activa']);
        $hook($noConsent, ['evento' => 'correo', 'codigo' => 'c2', 'estado' => 'enviado']);
        $at('2026-12-23 18:29');
        $run();
        $this->check('L-19 · sin consentimiento comercial por WhatsApp: el plan se cancela y no se envía', $state($noConsent) === 'cancelado' && $sent($ncPhone) === 0);

        // Prueba 17 / §1: never two messages the same day; at most three a week (both channels); 18: only WhatsApp.
        $at('2026-12-28 10:31');   // Monday
        [$other, $otherPhone] = $lead();
        [$busy, $busyPhone] = $lead();
        [$onlyWa, $onlyWaPhone] = $lead(['email' => false, 'whatsapp' => true]);
        foreach ([$other, $busy] as $id) {
            $hook($id, ['evento' => 'inicio', 'rama' => 'activa']);
            $hook($id, ['evento' => 'correo', 'codigo' => 'c2', 'estado' => 'enviado']);   // → WA1 Wednesday 30/12
        }
        $hook($onlyWa, ['evento' => 'inicio', 'rama' => 'activa']);
        $hook($onlyWa, ['evento' => 'correo', 'codigo' => 'c2', 'estado' => 'sin_correo']);
        \App\Communications\Communications::record($team->getKey(), 'email', \App\Models\Communication::OUT, ['phone' => $busyPhone, 'email' => 'x@example.com', 'subject' => 'Entrega', 'body' => 'Entrega', 'source' => 'valoraciones']);
        $at('2026-12-29 12:00');
        \App\Communications\Communications::record($team->getKey(), 'whatsapp', \App\Models\Communication::OUT, ['phone' => $busyPhone, 'body' => 'Recordatorio', 'source' => 'nutricion']);
        $at('2026-12-30 10:00');
        \App\Communications\Communications::record($team->getKey(), 'whatsapp', \App\Models\Communication::OUT, ['phone' => $otherPhone, 'body' => 'Mensaje del asesor', 'source' => 'panel']);
        $at('2026-12-30 18:29');
        $run(6);
        $this->check('Prueba 17 · ya hubo otro mensaje hoy a ese número: su WhatsApp se omite (no se manda tarde)', $slot($other, 'wa1')?->estado === 'omitido' && $sent($otherPhone) === 0);
        $this->check('§1 · como máximo tres mensajes por semana sumando canales (correo C2 + entrega + otro WhatsApp): el cuarto se omite',
            $slot($busy, 'wa1')?->motivo === 'Ya hay tres mensajes esta semana' && $sent($busyPhone) === 0);
        $this->check('Prueba 18 · solo WhatsApp (webhook «sin_correo»): mismo calendario y el WhatsApp sí sale', $slot($onlyWa, 'wa1')?->estado === 'enviado' && $sent($onlyWaPhone) === 1);

        // Test mode (B.3 v2 §8): a team test phone gets minutes instead of working days, even on a Saturday night.
        $at('2027-01-02 22:00');   // Saturday
        [$testLead, $testPhone] = $lead();
        config(['valoraciones.nutricion_whatsapp.prueba_telefonos' => [$testPhone], 'valoraciones.nutricion_whatsapp.prueba_minutos' => 2]);
        $hook($testLead, ['evento' => 'inicio', 'rama' => 'activa']);
        $hook($testLead, ['evento' => 'correo', 'codigo' => 'c2', 'estado' => 'enviado']);
        $run();
        $early = $sent($testPhone);
        $at('2027-01-02 22:05');
        $run();
        [$realLead] = $lead();
        $hook($realLead, ['evento' => 'inicio', 'rama' => 'activa']);
        $real = $hook($realLead, ['evento' => 'correo', 'codigo' => 'c2', 'estado' => 'enviado']);
        $this->check('Modo de prueba · un móvil de prueba recibe WA1 a los 4 minutos (2 DL × 2 min) un sábado por la noche; otro número sigue el calendario real (martes 17:30)',
            $early === 0 && $sent($testPhone) === 1 && str_starts_with((string) ($real['fecha'] ?? ''), '2027-01-05 1'));
        config(['valoraciones.nutricion_whatsapp.prueba_telefonos' => []]);
        \Illuminate\Support\Carbon::setTestNow();
    }

    /**
     * @param  array<string, mixed>  $property
     * @param  array<string, mixed>  $contact
     * @param  array<string, mixed>  $catastro
     * @param  array<string, bool>  $commercial
     */
    private function valuationRequest(\Liberu\Foundation\Organizations\Models\Team $team, array $property, array $contact, array $catastro, array $commercial, string $status = 'estimada'): \App\Valuations\ValuationRequest
    {
        $estimated = $status === 'estimada';
        $r = \App\Valuations\ValuationRequest::query()->create([
            'request_id' => (string) \Illuminate\Support\Str::uuid(), 'team_id' => $team->getKey(), 'operation' => 'venta', 'status' => 'estimada',
            'property' => ['confirmado_por_usuario' => true, ...$property],
            'contact' => ['nombre' => 'PRUEBA Ana Ficticia', 'email' => 'prueba.ana@example.com', 'telefono' => '+34600111222', 'canal' => 'whatsapp', 'franja' => 'tarde', ...$contact],
            'consent' => ['version' => '2026-11-24', 'fecha' => now()->toIso8601String(), 'comercial' => [...$commercial, 'version' => '2026-11-24', 'texto_email' => 'Quiero recibir por correo…', 'texto_whatsapp' => 'Quiero recibir por WhatsApp…']],
            'attribution' => ['utm' => ['source' => 'google', 'campaign' => 'valoraciones-prueba'], 'landing' => '/inmuebles/valoraciones'],
            'catastro' => $catastro === [] ? null : $catastro, 'delivery' => \App\Valuations\ValuationDelivery::initial(),
        ]);
        $path = 'valoraciones/prueba-'.$r->getKey().'.pdf';
        \Illuminate\Support\Facades\Storage::disk(\App\Valuations\ValuationEngine::DISK)->put($path, '%PDF-1.4 prueba');
        $run = \App\Valuations\ValuationRun::query()->create(['valuation_request_id' => $r->getKey(), 'number' => 1, 'version' => 'prueba', 'status' => $status, 'trigger' => 'prueba',
            'result' => $estimated ? ['precio_central' => 170000, 'intervalo' => ['min' => 153000, 'max' => 187000], 'suficiencia' => 'alta', 'version_calculo' => 'prueba'] : null,
            'pdf_path' => $estimated ? $path : null, 'pdf_pages' => $estimated ? 1 : null, 'message' => $estimated ? null : 'Superficie declarada muy distinta de Catastro.']);
        $r->forceFill(['reference' => 'VAL-P'.str_pad((string) $r->getKey(), 5, '0', STR_PAD_LEFT), 'current_run_id' => $run->getKey()])->save();

        return $r->refresh();
    }

    /** Stateful simulated GHL (only what the acquisition circuit calls). */
    private function ghlSim($request): mixed
    {
        if (! $request->hasHeader('Authorization', 'Bearer pit-prueba')) {
            return Http::response(['message' => 'unauthorized'], 401);
        }
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
        $body = (array) $request->data();
        $method = $request->method();
        $merge = function (array $current, array $fields, string $prop): array {
            foreach ($fields as $f) {
                $current = array_values(array_filter($current, fn (array $c): bool => $c['id'] !== $f['id']));
                $current[] = ['id' => $f['id'], $prop => $f['fieldValue'] ?? null];
            }

            return $current;
        };
        if ($method === 'GET' && str_ends_with($path, '/customFields')) {
            $opportunity = ['solicitud_id', 'solicitudes_ids', 'inmueble_clave', 'referencia_valoracion', 'estado_valoracion', 'operacion', 'tipo_inmueble', 'direccion', 'direccion_resumida', 'zona', 'municipio',
                'codigo_postal', 'referencia_catastral', 'oficina', 'superficie_m2', 'dormitorios', 'banos', 'plazo_venta', 'relacion_inmueble', 'precio_estimado', 'precio_minimo', 'precio_maximo', 'intervalo',
                'suficiencia', 'version_calculo', 'fecha_valoracion', 'informe_url', 'informe_caduca', 'datos_aportados', 'entrega_email_estado', 'entrega_whatsapp_estado', 'informe_enviado_at', 'origen',
                'utm_source', 'utm_medium', 'utm_campaign', 'landing', 'situacion_comercial', 'ocupacion', 'comentario_propietario', 'alertas', 'informe_aperturas', 'atribucion_clic'];
            $contact = ['temperatura', 'intereses', 'fuente_original', 'campana_original', 'num_valoraciones', 'num_inmuebles', 'primera_valoracion_fecha', 'canal_preferido', 'franja_preferida', 'idioma', 'nif',
                'consentimiento_tratamiento_version', 'consentimiento_tratamiento_fecha', 'consentimiento_origen', 'consentimiento_comercial_email', 'consentimiento_comercial_email_fecha',
                'consentimiento_comercial_whatsapp', 'consentimiento_comercial_whatsapp_fecha', 'consentimiento_comercial_version', 'consentimiento_comercial_texto', 'consentimiento_comercial_origen',
                'baja_fecha', 'baja_canal', 'baja_origen', 'val_referencia', 'val_direccion_resumida', 'val_zona', 'val_oficina', 'val_precio_minimo_txt', 'val_precio_maximo_txt', 'val_estado',
                'val_plazo_venta', 'val_informe_url', 'val_informe_caduca', 'val_enlace_cita'];

            return Http::response(['customFields' => [...array_map(fn ($k) => ['id' => 'of-'.$k, 'fieldKey' => 'opportunity.'.$k], $opportunity), ...array_map(fn ($k) => ['id' => 'cf-'.$k, 'fieldKey' => 'contact.'.$k], $contact)]]);
        }
        if ($method === 'POST' && $path === '/contacts/upsert') {
            $this->upserts[] = $body;
            $id = collect($this->contacts)->first(fn (array $c): bool => $c['email'] === ($body['email'] ?? null) || $c['phone'] === ($body['phone'] ?? null))['id'] ?? sprintf('CT-%04d', count($this->contacts) + 1);
            $this->contacts[$id] ??= ['id' => $id, 'locationId' => 'LOC-CLARTO', 'email' => $body['email'] ?? null, 'phone' => $body['phone'] ?? null, 'name' => $body['name'] ?? '',
                'firstName' => $body['firstName'] ?? '', 'lastName' => $body['lastName'] ?? '', 'tags' => [], 'customFields' => [], 'dndSettings' => []];

            return Http::response(['contact' => ['id' => $id]]);
        }
        if (preg_match('#^/contacts/([A-Za-z0-9-]+)(/tags|/notes)?$#', $path, $m) && isset($this->contacts[$m[1]])) {
            $id = $m[1];
            if (($m[2] ?? '') === '/tags') {
                foreach ((array) ($body['tags'] ?? []) as $tag) {
                    $this->tagEvents[] = ($method === 'POST' ? '+' : '-').$tag;
                }
                $this->contacts[$id]['tags'] = $method === 'POST' ? array_values(array_unique([...$this->contacts[$id]['tags'], ...$body['tags']])) : array_values(array_diff($this->contacts[$id]['tags'], $body['tags']));

                return Http::response(['tags' => $this->contacts[$id]['tags']]);
            }
            if (($m[2] ?? '') === '/notes') {
                $this->notes[] = ['contact' => $id, 'body' => (string) ($body['body'] ?? '')];

                return Http::response(['note' => ['id' => 'N'.count($this->notes)]], 201);
            }
            if ($method === 'PUT') {
                $this->contacts[$id]['customFields'] = $merge($this->contacts[$id]['customFields'], (array) ($body['customFields'] ?? []), 'value');
                $this->contacts[$id]['dndSettings'] = [...$this->contacts[$id]['dndSettings'], ...(array) ($body['dndSettings'] ?? [])];

                return Http::response(['contact' => $this->contacts[$id]]);
            }

            return Http::response(['contact' => $this->contacts[$id]]);
        }
        if ($method === 'GET' && $path === '/opportunities/search') {
            return Http::response(['opportunities' => array_values(array_filter($this->opps, fn (array $o): bool => ($q['contact_id'] ?? $o['contactId']) === $o['contactId']
                && ($q['pipeline_id'] ?? null) === $o['pipelineId'] && (! isset($q['status']) || $q['status'] === $o['status'])))]);
        }
        if ($method === 'POST' && $path === '/opportunities/') {
            if ($this->noDuplicateOnce) {
                $this->noDuplicateOnce = false;
                $this->opps['OPP-EXISTENTE'] = ['id' => 'OPP-EXISTENTE', 'status' => 'open', 'contactId' => $body['contactId'], 'pipelineId' => $body['pipelineId'], 'locationId' => 'LOC-CLARTO',
                    'pipelineStageId' => 'STAGE-NUEVO', 'name' => 'existente', 'customFields' => []];

                return Http::response(['message' => 'duplicate', 'meta' => ['existingId' => 'OPP-EXISTENTE']], 400);
            }
            $id = 'OPP-C'.(count($this->opps) + 1);
            $this->opps[$id] = ['id' => $id, 'status' => 'open', 'locationId' => $body['locationId'], 'pipelineId' => $body['pipelineId'], 'pipelineStageId' => $body['pipelineStageId'],
                'contactId' => $body['contactId'], 'name' => $body['name'], 'assignedTo' => $body['assignedTo'] ?? null, 'customFields' => $merge([], (array) $body['customFields'], 'fieldValue')];

            return Http::response(['opportunity' => ['id' => $id]], 201);
        }
        if (preg_match('#^/opportunities/([A-Za-z0-9-]+)$#', $path, $m) && isset($this->opps[$m[1]])) {
            if ($method === 'PUT') {
                $this->opps[$m[1]]['customFields'] = $merge($this->opps[$m[1]]['customFields'], (array) ($body['customFields'] ?? []), 'fieldValue');
                $this->opps[$m[1]] = [...$this->opps[$m[1]], ...array_intersect_key($body, array_flip(['pipelineStageId', 'status', 'name']))];
            }

            return Http::response(['opportunity' => $this->opps[$m[1]]]);
        }

        return Http::response(['message' => 'no simulado: '.$method.' '.$path], 404);
    }

    /** Simulated WAHA: «WORKING» only while linked; sendText recorded. */
    private function waha($request): mixed
    {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        if ($request->method() === 'GET' && str_starts_with($path, '/api/sessions/')) {
            return $this->wahaLinked ? Http::response(['name' => 'default', 'status' => 'WORKING']) : Http::response(['message' => 'not found'], 404);
        }
        if ($request->method() === 'POST' && $path === '/api/sendText' && $this->wahaLinked) {
            $this->wahaSends[] = (array) $request->data();

            return Http::response(['id' => 'true_'.count($this->wahaSends)], 201);
        }

        return Http::response(['message' => 'WAHA simulado: no disponible'], 422);
    }
    private function check(string $label, bool $ok): void
    {
        $ok ? $this->pass++ : $this->fail++;
        $this->line(($ok ? 'PASS  ' : 'FAIL  ').$label);
    }
}
