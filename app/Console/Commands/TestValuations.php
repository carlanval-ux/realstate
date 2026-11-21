<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Acquisition\GhlHandoff;
use App\Models\AiSetting;
use App\Models\User;
use App\Modules\ClartoModules;
use App\Valuations\BrandPdf;
use App\Valuations\Catastro;
use App\Valuations\GhlValuationSync;
use App\Valuations\ProcessValuation;
use App\Valuations\ValuationDelivery;
use App\Valuations\ValuationEngine;
use App\Valuations\ValuationReport;
use App\Valuations\ValuationReportMail;
use App\Valuations\ValuationRequest;
use App\Valuations\ValuationRun;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\RealEstate\Properties\Models\Property;
use Throwable;

/**
 * Valoraciones automáticas end to end, in a rolled-back transaction with Http::fake:
 *  - Catastro: REAL answers recorded on 2026-11-21 (resources/valoraciones/pruebas/catastro), replayed;
 *  - prices: the REAL public data of resources/valoraciones/precios.json;
 *  - GoHighLevel, OpenRouter and the AVM providers (idealista/data, Tinsa): SIMULATED.
 * Each check says which ones it used. --ejemplos=<dir> also writes the three example reports there.
 *
 *   php artisan clarto:valoraciones-prueba [--ejemplos=/tmp/valoraciones-ejemplos]
 */
final class TestValuations extends Command
{
    protected $signature = 'clarto:valoraciones-prueba {--ejemplos= : Directory where the example PDFs (one per area) are written}';

    protected $description = 'Test automatic valuations (recorded real Catastro, real price data, simulated GHL/AI/AVM; nothing is kept)';

    private const KEY = 'clave-prueba-valoraciones';

    private const REAL = '[Catastro real grabado + precios públicos]';

    private const PRICES = '[precios públicos]';

    private const SIM = '[simulado]';

    private int $pass = 0;

    private int $fail = 0;

    /** @var array<string, array<string, mixed>> GHL opportunities (simulated) */
    private array $opportunities = [];

    private int $opportunityCreates = 0;

    private bool $ghlDown = false;

    private bool $catastroDown = false;

    private bool $wahaLinked = false;

    /** @var list<array<string, mixed>> WhatsApp messages sent through the simulated WAHA */
    private array $wahaSends = [];

    private string $aiAnswer = '';

    /** @var array<string, string> example PDFs by area */
    private array $examples = [];

