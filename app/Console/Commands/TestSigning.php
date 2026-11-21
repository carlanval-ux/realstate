<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Signing\FormTemplate;
use App\Signing\Signing;
use App\Signing\SigningFailed;
use App\Signing\SignatureEvent;
use App\Signing\SignatureRequest;
use App\Signing\SmsMessage;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\RealEstate\Instructions\Models\Instruction;
use Liberu\RealEstate\Parties\Models\Party;
use Liberu\RealEstate\Properties\Models\Property;
use Throwable;

/**
 * Formularios + signing end to end with SIMULATED DocuSeal and dealer-SMS answers (Http::fake), webhooks through the
 * real HTTP route. Fictitious owner and property; database rolled back; files on a throw-away disk.
 *
 *   php artisan clarto:firma-prueba
 */
final class TestSigning extends Command
{
    protected $signature = 'clarto:firma-prueba';

    protected $description = 'Test Formularios and signing with simulated DocuSeal and SMS answers (nothing is kept)';

    private const SECRET = 'whsec_prueba';

    private int $pass = 0;

    private int $fail = 0;

    public function handle(): int
    {
        config([
            'firma.driver' => 'docuseal', 'firma.disk' => 'firma-prueba',
            'firma.docuseal.environment' => 'sandbox', 'firma.docuseal.api_key' => 'ds-prueba', 'firma.docuseal.webhook_secret' => self::SECRET,
            'firma.sms.driver' => 'redtelecom', 'firma.sms.username' => 'usuario-prueba', 'firma.sms.password' => 'clave-prueba', 'firma.sms.sender' => 'CLARTO',
            'firma.sms.only_test_destinations' => true, 'firma.sms.test_destinations' => ['34600000001'],
        ]);
        Storage::fake('firma-prueba');

        DB::beginTransaction();
        try {
            $this->scenarios();
        } catch (Throwable $e) {
            $this->check('sin excepciones: '.get_class($e).': '.$e->getMessage(), false);
        } finally {
            DB::rollBack();
            File::deleteDirectory(storage_path('framework/testing/disks/firma-prueba'));
        }

        $this->line(sprintf('RESULT: %d passed, %d failed (DocuSeal and SMS simulated with Http::fake; database rolled back)', $this->pass, $this->fail));

        return $this->fail === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function scenarios(): void
    {
        // ── DocuSeal and dealer SMS, simulated ──────────────────────────────────────────────────────────────────
        $state = ['opened' => false, 'completed' => false];
        $calls = ['create' => 0, 'download' => 0, 'sms' => 0];
        $sms = [];
        $created = [];
        Http::preventStrayRequests();
        Http::fake(function (HttpRequest $r) use (&$state, &$calls, &$sms, &$created) {
            $url = $r->url();
            if (str_starts_with($url, 'https://sms.redtelecom.es/')) {
                if (! $r->hasHeader('Authorization', 'Basic '.base64_encode('usuario-prueba:clave-prueba'))) {
                    return Http::response(['error' => ['code' => 103, 'description' => 'Username or password unknown']], 401);
                }
                $calls['sms']++;
                $sms[] = $r->data();

                return Http::response([['accepted' => true, 'to' => $r->data()['to'][0], 'id' => (string) (770 + $calls['sms'])]], 202);
            }
            if (preg_match('#^https://docuseal\.com/file/(signed|audit)\.pdf$#', $url, $m)) {
                $calls['download']++;

                return $r->hasHeader('X-Auth-Token') ? Http::response('token filtrado', 400) : Http::response('%PDF-1.4 '.$m[1].' ficticio', 200, ['Content-Type' => 'application/pdf']);
            }
            if (! str_starts_with($url, 'https://api.docuseal.com/') || ! $r->hasHeader('X-Auth-Token', 'ds-prueba')) {
                return Http::response(['error' => 'unauthorized'], 401);
            }
            $path = parse_url($url, PHP_URL_PATH);
            if ($r->method() === 'GET' && $path === '/submitters') {
                return Http::response(['data' => [], 'pagination' => ['next' => null]]);
            }
            if ($r->method() === 'POST' && $path === '/submissions') {
                $calls['create']++;
                $created = $r->data();

                return Http::response(array_map(fn (array $s, int $i) => [
                    'id' => 501 + $i, 'submission_id' => 9001, 'external_id' => $s['external_id'], 'role' => $s['role'],
                    'embed_src' => 'https://docuseal.com/s/firma'.$i.'abcdef', 'status' => 'sent',
                ], $created['submitters'], array_keys($created['submitters'])));
            }
            if ($r->method() === 'GET' && $path === '/submissions/9001') {
                $sub = fn (int $id, int $i) => [
                    'id' => $id, 'external_id' => $created['submitters'][$i]['external_id'] ?? null, 'status' => $state['completed'] ? 'completed' : ($state['opened'] && $i === 0 ? 'opened' : 'sent'),
                    'sent_at' => '2026-11-21T10:00:00Z', 'opened_at' => $state['opened'] && $i === 0 ? '2026-11-21T10:05:00Z' : null,
                    'completed_at' => $state['completed'] ? '2026-11-21T10:10:00Z' : null, 'declined_at' => null,
                ];

                return Http::response([
                    'id' => 9001, 'status' => $state['completed'] ? 'completed' : 'pending', 'completed_at' => $state['completed'] ? '2026-11-21T10:10:00Z' : null,
                    'audit_log_url' => $state['completed'] ? 'https://docuseal.com/file/audit.pdf' : null,
                    'submitters' => [$sub(501, 0), $sub(502, 1)],
                    'submission_events' => $state['completed'] ? [['submitter_id' => 501, 'event_type' => 'phone_verified', 'event_timestamp' => '2026-11-21T10:08:00Z']] : [],
                ]);
            }
            if ($r->method() === 'GET' && $path === '/submissions/9001/documents') {
                return Http::response(['id' => 9001, 'documents' => [['name' => 'Hoja de encargo', 'url' => 'https://docuseal.com/file/signed.pdf']]]);
            }

            return Http::response(['error' => 'not found'], 404);
        });

        // ── Fictitious owner, property and mandate ──────────────────────────────────────────────────────────────
        $team = Team::query()->oldest()->firstOrFail();
        $actor = (int) ($team->user_id ?? 1);
        $party = new Party;
        $party->forceFill(['team_id' => $team->getKey(), 'created_by' => $actor, 'type' => 'vendor', 'name' => 'Propietaria Ficticia Prueba',
            'email' => 'propietaria.ficticia@example.com', 'phone' => '600000001', 'metadata' => ['documento' => '00000000T']])->save();
        $property = new Property;
        $property->forceFill(['team_id' => $team->getKey(), 'created_by' => $actor, 'status' => 'draft', 'deal_type' => 'sale', 'property_type' => 'apartment',
            'address' => 'Calle Ficticia 12, 3º, 46520 Sagunto', 'postal_code' => '46520', 'area_sqft' => 96, 'price' => 185000, 'country' => 'ES', 'currency' => 'EUR'])->save();
        $instruction = new Instruction;
        $instruction->forceFill(['team_id' => $team->getKey(), 'created_by' => $actor, 'property_id' => $property->getKey(), 'party_id' => $party->getKey(),
            'subject' => 'Encargo de venta · prueba', 'status' => 'draft'])->save();

        // ── Catalogue: the fictitious template, a new version with a sandbox template id ───────────────────────
        $seed = FormTemplate::query()->where('key', 'prueba-hoja-de-encargo')->orderBy('version')->first();
        $this->check('plantilla ficticia sembrada', $seed !== null && $seed->fictitious);
        if ($seed === null) {
            return;
        }
        $template = $seed->newVersion();
        $template->forceFill(['status' => 'activo', 'docuseal_template_ids' => ['sandbox' => 1000001, 'production' => null]])->save();
        $this->check('nueva versión en borrador con número siguiente', $template->version > $seed->version);

        // ── Prefill from the 360 data, missing fields, preview ─────────────────────────────────────────────────
        $ctx = Signing::context(null, $property, null);
        ['values' => $values, 'missing' => $missing] = Signing::prefill($template, $ctx);
        $this->check('desde el inmueble: propietario y encargo deducidos', $ctx['_models']['party']?->is($party) && $ctx['_models']['instruction']?->is($instruction));
        $this->check('campos rellenados (nombre, DNI, referencia, precio 185.000, venta)', $values['propietario_nombre'] === 'Propietaria Ficticia Prueba' && $values['propietario_documento'] === '00000000T'
            && $values['inmueble_referencia'] === $property->reference() && $values['precio'] === '185.000' && $values['operacion'] === 'venta' && $missing === []);
        $partial = Signing::prefill($template, Signing::context(null, null, null));
        $this->check('sin propietario ni inmueble: faltan los campos obligatorios', in_array('Nombre del propietario', $partial['missing'], true) && in_array('Precio orientativo (€)', $partial['missing'], true));
        $pdf = Signing::documentPdf($template, $values, 'Hoja de encargo');
        $this->check('vista previa: PDF determinista con los datos', str_starts_with($pdf, '%PDF') && $pdf === Signing::documentPdf($template, $values, 'Hoja de encargo')
            && str_contains($pdf, 'Propietaria Ficticia Prueba'));

        $signing = Signing::make();
        $signers = [
            ['role' => 'propietario', 'name' => 'Propietaria Ficticia Prueba', 'email' => 'propietaria.ficticia@example.com', 'phone' => '600 000 001', 'require_phone_2fa' => true],
            ['role' => 'agencia', 'name' => 'Agente Ficticio', 'email' => 'agente@example.com', 'phone' => '611111111'],
        ];
        $input = ['template_id' => $template->getKey(), 'title' => 'Hoja de encargo', 'property_id' => $property->getKey(), 'values' => $values, 'signers' => $signers];

        try {
            $signing->send(['values' => array_merge($values, ['precio' => null])] + $input);
            $this->check('envío con datos obligatorios vacíos: rechazado', false);
        } catch (SigningFailed $e) {
            $this->check('envío con datos obligatorios vacíos: rechazado', str_contains($e->getMessage(), 'Precio') && $calls['create'] === 0);
        }
        $advanced = $template->replicate(['used_at']);
        $advanced->forceFill(['version' => $template->version + 1, 'signature_level' => 'avanzada'])->save();
        try {
            $signing->send(['template_id' => $advanced->getKey()] + $input);
            $this->check('firma avanzada en sandbox (docuseal.com): rechazada', false);
        } catch (SigningFailed) {
            $this->check('firma avanzada en sandbox (docuseal.com): rechazada', $calls['create'] === 0);
        }

        // ── Send ────────────────────────────────────────────────────────────────────────────────────────────────
        $request = $signing->send($input, $actor);
        $this->check('enviada a DocuSeal con plantilla, roles, valores y SMS-OTP del propietario', $request->status === 'enviada' && $request->provider_submission_id === '9001'
            && ($created['template_id'] ?? null) === 1000001 && ($created['submitters'][0]['role'] ?? null) === 'Propietario'
            && ($created['submitters'][0]['values']['Precio'] ?? null) === '185.000' && ($created['submitters'][0]['require_phone_2fa'] ?? false) === true
            && ($created['submitters'][0]['phone'] ?? null) === '+34600000001' && ! isset($created['submitters'][1]['require_phone_2fa']));
        $this->check('versión exacta guardada (plantilla, valores, hash del PDF)', $request->template_version === $template->version && $request->values === $values
            && $request->document_sha256 === hash('sha256', $pdf) && Storage::disk('firma-prueba')->get($request->document_path) === $pdf && $request->party_id === $party->getKey());
        $raw = DB::table('clarto_signature_signers')->where('signature_request_id', $request->getKey())->orderBy('position')->first();
        $this->check('firmantes con estado y enlace cifrado en la base de datos', $request->signers->pluck('status')->all() === ['enviado', 'enviado']
            && $request->signers[0]->sign_url === 'https://docuseal.com/s/firma0abcdef' && ! str_contains((string) $raw->sign_url, 'docuseal.com'));
        try {
            $template->refresh()->forceFill(['name' => 'Cambio'])->save();
            $this->check('versión usada inmutable', false);
        } catch (ValidationException) {
            $template->refresh();
            $template->forceFill(['status' => 'retirado'])->save();
            $this->check('versión usada inmutable (solo cambia el estado)', $template->refresh()->status === 'retirado' && $template->name !== 'Cambio');
        }

        // ── SMS: link, manual reminder, non-test destination ────────────────────────────────────────────────────
        [$owner, $agent] = [$request->signers[0], $request->signers[1]];
        $m1 = $signing->sendSms($owner, 'enlace', $actor);
        $m2 = $signing->sendSms($owner, 'recordatorio', $actor);
        $this->check('SMS con el enlace y recordatorio manual por la API del dealer', $m1->status === 'aceptado' && $m2->status === 'aceptado' && $m1->provider_message_id === '771'
            && $calls['sms'] === 2 && ($sms[0]['to'] ?? null) === ['34600000001'] && str_contains($sms[0]['text'], 'https://docuseal.com/s/firma0abcdef')
            && strlen($sms[0]['text']) <= 160 && ($sms[0]['from'] ?? null) === 'CLARTO' && str_contains($sms[1]['text'], 'recordatorio'));
        $this->check('el enlace no queda en el registro del SMS', ! str_contains($m1->text, 'docuseal.com') && SmsMessage::query()->where('signature_request_id', $request->getKey())->count() === 2);
        $m3 = $signing->sendSms($agent, 'recordatorio', $actor);
        $this->check('destino que no es de prueba: bloqueado, no se envía', $m3->status === 'bloqueado' && $calls['sms'] === 2);
        $this->check('un SMS nuestro no verifica nada ni sube el nivel', $owner->refresh()->otp_verified_at === null && $request->refresh()->signature_level === 'simple');

        // ── Webhooks through the real route ─────────────────────────────────────────────────────────────────────
        $kernel = app(Kernel::class);
        $hook = function (array $event, ?int $ts = null, ?string $secret = null) use ($kernel) {
            $body = (string) json_encode($event);
            $ts ??= time();
            $sig = $ts.'.'.hash_hmac('sha256', $ts.'.'.$body, $secret ?? self::SECRET);

            return $kernel->handle(Request::create('/api/v1/firma/docuseal', 'POST', [], [], [], [
                'HTTP_X_DOCUSEAL_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            ], $body));
        };
        $viewed = ['event_type' => 'form.viewed', 'timestamp' => '2026-11-21T10:05:00Z', 'data' => ['id' => 501, 'submission_id' => 9001, 'external_id' => $owner->external_id]];

        $this->check('webhook con firma incorrecta: 401', $hook($viewed, secret: 'otro')->getStatusCode() === 401);
        $this->check('webhook fuera de la ventana de 300 s: 401', $hook($viewed, time() - 600)->getStatusCode() === 401);
        $this->check('webhook de otra solicitud: 200 huérfano', str_contains((string) $hook(['event_type' => 'form.viewed', 'timestamp' => 'x', 'data' => ['id' => 1, 'submission_id' => 1]])->getContent(), 'huerfano'));

        $state['opened'] = true;
        $r = $hook($viewed);
        $this->check('form.viewed: conciliado con GET, propietario «visto», solicitud en curso', $r->getStatusCode() === 200 && $owner->refresh()->status === 'visto'
            && $owner->viewed_at !== null && $request->refresh()->status === 'en_curso' && $agent->refresh()->status === 'enviado');

        $state['completed'] = true;
        $completed = ['event_type' => 'submission.completed', 'timestamp' => '2026-11-21T10:10:00Z', 'data' => ['id' => 9001, 'status' => 'completed']];
        $r = $hook($completed);
        $request->refresh();
        $docs = $request->documents()->orderBy('kind')->get();
        $this->check('submission.completed: solicitud completada y firmantes firmados', $r->getStatusCode() === 200 && $request->status === 'completada' && $request->completed_at !== null
            && $request->signers()->pluck('status')->unique()->all() === ['firmado']);
        $this->check('PDF firmado y registro de auditoría guardados en privado con su sha256', $docs->pluck('kind')->all() === ['auditoria', 'firmado']
            && $docs->every(fn ($d) => Storage::disk('firma-prueba')->exists($d->path) && hash('sha256', Storage::disk('firma-prueba')->get($d->path)) === $d->sha256)
            && $calls['download'] === 2);
        $this->check('código SMS registrado solo porque DocuSeal lo confirma', $owner->refresh()->otp_verified_at !== null && $owner->otp_channel === 'sms' && $agent->refresh()->otp_verified_at === null);

        $before = [SignatureEvent::query()->count(), $calls['download'], $calls['sms'], $calls['create'], $request->documents()->count()];
        $r1 = $hook($completed);
        $r2 = $hook($completed);
        $this->check('webhook duplicado: no duplica documentos, descargas, envíos ni SMS', str_contains((string) $r1->getContent(), 'duplicado') && str_contains((string) $r2->getContent(), 'duplicado')
            && [SignatureEvent::query()->count(), $calls['download'], $calls['sms'], $calls['create'], $request->documents()->count()] === $before);
        $formDone = ['event_type' => 'form.completed', 'timestamp' => '2026-11-21T10:09:00Z', 'data' => ['id' => 502, 'submission_id' => 9001]];
        $hook($formDone);
        $this->check('otro evento del mismo envío: no vuelve a guardar los documentos', $request->documents()->count() === 2 && $calls['download'] === 2);
        $hook(['event_type' => 'form.viewed', 'timestamp' => '2026-11-21T10:11:00Z', 'data' => ['id' => 501, 'submission_id' => 9001]]);
        $this->check('evento tardío fuera de orden: no retrocede el estado', $request->refresh()->status === 'completada' && $owner->refresh()->status === 'firmado');

        try {
            $signing->sendSms($owner, 'recordatorio', $actor);
            $this->check('recordatorio tras la firma: rechazado', false);
        } catch (SigningFailed) {
            $this->check('recordatorio tras la firma: rechazado', $calls['sms'] === 2);
        }
        $this->check('registro de SMS: enlace, recordatorio y bloqueado', SmsMessage::query()->where('signature_request_id', $request->getKey())->pluck('kind')->sort()->values()->all() === ['enlace', 'recordatorio', 'recordatorio']);
        $this->check('solicitud consultable por inmueble y propietario', SignatureRequest::query()->where('property_id', $property->getKey())->where('party_id', $party->getKey())->count() === 1);
    }

    private function check(string $label, bool $ok): void
    {
        $ok ? $this->pass++ : $this->fail++;
        $this->line(($ok ? 'PASS  ' : 'FAIL  ').$label);
    }
}