    public function handle(): int
    {
        config([
            'cache.default' => 'array',
            'valoraciones.api_key' => self::KEY,
            'queue.default' => 'sync',   // ProcessValuation runs right away (in production: the worker)
            'whatsapp.url' => 'http://waha-prueba.test',
            'valoraciones.resumen_ia' => true,
            'valoraciones.proveedores.idealista.driver' => 'off',
            'valoraciones.proveedores.tinsa.driver' => 'off',
            'ghl.token' => 'pit-prueba', 'ghl.location_id' => 'LOC-CLARTO', 'ghl.pipeline_id' => 'PIPE-CAPTACION', 'ghl.api_url' => 'https://services.leadconnectorhq.com',
            'ghl.fields' => ['solicitud_id' => 'f-sol', 'municipio' => 'f-mun', 'superficie_m2' => 'f-m2'],
            'valoraciones.ghl.stage_id' => 'STAGE-NUEVO',
            'valoraciones.ghl.fields' => ['precio_estimado' => 'f-precio', 'version_calculo' => 'f-version', 'informe_url' => 'f-informe', 'referencia_valoracion' => 'f-ref'],
            'valoraciones.citas' => ['cita_valencia' => 'https://citas.prueba.test/valencia', 'cita_sagunto' => 'https://citas.prueba.test/sagunto', 'cita_bellreguard' => 'https://citas.prueba.test/bellreguard'],
        ]);
        Queue::fake([\App\Tarmio\SyncTarmioTask::class]);
        Mail::fake();
        Storage::fake(ValuationEngine::DISK);
        Http::preventStrayRequests();
        Http::fake(fn (HttpRequest $request) => match (true) {
            str_contains($request->url(), 'ovc.catastro.meh.es') => $this->catastro($request),
            str_contains($request->url(), 'waha-prueba.test') => $this->waha($request),
            str_contains($request->url(), 'leadconnectorhq') => $this->ghl($request),
            str_contains($request->url(), 'openrouter.ai') => Http::response(['model' => 'prueba/modelo', 'choices' => [['message' => ['content' => $this->aiAnswer]]]]),
            str_contains($request->url(), 'avm-prueba.tinsa.test') => Http::response(['error' => 'Service Unavailable'], 503),
            default => Http::response(['message' => 'no simulado: '.$request->url()], 599),
        });

        DB::beginTransaction();
        try {
            AiSetting::current()->forceFill(['provider' => 'openrouter', 'openrouter_api_key' => 'sk-prueba', 'writing_model' => 'prueba/modelo'])->save();
            $this->scenarios();
        } catch (Throwable $e) {
            $this->check(self::SIM, 'sin excepciones: '.$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine(), false);
        } finally {
            DB::rollBack();
        }

        if (($dir = $this->option('ejemplos')) !== null) {
            @mkdir((string) $dir, 0775, true);
            foreach ($this->examples as $name => $pdf) {
                file_put_contents(rtrim((string) $dir, '/')."/{$name}.pdf", $pdf);
                $this->line("Ejemplo: {$dir}/{$name}.pdf (".BrandPdf::countPages($pdf).' págs.)');
            }
        }

        $this->line(sprintf('RESULT: %d passed, %d failed (Catastro: respuestas reales grabadas; precios: datos públicos reales; GHL, IA y AVM simulados; base de datos revertida)', $this->pass, $this->fail));

        return $this->fail === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function scenarios(): void
    {
        $team = Team::query()->oldest()->firstOrFail();
        // Start from a WhatsApp line that is not linked, whatever the copied database says (rolled back at the end).
        \App\Models\WhatsAppLine::forTeam($team->id)->forceFill(['status' => 'SIN_CREAR'])->save();

        // 0. API access.
        $this->check(self::SIM, 'API sin clave: 401', $this->api('GET', '/api/v1/valoraciones/catastro?rc=5714102YJ2751F0005XX', key: '')->getStatusCode() === 401);
        $this->check(self::SIM, 'solicitud incompleta: 422', $this->api('POST', '/api/v1/valoraciones/solicitudes', ['request_id' => 'x'])->getStatusCode() === 422);

        // 1. Valencia: address search narrowed by floor/door → one dwelling with surfaces split.
        $search = $this->json($this->api('GET', '/api/v1/valoraciones/catastro?'.http_build_query(['direccion' => 'Calle de Cuba 22, 2º 3', 'municipio' => 'València', 'provincia' => 'Valencia'])));
        $flat = $search['candidatos'][0] ?? [];
        $this->check(self::REAL, 'Valencia · «Calle de Cuba 22, 2º 3»: Catastro ok con una vivienda (planta 02, puerta 03)', ($search['estado'] ?? '') === 'ok' && count($search['candidatos']) === 1 && $flat['rc'] === '5714102YJ2751F0005XX');
        $this->check(self::REAL, 'Valencia · superficies separadas: vivienda 54 + comunes 4 = 58 m², año 1934, coordenadas, CP 46006',
            $flat['superficie_vivienda_m2'] == 54 && $flat['superficie_elementos_comunes_m2'] == 4 && $flat['superficie_construida_m2'] == 58 && $flat['ano_construccion'] === 1934 && isset($flat['coordenadas']['lat']) && $flat['codigo_postal'] === '46006');
        $valencia = $this->request(['rc' => '5714102YJ2751F0005XX', 'direccion_confirmada' => $flat['direccion'], 'municipio' => 'València', 'codigo_postal' => '46006', 'tipo' => 'piso',
            'superficie_util_m2' => 48, 'dormitorios' => 2, 'banos' => 1, 'planta' => '2', 'ascensor' => false, 'exterior' => true, 'conservacion' => 'buen_estado']);
        $answer = $this->outcome($valencia);
        $this->check(self::REAL, 'Valencia · POST: 201, estimada, precio dentro del intervalo, cierre, versión de cálculo y fuentes con fecha',
            $valencia['response']->getStatusCode() === 201 && $answer['estado'] === 'estimada' && $answer['resultado']['intervalo']['min'] <= $answer['resultado']['precio_central']
            && $answer['resultado']['precio_central'] <= $answer['resultado']['intervalo']['max'] && $answer['resultado']['tipo_precio'] === 'cierre' && filled($answer['resultado']['version_calculo'])
            && collect($answer['resultado']['fuentes'])->every(fn (array $f): bool => filled($f['fecha_datos']) && filled($f['licencia'])) && str_starts_with((string) $answer['referencia'], 'VAL-'));
        $this->check(self::REAL, 'Valencia · superficie de cálculo de Catastro (58 m²; la útil 48 × 1,25 = 60 coincide) y ajuste «planta 2 sin ascensor»',
            ($answer['resultado']['calculo']['superficie_m2'] ?? null) == 58 && ($answer['resultado']['calculo']['superficie_origen'] ?? '') === 'catastro'
            && collect($answer['resultado']['calculo']['ajustes'])->contains(fn (array $a): bool => str_contains($a['concepto'], 'sin ascensor')));
        $this->check(self::SIM, 'Valencia · datos_simulados = false (ningún proveedor simulado activo)', $answer['datos_simulados'] === false);
        $this->pdfChecks('Valencia', $valencia['model'], 'valencia-cuba-22');

        // Report access.
        $url = (string) $answer['informe_url'];
        $relative = Str::after($url, parse_url($url, PHP_URL_HOST) ?? '');
        $signed = $this->api('GET', $relative, key: null);
        $this->check(self::SIM, 'informe por enlace firmado: 200 application/pdf sin sesión', $signed->getStatusCode() === 200 && $signed->headers->get('Content-Type') === 'application/pdf');
        $this->check(self::SIM, 'informe sin firma: 403', $this->api('GET', strtok($relative, '?'), key: null)->getStatusCode() === 403);
        $this->check(self::SIM, 'informe con firma alterada (otra solicitud): 403', $this->api('GET', str_replace($valencia['model']->request_id, (string) Str::uuid(), $relative), key: null)->getStatusCode() === 403);
        $panel = '/filament/clarto/valoraciones/'.$valencia['model']->getKey().'/informe';
        $other = $this->team('Otra inmobiliaria');
        $this->check(self::SIM, 'panel: la administración del equipo descarga el informe', $this->panel($panel, $this->user('Admin CLARTO', $team, 'admin'))->getStatusCode() === 200);
        $this->check(self::SIM, 'panel: una cuenta de otro equipo (aunque sea admin allí) recibe 403', $this->panel($panel, $this->user('Admin otra', $other, 'admin'))->getStatusCode() === 403);
        $this->check(self::SIM, 'panel: personal del equipo sin permiso «valoraciones.ver» recibe 403', $this->panel($panel, $this->user('Comercial sin permiso', $team, 'agente-prueba'))->getStatusCode() === 403);
        $this->check(self::SIM, 'panel: sin sesión no se sirve', $this->panel($panel, null)->getStatusCode() !== 200);
        $this->check(self::SIM, 'listado: otro equipo no ve la solicitud', ValuationRequest::query()->visibleTo(User::query()->where('name', 'Admin otra')->first())->whereKey($valencia['model']->getKey())->doesntExist());

        // GHL: one opportunity, custom fields with the result and the private link; AI summary (simulated) accepted.
        $opportunity = $this->opportunities[$valencia['model']->refresh()->ghl_opportunity_id] ?? null;
        $fields = collect($opportunity['customFields'] ?? [])->pluck('field_value', 'id');
        $this->check(self::SIM, 'GHL: contacto + 1 oportunidad en la etapa, con solicitud_id, precio, versión y enlace privado al informe',
            $valencia['model']->ghl_status === 'sincronizada' && $opportunity !== null && $opportunity['pipelineStageId'] === 'STAGE-NUEVO'
            && $fields['f-sol'] === $valencia['model']->request_id && $fields['f-precio'] === (string) $answer['resultado']['precio_central'] && filled($fields['f-version']) && str_contains((string) $fields['f-informe'], 'signature='));
        $this->check(self::SIM, 'IA sin respuesta (simulada): el informe lleva el resumen de plantilla con las cifras del cálculo', $valencia['model']->currentRun->summary_source === 'plantilla');
        $this->aiAnswer = 'Es una estimación automática orientativa basada en precios de cierre de la zona y en los datos de Catastro de la vivienda, con el intervalo que se indica en el informe. Conviene que el equipo de CLARTO revise el inmueble para ajustar el resultado a su estado real.';
        $run = $this->request($valencia['body']['inmueble'])['model']->currentRun;
        $this->check(self::SIM, 'IA (simulada): resumen aceptado solo con cifras del cálculo; PDF regenerado (portada + 2)', $run->summary_source === 'ia' && $run->pdf_pages === 3);

        // 1b. Delivery: the web gets «recibida», the client an e-mail (PDF + link) and a WhatsApp (link) once the line is linked.
        $http = $this->json($valencia['response']);
        $this->check(self::SIM, 'POST: respuesta sin resultado para el navegador: {estado: recibida, referencia, envio: {email: enviado, whatsapp: linea_no_vinculada}}',
            $http['estado'] === 'recibida' && ! isset($http['resultado']) && ! isset($http['informe_url']) && $http['referencia'] === $valencia['model']->reference
            && $http['envio'] === ['email' => 'enviado', 'whatsapp' => 'linea_no_vinculada'] && str_contains((string) $http['mensaje'], 'WhatsApp'));
        $mails = Mail::sent(ValuationReportMail::class, fn (ValuationReportMail $m): bool => $m->valuation->is($valencia['model']));
        $mail = $mails->first();
        $html = $mail?->render() ?? '';
        $this->check(self::SIM, 'correo 1 (B.1, L-9): 1 envío con el PDF adjunto («Con portada», 3 págs.), intervalo en el cuerpo, enlace firmado de texto y UN botón a la visita de la oficina de Valencia',
            $mails->count() === 1 && $mail->hasTo('lucia.prueba@example.com') && count($mail->attachments()) === 1
            && str_contains($html, 'signature=') && str_contains($html, number_format((float) $answer['resultado']['intervalo']['min'], 0, ',', '.'))
            && str_contains($html, 'Solicitar valoración personalizada') && str_contains($html, 'https://citas.prueba.test/valencia?utm_content=correo1')
            && str_starts_with($mail->subjectLine(), 'Su valoración de ') && str_contains($mail->plainText(), 'Responsable: Carlos Miguel Landaeta Valdivia'));
        $this->check(self::SIM, 'API (L-13): la respuesta trae oficina_nombre y enlace_cita con utm_content=gracias',
            ($http['oficina_nombre'] ?? null) === 'Valencia centro' && ($http['enlace_cita'] ?? null) === 'https://citas.prueba.test/valencia?utm_content=gracias');
        $pdf = (string) Storage::disk(ValuationEngine::DISK)->get((string) $valencia['model']->refresh()->currentRun->pdf_path);
        $this->check(self::SIM, 'PDF (L-17): enlaces activos a la visita (utm_content=pdf), tel: y wa.me con la referencia',
            str_contains($pdf, '/URI (https://citas.prueba.test/valencia?utm_content=pdf)') && str_contains($pdf, '/URI (tel:+34960303131)') && str_contains($pdf, 'wa.me/34623229779?text='));
        $m = $valencia['model']->refresh();
        $wa = fn (?string $plazo): string => ValuationDelivery::whatsAppText((clone $m)->forceFill(['contact' => [...(array) $m->contact, 'plazo_venta' => $plazo]]), $m->currentRun);
        $this->check(self::SIM, 'WhatsApp 1 (B.2, L-16) según el plazo: <3 meses → asesor de la oficina + visita; 6–12 → qué preparar; solo valor → dudas; sin plazo → pregunta B.2',
            str_contains($wa('menos_3m'), 'Su asesor de la oficina de Valencia centro') && str_contains($wa('menos_3m'), 'utm_content=whatsapp1')
            && str_contains($wa('6_12m'), 'qué conviene preparar') && ! str_contains($wa('6_12m'), '¿Está pensando en vender')
            && str_contains($wa('solo_informacion'), 'cualquier duda') && str_contains($wa(null), '¿Está pensando en vender'));
        $this->check(self::SIM, 'API (L-12): plazo de venta, relación, ocupación o en venta con valores desconocidos → 422',
            $this->api('POST', '/api/v1/valoraciones/solicitudes', $this->body(['rc' => null, 'direccion_confirmada' => 'x', 'municipio' => 'Gandia', 'tipo' => 'piso'], ['plazo_venta' => 'pronto']))->getStatusCode() === 422
            && $this->api('POST', '/api/v1/valoraciones/solicitudes', $this->body(['rc' => null, 'direccion_confirmada' => 'x', 'municipio' => 'Gandia', 'tipo' => 'piso', 'ocupacion' => 'okupada']))->getStatusCode() === 422);
        $log = \App\Models\Communication::query()->where('related_id', (string) $valencia['model']->getKey())->where('source', 'valoraciones')->get();
        $this->check(self::SIM, 'envíos registrados en comunicaciones (correo enviado; WhatsApp «no enviado» por línea sin vincular) y en la solicitud',
            $log->where('channel', 'email')->where('status', 'enviado')->count() === 1 && $log->where('channel', 'whatsapp')->where('status', 'error')->count() === 1
            && ValuationDelivery::state($valencia['model']->refresh())['whatsapp']['estado'] === 'linea_no_vinculada');
        $this->check(self::SIM, 'teléfono obligatorio y válido: sin teléfono 422, «12345» 422, +44 válido',
            $this->api('POST', '/api/v1/valoraciones/solicitudes', $this->body(['rc' => null, 'direccion_confirmada' => 'x', 'municipio' => 'Gandia', 'tipo' => 'piso'], ['telefono' => null]))->getStatusCode() === 422
            && $this->api('POST', '/api/v1/valoraciones/solicitudes', $this->body(['rc' => null, 'direccion_confirmada' => 'x', 'municipio' => 'Gandia', 'tipo' => 'piso'], ['telefono' => '12345']))->getStatusCode() === 422
            && \App\WhatsApp\Phone::e164('+44 7700 900123') === '+447700900123');

        // 2. Puerto de Sagunto: ambiguous address (26 flats) → the visitor picks one.
        $search = $this->json($this->api('GET', '/api/v1/valoraciones/catastro?'.http_build_query(['direccion' => 'C/ Castellón 2', 'municipio' => 'Puerto de Sagunto'])));
        $this->check(self::REAL, 'Puerto de Sagunto · dirección ambigua «C/ Castellón 2»: estado «varias» con 26 viviendas para elegir',
            ($search['estado'] ?? '') === 'varias' && count($search['candidatos']) === 26 && collect($search['candidatos'])->every(fn (array $c): bool => $c['uso'] === 'Residencial' && filled($c['planta'])));
        $this->aiAnswer = 'Estimación orientativa de 999.999 € para la vivienda.';   // number not in the calculation → rejected
        $sagunto = $this->request(['rc' => '9342915YJ3994S0014UD', 'direccion_confirmada' => 'CL CASTELLON 2, Esc 1, Pl 04, Pt 14', 'municipio' => 'Sagunto', 'codigo_postal' => '46520', 'tipo' => 'piso',
            'superficie_construida_m2' => 105, 'incluye_elementos_comunes' => false, 'dormitorios' => 3, 'banos' => 2, 'planta' => '4', 'ascensor' => true, 'exterior' => true, 'terraza_m2' => 6, 'conservacion' => 'reformado']);
        $answer = $this->outcome($sagunto);
        $this->check(self::REAL, 'Puerto de Sagunto · vivienda elegida (Pl 04 Pt 14): estimada con 109 m² de Catastro (105 declarados, diferencia < 10 %)',
            $answer['estado'] === 'estimada' && ($answer['resultado']['calculo']['superficie_m2'] ?? null) == 109);
        $this->check(self::SIM, 'IA (simulada) con una cifra inventada: descartada, se mantiene la plantilla', $sagunto['model']->currentRun->summary_source === 'plantilla');
        $this->pdfChecks('Puerto de Sagunto', $sagunto['model'], 'puerto-sagunto-castellon-2');

        // 2b. The worked example of the methodology (valoraciones-investigacion.md §4.1), reproduced with real data.
        $this->aiAnswer = '';
        $example = $this->outcome($this->request(['rc' => '9342915YJ3994S0002ZR', 'direccion_confirmada' => 'CL CASTELLON 2, Esc 1, Pl 01, Pt 02', 'municipio' => 'Sagunto', 'tipo' => 'piso',
            'planta' => '1', 'ascensor' => true, 'exterior' => true, 'conservacion' => 'buen_estado']));
        $calc = $example['resultado']['calculo'] ?? [];
        $this->check(self::REAL, 'Ejemplo de la metodología (Sagunto 0002ZR, 109 m², 1979): base MIVAU >5 años 1.612,3 €/m² × 109 × 0,97 = 170.000 €, 75 puntos (alta), ±10 % → 153.000–187.000 €',
            $example['estado'] === 'estimada' && $calc['base_columna'] === 'mas_5_anos' && $calc['base_eur_m2_publicado'] == 1612.3 && $example['resultado']['precio_central'] === 170000
            && $calc['suficiencia_puntos'] === 75 && $example['resultado']['suficiencia'] === 'alta' && $example['resultado']['intervalo'] === ['min' => 153000, 'max' => 187000]);

        // 3. Gandía city (CP 46702): address search and discrepant surfaces.
        $search = $this->json($this->api('GET', '/api/v1/valoraciones/catastro?'.http_build_query(['direccion' => 'Av. República Argentina 30, 4º 17', 'municipio' => 'Gandía'])));
        $this->check(self::REAL, 'Gandía · «Av. República Argentina 30, 4º 17»: Catastro ok (99 m², 1965, CP 46702)',
            ($search['estado'] ?? '') === 'ok' && ($search['candidatos'][0]['rc'] ?? '') === '3970903YJ4137S0018FH' && $search['candidatos'][0]['superficie_construida_m2'] == 99);
        $city = ['rc' => '3970903YJ4137S0018FH', 'direccion_confirmada' => 'AV REPUBLICA ARGENTINA DE LA 30, Esc 1, Pl 04, Pt 17', 'municipio' => 'Gandia', 'tipo' => 'piso',
            'dormitorios' => 3, 'banos' => 1, 'planta' => '4', 'ascensor' => true, 'exterior' => true, 'conservacion' => 'buen_estado'];
        $gandia = $this->request([...$city, 'superficie_util_m2' => 80]);
        $answer = $this->outcome($gandia);
        $this->check(self::REAL, 'Gandía ciudad · estimada con 99 m² de Catastro (útil 80 × 1,25 = 100, coherente) y la media municipal (sin precio de CP autorizado)',
            $answer['estado'] === 'estimada' && $answer['resultado']['calculo']['superficie_m2'] == 99 && $answer['resultado']['calculo']['base_nivel'] === 'municipio');
        $this->pdfChecks('Gandía', $gandia['model'], 'gandia-republica-argentina-30');
        $m = $this->outcome($this->request([...$city, 'superficie_construida_m2' => 120, 'incluye_elementos_comunes' => true]));
        $this->check(self::REAL, 'Gandía ciudad · 120 m² declarados frente a 99 m² de Catastro (21 %): se usa Catastro, baja la suficiencia y el intervalo pasa a ±19 %',
            $m['estado'] === 'estimada' && $m['resultado']['calculo']['superficie_m2'] == 99 && $m['resultado']['suficiencia'] === 'media' && $m['resultado']['calculo']['margen'] == 0.19);
        $excess = $this->outcome($this->request([...$city, 'superficie_util_m2' => 150]));
        $this->check(self::REAL, 'Gandía ciudad · útil 150 m² (188 construidos) frente a 99 m²: revisión del equipo con explicación', $excess['estado'] === 'revision' && str_contains((string) $excess['mensaje'], 'Catastro') && $excess['resultado'] === null);

        // 3b. Gandía beach (CP 46730): annexes are not added twice, and the municipal mean does not represent the zone.
        $beach = ['rc' => '5910612YJ4251S0012RD', 'direccion_confirmada' => 'PS MARITIM NEPTU 40, Esc 1, Pl 05, Pt B', 'municipio' => 'Gandia', 'tipo' => 'piso',
            'superficie_construida_m2' => 118, 'incluye_elementos_comunes' => true, 'planta' => '5', 'ascensor' => true, 'terraza_m2' => 12, 'garaje' => 'incluido', 'trastero' => true, 'conservacion' => 'buen_estado'];
        $playa = $this->request($beach);
        $answer = $this->outcome($playa);
        $subject = (array) ($playa['model']->currentRun?->result['subject'] ?? []);
        $this->check(self::REAL, 'Gandía playa · 118 m² declarados = total de Catastro con garaje y trasteros → 80 m² (68 + 12 comunes), anexos aparte; y «revision»: la media municipal no representa el CP 46730',
            $answer['estado'] === 'revision' && str_contains((string) $answer['mensaje'], '46730') && ($subject['superficie_m2'] ?? null) == 80 && ($subject['discrepancia'] ?? '') === 'anexos');
        config(['valoraciones.fuentes_pendientes_licencia' => true]);
        $licensed = $this->outcome($this->request($beach));
        $this->check(self::REAL, 'Gandía playa · con datos de CP con licencia (PRUEBA INTERNA, Notariado 46730: 2.125 €/m²) sí se estima, con el garaje y el trastero como ajuste',
            $licensed['estado'] === 'estimada' && $licensed['resultado']['calculo']['base_nivel'] === 'codigo_postal' && collect($licensed['resultado']['calculo']['ajustes'])->contains(fn (array $a): bool => str_starts_with($a['concepto'], 'Plaza de garaje')));
        config(['valoraciones.fuentes_pendientes_licencia' => false]);

        // 4. Bellreguard: < 25.000 inhabitants, no MIVAU appraisal value; only the Notariado (licence pending) has data.
        $house = ['rc' => '6147725YJ4164N0001IQ', 'direccion_confirmada' => 'CL MAJOR 10', 'municipio' => 'Bellreguard', 'codigo_postal' => '46713', 'tipo' => 'casa',
            'superficie_construida_m2' => 110, 'incluye_elementos_comunes' => true, 'dormitorios' => 3, 'banos' => 2, 'conservacion' => 'a_reformar', 'garaje' => 'incluido'];
        $bell = $this->outcome($this->request([...$house, 'rc' => null, 'confirmado_por_usuario' => false]));   // surfaces as declared
        $this->check(self::PRICES, 'Bellreguard · solo hay datos de uso pendiente de licencia (Notariado, portal): «revision» explicando qué falta, sin precio',
            $bell['estado'] === 'revision' && str_contains((string) $bell['mensaje'], 'licencia') && $bell['resultado'] === null);
        config(['valoraciones.fuentes_pendientes_licencia' => true]);
        $answer = $this->outcome($this->request($house));
        $this->check(self::REAL, 'Bellreguard · aun con datos del Notariado (PRUEBA INTERNA): Catastro solo da 36 m² de VIVIENDA de 113 (garaje y almacenes) → «revision», el equipo comprueba qué es vivienda',
            $answer['estado'] === 'revision' && str_contains((string) $answer['mensaje'], '36') && $answer['resultado'] === null);
        config(['valoraciones.fuentes_pendientes_licencia' => false]);

        // 5. No comparables → revision, request kept and still sent to GHL as a lead.
        $none = $this->request(['rc' => null, 'direccion_confirmada' => 'Calle Mayor 3', 'municipio' => 'Castielfabib', 'tipo' => 'casa', 'superficie_construida_m2' => 120, 'confirmado_por_usuario' => false]);
        $answer = $this->outcome($none);
        $this->check(self::PRICES, 'sin datos de precios (Castielfabib): «revision», mensaje claro, sin precio ni informe; solicitud guardada',
            $answer['estado'] === 'revision' && $answer['resultado'] === null && $answer['informe_url'] === null && str_contains((string) $answer['mensaje'], 'Castielfabib') && $none['model']->exists);
        $this->check(self::SIM, 'revisión también crea su oportunidad en GHL (el equipo la atiende)', $none['model']->refresh()->ghl_status === 'sincronizada' && isset($this->opportunities[$none['model']->ghl_opportunity_id]));

        // 6. Provider failure → fallback, visible. Then a simulated provider → datos_simulados.
        config(['valoraciones.proveedores.tinsa.driver' => 'http', 'valoraciones.proveedores.tinsa.url' => 'https://avm-prueba.tinsa.test/v1/valoracion', 'valoraciones.proveedores.tinsa.secret' => 'clave-prueba']);
        $fallback = $this->request(['rc' => '5714102YJ2751F0005XX', 'direccion_confirmada' => 'CL CUBA 22, Esc 1, Pl 02, Pt 03', 'municipio' => 'València', 'tipo' => 'piso']);
        $answer = $this->outcome($fallback);
        $run = $fallback['model']->currentRun;
        $this->check(self::SIM, 'Tinsa AVM (HTTP 503 simulado): se usa el Estimador CLARTO, el fallo queda en el cálculo y en las limitaciones',
            $answer['estado'] === 'estimada' && $run->provider === 'clarto' && ($run->provider_errors[0]['proveedor'] ?? '') === 'tinsa'
            && collect($answer['resultado']['limitaciones'])->contains(fn (string $l): bool => str_contains($l, 'Tinsa')));
        config(['valoraciones.proveedores.tinsa.driver' => 'off', 'valoraciones.proveedores.idealista.driver' => 'simulado']);
        $simulated = $this->outcome($this->request(['rc' => '5714102YJ2751F0005XX', 'direccion_confirmada' => 'CL CUBA 22, Esc 1, Pl 02, Pt 03', 'municipio' => 'València', 'tipo' => 'piso']));
        $this->check(self::SIM, 'idealista/data en modo simulado: datos_simulados = true y fuente marcada «SIMULADO»',
            $simulated['datos_simulados'] === true && str_contains((string) $simulated['resultado']['fuentes'][0]['nombre'], 'SIMULADO'));
        config(['valoraciones.proveedores.idealista.driver' => 'off']);

        // 7. Retries never duplicate the opportunity.
        $before = $this->opportunityCreates;
        $again = $this->api('POST', '/api/v1/valoraciones/solicitudes', $valencia['body']);
        $this->check(self::SIM, 'reintento con el mismo request_id: 200, misma referencia, ninguna oportunidad nueva',
            $again->getStatusCode() === 200 && $this->json($again)['referencia'] === $valencia['model']->reference && $this->opportunityCreates === $before && ValuationRequest::query()->where('request_id', $valencia['model']->request_id)->count() === 1);
        $this->ghlDown = true;
        $down = $this->request(['rc' => '9342915YJ3994S0014UD', 'direccion_confirmada' => 'CL CASTELLON 2, Pl 04, Pt 14', 'municipio' => 'Sagunto', 'tipo' => 'piso']);
        $this->check(self::SIM, 'GHL caído: la estimación se entrega igual y la sincronización queda en «error»', $this->outcome($down)['estado'] === 'estimada' && $down['model']->refresh()->ghl_status === 'error');
        $this->ghlDown = false;
        $this->api('GET', '/api/v1/valoraciones/solicitudes/'.$down['model']->request_id);
        $this->check(self::SIM, 'consulta posterior: se reintenta GHL y queda sincronizada con UNA oportunidad', $down['model']->refresh()->ghl_status === 'sincronizada' && $this->countOpportunities($down['model']) === 1);
        $down['model']->forceFill(['ghl_opportunity_id' => null, 'ghl_status' => 'error'])->save();   // id lost after GHL created it
        (new GhlValuationSync)->sync($down['model']);
        $this->check(self::SIM, 'id de oportunidad perdido: se encuentra por la etiqueta de la solicitud y no se crea otra', $down['model']->refresh()->ghl_status === 'sincronizada' && $this->countOpportunities($down['model']) === 1);

        // 7b. The WhatsApp line gets linked: the scheduled retry sends what was pending, once.
        $this->wahaLinked = true;
        Artisan::call('clarto:valoraciones-reenviar');
        $sendsFor = fn (ValuationRequest $r): array => array_values(array_filter($this->wahaSends, fn (array $b): bool => str_contains((string) ($b['text'] ?? ''), (string) $r->reference)));
        $sent = $sendsFor($valencia['model']);
        $this->check(self::SIM, 'línea vinculada (WAHA simulado): «clarto:valoraciones-reenviar» envía el WhatsApp pendiente con el enlace firmado, al número E.164',
            ValuationDelivery::state($valencia['model']->refresh())['whatsapp']['estado'] === 'enviado' && count($sent) === 1
            && str_contains((string) $sent[0]['text'], 'signature=') && $sent[0]['chatId'] === '34600000000@c.us');
        $noneSends = $sendsFor($none['model']);
        $noneMail = Mail::sent(ValuationReportMail::class, fn (ValuationReportMail $m): bool => $m->valuation->is($none['model']))->first();
        $this->check(self::SIM, 'revisión: correo sin PDF ni precio y WhatsApp «el equipo la revisará»',
            $noneMail !== null && $noneMail->attachments() === [] && str_contains($noneMail->subjectLine(), 'Hemos recibido') && count($noneSends) === 1 && str_contains((string) $noneSends[0]['text'], 'revisará'));
        $legacy = ValuationRequest::query()->create(['request_id' => (string) Str::uuid(), 'team_id' => $team->id, 'property' => $valencia['body']['inmueble'],
            'contact' => ['nombre' => 'Solicitud anterior', 'email' => 'anterior@example.com']]);
        app(ValuationEngine::class)->run($legacy);
        $this->api('POST', '/api/v1/valoraciones/solicitudes', $valencia['body']);
        Artisan::call('clarto:valoraciones-reenviar');
        $this->check(self::SIM, 'solicitudes anteriores al envío automático (sin estado de envío) no reciben nada al desplegar',
            Mail::sent(ValuationReportMail::class, fn (ValuationReportMail $m): bool => $m->valuation->is($legacy))->isEmpty() && $legacy->refresh()->delivery === null);
        $this->check(self::SIM, 'reintentos (mismo request_id + reenvío programado): ni correos, ni WhatsApp, ni oportunidades duplicados',
            count($sendsFor($valencia['model'])) === 1 && Mail::sent(ValuationReportMail::class, fn (ValuationReportMail $m): bool => $m->valuation->is($valencia['model']))->count() === 1
            && \App\Models\Communication::query()->where('idempotency_key', 'valoracion:'.$valencia['model']->request_id.':1:whatsapp')->count() === 1 && $this->countOpportunities($valencia['model']) === 1);

        // 8. Recalculation keeps the history.
        $model = $valencia['model']->refresh();
        $firstRun = $model->currentRun;
        $firstUrl = $model->reportUrl();
        $second = app(ValuationEngine::class)->run($model, 'recalculo', null);
        $model->refresh();
        (new GhlValuationSync)->sync($model);
        $this->check(self::REAL, 'Recalcular: cálculo n.º 2, el n.º 1 y su PDF se conservan, nuevo enlace, la oportunidad se actualiza (no se crea otra)',
            $second->number === 2 && $model->runs()->count() === 2 && ValuationRun::query()->whereKey($firstRun->getKey())->exists() && Storage::disk(ValuationEngine::DISK)->exists((string) $firstRun->pdf_path)
            && $model->current_run_id === $second->getKey() && $model->reportUrl() !== $firstUrl && $this->countOpportunities($model) === 1
            && str_contains((string) collect($this->opportunities[$model->ghl_opportunity_id]['customFields'])->firstWhere('id', 'f-informe')['field_value'], '/2?'));
        Artisan::call('clarto:valoraciones-reenviar');
        $quiet = Mail::sent(ValuationReportMail::class, fn (ValuationReportMail $m): bool => $m->valuation->is($model))->count() === 1;
        ProcessValuation::dispatch($model->getKey(), resend: true);   // panel «Enviar este cálculo al cliente»
        $this->check(self::SIM, 'un recálculo no se envía solo; «Enviar este cálculo al cliente» manda el n.º 2 por correo y WhatsApp (una vez)',
            $quiet && Mail::sent(ValuationReportMail::class, fn (ValuationReportMail $m): bool => $m->valuation->is($model))->count() === 2 && count($sendsFor($model)) === 2
            && ValuationDelivery::state($model->refresh())['run'] === 2 && ValuationDelivery::state($model)['email']['estado'] === 'enviado');

        // 9. Catastro down (the cache of the answers above is emptied first).
        $this->catastroDown = true;
        \Illuminate\Support\Facades\Cache::flush();
        $cached = new Catastro;
        $this->check(self::SIM, 'Catastro no disponible (timeout simulado): «catastro_no_disponible», no se guarda en caché',
            $cached->search('Calle Desconocida 7', 'Gandia', 'Valencia')['estado'] === 'catastro_no_disponible');
        $unavailable = $this->request(['rc' => '5910612YJ4251S0012RD', 'direccion_confirmada' => 'PS MARITIM NEPTU 40', 'municipio' => 'Gandia', 'tipo' => 'piso', 'superficie_construida_m2' => 80, 'incluye_elementos_comunes' => true]);
        $answer = $this->outcome($unavailable);
        $this->check(self::PRICES, 'POST con Catastro caído: estima con los datos aportados, suficiencia rebajada y limitación explícita',
            $answer['estado'] === 'estimada' && $answer['resultado']['suficiencia'] !== 'alta' && ($unavailable['model']->catastro['error'] ?? '') === 'catastro_no_disponible');
        $this->catastroDown = false;

        // 10. Won in GHL → owner, draft property, TARMIO; the draft property carries the valuation.
        $opportunityId = (string) $gandia['model']->refresh()->ghl_opportunity_id;
        $this->opportunities[$opportunityId]['status'] = 'won';
        $handoff = GhlHandoff::process($opportunityId)['handoff'];
        $property = Property::query()->find($handoff->property_id);
        $this->check(self::SIM, 'oportunidad ganada: GhlHandoff abre propietario e inmueble en borrador con la referencia de la valoración',
            in_array($handoff->status, ['procesado', 'pendiente'], true) && $property !== null && str_contains((string) $property->internal_notes, (string) $gandia['model']->reference)
            && $gandia['model']->refresh()->property_id === $property->getKey());

        // 11. Module switched off: no new requests, existing ones still answer.
        ClartoModules::disable('valoraciones');
        $this->check(self::SIM, 'módulo desactivado: POST nuevo 403, consulta de una existente 200',
            $this->api('POST', '/api/v1/valoraciones/solicitudes', $this->body(['rc' => null, 'direccion_confirmada' => 'x', 'municipio' => 'Gandia', 'tipo' => 'piso', 'superficie_construida_m2' => 80]))->getStatusCode() === 403
            && $this->api('GET', '/api/v1/valoraciones/solicitudes/'.$valencia['model']->request_id)->getStatusCode() === 200);
        ClartoModules::enable('valoraciones');
    }

    private function pdfChecks(string $area, ValuationRequest $request, string $example): void
    {
        $run = $request->refresh()->currentRun;
        $pdf = $run?->pdf_path ? (string) Storage::disk(ValuationEngine::DISK)->get($run->pdf_path) : '';
        $pages = BrandPdf::countPages($pdf);
        $text = $this->pdfText($pdf);
        $r = (array) $run?->result;
        $price = isset($r['precio_central']) ? number_format((float) $r['precio_central'], 0, ',', '.').' € ('.number_format((float) $r['intervalo']['min'], 0, ',', '.').'–'.number_format((float) $r['intervalo']['max'], 0, ',', '.').' €, suficiencia '.$r['suficiencia'].', '.$r['calculo']['superficie_m2'].' m²)' : '—';
        $this->check(self::REAL, "{$area} · {$price} · informe guardado en el formato del módulo («Con portada»): {$pages} páginas (portada + 2)",
            str_starts_with($pdf, '%PDF-') && $pages === 3 && $run->pdf_pages === 3 && str_contains($text, 'Preparado para'));
        $limits = ['compacta' => [1, 2], 'esencial' => [1, 2], 'portada' => [3, 3]];
        $counts = [];
        $ok = true;
        foreach ($limits as $format => [$min, $max]) {
            $bytes = ValuationReport::render($request, $run, $format);
            $text = $this->pdfText($bytes);
            $counts[] = $format.' '.BrandPdf::countPages($bytes);
            $ok = $ok && BrandPdf::countPages($bytes) >= $min && BrandPdf::countPages($bytes) <= $max
                && str_contains($text, 'ESTIMACIÓN ORIENTATIVA') && ! str_contains(mb_strtolower($text), 'automática orientativa')
                && str_contains($text, 'Solicitar una valoración personalizada con CLARTO') && str_contains($text, 'Observaciones') && str_contains($text, 'Fuentes')
                && str_contains($text, 'VENDA CON CLARTO') && str_contains($text, 'abogados, asesores y gestores') && ! str_contains($text, 'Tecnología actual')
                && ($format === 'compacta' || ! \App\Valuations\ValuationBrochure::$lastOverflow)
                && ! str_contains($text, 'Principales limitaciones') && ! str_contains($text, 'Versión del cálculo') && str_contains($text, 'no es una tasación oficial (Orden ECO/805/2003)')
                && str_contains($bytes, '/Subject (Estimador CLARTO');
            $this->examples["{$example}-{$format}"] = $bytes;
        }
        $this->check(self::REAL, "{$area} · los 3 formatos (".implode(', ', $counts).' págs.): compacta y esencial ≤ 2, con portada = 3; «Estimación orientativa», «Observaciones», sin la línea de versión (va en los metadatos)', $ok);
    }

    /** Text of our own PDFs (FlateDecode streams, WinAnsi strings). */
    private function pdfText(string $pdf): string
    {
        preg_match_all('/stream\n(.*?)\nendstream/s', $pdf, $m);
        $text = '';
        foreach ($m[1] as $stream) {
            $plain = @gzuncompress($stream);
            if ($plain !== false && preg_match_all('/\((.*?)(?<!\\\\)\) Tj/s', $plain, $t)) {
                $text .= ' '.implode(' ', array_map(fn (string $s): string => (string) mb_convert_encoding(stripslashes($s), 'UTF-8', 'Windows-1252'), $t[1]));
            }
        }

        return $text;
    }

    /**
     * POST a request through the API.
     *
     * @param  array<string, mixed>  $property
     * @return array{response: \Symfony\Component\HttpFoundation\Response, model: ValuationRequest, body: array<string, mixed>}
     */
    private function request(array $property): array
    {
        $body = $this->body($property);
        $response = $this->api('POST', '/api/v1/valoraciones/solicitudes', $body);

        return ['response' => $response, 'model' => ValuationRequest::query()->where('request_id', $body['request_id'])->firstOrNew(), 'body' => $body];
    }

    /** @param array<string, mixed> $property */
    private function body(array $property, array $contact = []): array
    {
        return [
            'request_id' => (string) Str::uuid(),
            'operacion' => 'venta',
            'inmueble' => ['confirmado_por_usuario' => true, ...$property],
            'contacto' => ['nombre' => 'Lucía Prueba Ficticia', 'email' => 'lucia.prueba@example.com', 'telefono' => '600 00 00 00', 'canal' => 'whatsapp', 'franja' => 'tardes', ...$contact],
            'consentimiento' => ['version' => 'privacidad-2026-11', 'fecha' => now()->toIso8601String()],
            'atribucion' => ['utm' => ['source' => 'prueba'], 'landing' => '/inmuebles/valoraciones'],
        ];
    }

    /** @param array<string, mixed>|null $body */
    private function api(string $method, string $uri, ?array $body = null, ?string $key = self::KEY): \Symfony\Component\HttpFoundation\Response
    {
        Auth::forgetGuards();
        $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '10.0.0.'.random_int(2, 250), 'HTTP_X_CLIENTE_IP' => '192.0.2.'.random_int(2, 250)];
        if ($key !== null) {
            $server['HTTP_X_VALORACIONES_CLAVE'] = $key;
        }

        return app(Kernel::class)->handle(Request::create($uri, $method, [], [], [], $server, $body === null ? null : (string) json_encode($body)));
    }

    private function panel(string $uri, ?User $user): \Symfony\Component\HttpFoundation\Response
    {
        Auth::forgetGuards();
        if ($user !== null) {
            Auth::guard('web')->setUser($user);
        }

        return app(Kernel::class)->handle(Request::create($uri, 'GET'));
    }

    /** @return array<string, mixed> */
    /**
     * What the engine decided for a POSTed request (the API no longer shows it): status, result, report link, reason.
     *
     * @param  array{response: \Symfony\Component\HttpFoundation\Response, model: ValuationRequest}  $posted
     * @return array<string, mixed>
     */
    private function outcome(array $posted): array
    {
        $model = $posted['model']->refresh();
        $run = $model->currentRun;

        return [
            'estado' => $run?->status ?? $model->status, 'referencia' => $model->reference, 'datos_simulados' => (bool) $run?->simulated,
            'resultado' => $run?->status === 'estimada' ? $run->result : null, 'informe_url' => $run?->status === 'estimada' ? $model->reportUrl() : null,
            'mensaje' => (string) ($run?->message ?? ''),
        ];
    }

    /** Simulated WAHA: the session is «WORKING» only once linked; sendText is recorded. */
    private function waha(HttpRequest $request): mixed
    {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        if ($request->method() === 'GET' && str_starts_with($path, '/api/sessions/')) {
            return $this->wahaLinked ? Http::response(['name' => 'default', 'status' => 'WORKING', 'me' => ['id' => '34960303131@c.us', 'pushName' => 'CLARTO']]) : Http::response(['message' => 'not found'], 404);
        }
        if ($request->method() === 'POST' && $path === '/api/sendText' && $this->wahaLinked) {
            $this->wahaSends[] = (array) $request->data();

            return Http::response(['id' => 'true_'.((array) $request->data())['chatId'].'_'.count($this->wahaSends)], 201);
        }

        return Http::response(['message' => 'WAHA simulado: no disponible'], 422);
    }

    private function json(\Symfony\Component\HttpFoundation\Response $response): array
    {
        return (array) json_decode((string) $response->getContent(), true);
    }

    private function countOpportunities(ValuationRequest $request): int
    {
        return count(array_filter($this->opportunities, fn (array $o): bool => str_contains((string) $o['name'], GhlValuationSync::tag($request->request_id))));
    }

    /** Recorded real Catastro answers (2026-11-21), or a simulated timeout. */
    private function catastro(HttpRequest $request): mixed
    {
        if ($this->catastroDown) {
            return Http::failedConnection();
        }
        $method = basename((string) parse_url($request->url(), PHP_URL_PATH));
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
        $file = match ($method) {
            'ObtenerMunicipios' => 'municipios-valencia-'.Str::lower((string) $q['Municipio']),
            'ObtenerCallejero' => ['VALENCIA|CUBA' => 'callejero-valencia-cuba', 'SAGUNTO / SAGUNT|CASTELLON' => 'callejero-sagunto-castellon', 'GANDIA|MARITIM NEPTU' => 'callejero-gandia-neptu', 'GANDIA|REPUBLICA ARGENTINA' => 'callejero-gandia-argentina', 'BELLREGUARD|MAJOR' => 'callejero-bellreguard-major'][$q['Municipio'].'|'.$q['NomVia']] ?? null,
            'Consulta_DNPLOC' => ['VALENCIA|CUBA|22' => 'dnploc-valencia-cuba22', 'SAGUNTO / SAGUNT|CASTELLON|2' => 'dnploc-sagunto-castellon2', 'GANDIA|MARITIM NEPTU|40' => 'dnploc-gandia-neptu40', 'GANDIA|REPUBLICA ARGENTINA DE LA|30' => 'dnploc-gandia-argentina30', 'BELLREGUARD|MAJOR|10' => 'dnploc-bellreguard-major10'][$q['Municipio'].'|'.$q['Calle'].'|'.$q['Numero']] ?? null,
            'Consulta_DNPRC' => 'dnprc-'.$q['RefCat'],
            'Consulta_CPMRC' => 'cpmrc-'.$q['RefCat'],
            default => null,
        };
        $path = resource_path("valoraciones/pruebas/catastro/{$file}.json");

        return $file !== null && is_file($path)
            ? Http::response((string) file_get_contents($path), 200, ['Content-Type' => 'application/json'])
            : Http::response(['error' => "sin grabación: {$method} ".json_encode($q)], 404);
    }

    /** Simulated GoHighLevel: contacts upsert, opportunities search / create / update / get. */
    private function ghl(HttpRequest $request): mixed
    {
        if ($this->ghlDown) {
            return Http::response(['message' => 'Internal error'], 500);
        }
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
        $body = (array) $request->data();

        return match (true) {
            $request->method() === 'POST' && $path === '/contacts/upsert' => Http::response(['contact' => ['id' => 'CT-'.substr(md5((string) ($body['email'] ?? '')), 0, 8)]]),
            str_ends_with($path, '/customFields') => Http::response(['customFields' => []]),
            str_ends_with($path, '/tags') || str_ends_with($path, '/notes') => Http::response(['ok' => true]),
            $request->method() === 'PUT' && str_starts_with($path, '/contacts/') => Http::response(['contact' => ['id' => basename($path)]]),
            $request->method() === 'GET' && str_starts_with($path, '/contacts/') => Http::response(['contact' => ['id' => basename($path), 'name' => 'Lucía Prueba Ficticia', 'email' => 'lucia.prueba@example.com', 'phone' => '+34600000000']]),
            $request->method() === 'GET' && $path === '/opportunities/search' => Http::response(['opportunities' => array_values(array_filter($this->opportunities, fn (array $o): bool => $o['contactId'] === ($q['contact_id'] ?? '')))]),
            $request->method() === 'POST' && $path === '/opportunities/' => $this->createOpportunity($body),
            $request->method() === 'PUT' && str_starts_with($path, '/opportunities/') => $this->updateOpportunity(basename($path), $body),
            $request->method() === 'GET' && str_starts_with($path, '/opportunities/') => Http::response(['opportunity' => $this->opportunities[basename($path)] ?? []]),
            default => Http::response(['message' => 'no simulado'], 404),
        };
    }

    /** @param array<string, mixed> $body */
    private function createOpportunity(array $body): mixed
    {
        $id = 'OP-'.(++$this->opportunityCreates).'-'.Str::random(6);
        $this->opportunities[$id] = ['id' => $id, 'status' => 'open', 'locationId' => $body['locationId'], 'pipelineId' => $body['pipelineId'], ...$body,
            'customFields' => array_map(fn (array $f): array => ['id' => $f['id'], 'fieldValue' => $f['field_value'], 'field_value' => $f['field_value']], (array) $body['customFields'])];

        return Http::response(['opportunity' => ['id' => $id]], 201);
    }

    /** @param array<string, mixed> $body */
    private function updateOpportunity(string $id, array $body): mixed
    {
        if (! isset($this->opportunities[$id])) {
            return Http::response(['message' => 'not found'], 404);
        }
        $fields = collect($this->opportunities[$id]['customFields'] ?? [])->keyBy('id');   // GHL merges custom fields by id
        foreach ((array) ($body['customFields'] ?? []) as $f) {
            $fields[$f['id']] = ['id' => $f['id'], 'fieldValue' => $f['field_value'], 'field_value' => $f['field_value']];
        }
        $this->opportunities[$id] = [...$this->opportunities[$id], ...$body, 'customFields' => $fields->values()->all()];

        return Http::response(['opportunity' => ['id' => $id]]);
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
            'name' => $name, 'email' => 'prueba-valoraciones-'.bin2hex(random_bytes(5)).'@example.com', 'password' => bcrypt(bin2hex(random_bytes(16))),
            'locale' => 'es', 'email_verified_at' => now(),
        ]));
    }

    private function check(string $data, string $label, bool $ok): void
    {
        $ok ? $this->pass++ : $this->fail++;
        $this->line(($ok ? 'PASS  ' : 'FAIL  ').$data.' '.$label);
    }
}
