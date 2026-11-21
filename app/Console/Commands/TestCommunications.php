<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Communications\Communications;
use App\Filament\Communications\ClientTimeline;
use App\Models\Communication;
use App\Models\WhatsAppLine;
use App\Rentals\Integration\CommunicationLog;
use App\Rentals\Models\Occupancy;
use App\Rentals\Models\RentalUnit;
use App\Rentals\Occupancies;
use App\WhatsApp\WhatsApp;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\RealEstate\Parties\Models\Party;
use Liberu\RealEstate\Properties\Models\Property;
use RuntimeException;
use Throwable;

/**
 * Communications end to end with every provider SIMULATED (WAHA via Http::fake, SMS simulated driver, e-mail to the
 * in-memory "array" mailer): send each channel from a client record, inbound WhatsApp in the same timeline, rentals
 * and signatures writing to the same log, team isolation, consent, failed sends shown as failed. Rolled back.
 *
 *   php artisan clarto:comunicaciones-prueba
 */
final class TestCommunications extends Command
{
    protected $signature = 'clarto:comunicaciones-prueba';

    protected $description = 'Test client communications (WhatsApp, SMS, e-mail, timeline, inbox) with simulated providers (nothing is kept)';

    private int $pass = 0;

    private int $fail = 0;

    private bool $wahaDown = false;

    /** @var list<array<string, mixed>> */
    private array $whatsappSent = [];

    public function handle(): int
    {
        config([
            'whatsapp.url' => 'http://waha.prueba:3000', 'whatsapp.api_key' => 'clave-prueba', 'whatsapp.webhook_hmac_key' => 'hmac-prueba', 'whatsapp.session' => 'default',
            'firma.sms.driver' => 'simulado', 'firma.sms.only_test_destinations' => true, 'firma.sms.test_destinations' => ['34600222333'],
            'mail.default' => 'array',
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
        }

        $this->line(sprintf('RESULT: %d passed, %d failed (WAHA, SMS and e-mail simulated; database rolled back)', $this->pass, $this->fail));

        return $this->fail === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function scenarios(): void
    {
        $team = Team::query()->oldest()->firstOrFail();
        $teamId = (int) $team->getKey();
        $otherTeamId = (int) Team::query()->max('id') + 1000;   // another company (no row needed: isolation is by team_id)
        $actor = (int) ($team->user_id ?? 1);
        DB::table('clarto_whatsapp_links')->where('team_id', $teamId)->delete();
        WhatsAppLine::forTeam($teamId)->update(['status' => WhatsAppLine::WORKING]);

        $make = fn (int $team, string $name, ?string $phone, ?string $email, bool $consent = false): Party => Party::query()->create([
            'team_id' => $team, 'created_by' => $actor, 'type' => 'vendor', 'name' => $name, 'phone' => $phone, 'email' => $email, 'consent_at' => $consent ? now() : null,
        ]);
        $marta = $make($teamId, 'Marta Cliente Ficticia', '600 222 333', 'marta.ficticia@example.test');
        $other = $make($otherTeamId, 'Cliente de Otra Empresa', '600 222 333', 'otra.empresa@example.test');

        // 1. Each channel from the client record.
        $wa = Communications::send($teamId, $marta, 'whatsapp', '600222333', 'Hola Marta, le escribimos por WhatsApp.', ['user_id' => $actor]);
        $sms = Communications::send($teamId, $marta, 'sms', '600222333', 'CLARTO: su visita es mañana a las 10:00.', ['user_id' => $actor]);
        $mail = Communications::send($teamId, $marta, 'email', 'marta.ficticia@example.test', "Hola Marta,\nle enviamos la documentación.", ['subject' => 'Documentación', 'user_id' => $actor]);
        $sentMail = collect(app(MailManager::class)->mailer('array')->getSymfonyTransport()->messages());
        $this->check('WhatsApp desde la ficha: enviado por la línea a 34600222333@c.us', $wa->status === 'enviado' && ($this->whatsappSent[0]['chatId'] ?? '') === '34600222333@c.us');
        $this->check('SMS desde la ficha: enviado (proveedor simulado, número de prueba)', $sms->status === 'enviado' && str_starts_with((string) $sms->provider_id, 'SIM-'));
        $this->check('correo desde la ficha: enviado con el buzón del panel', $mail->status === 'enviado' && $sentMail->count() === 1
            && $sentMail->first()->getOriginalMessage()->getSubject() === 'Documentación');

        $again = Communications::send($teamId, $marta, 'sms', '600222333', 'Repetido', ['key' => 'clave-fija-prueba']);
        $again2 = Communications::send($teamId, $marta, 'sms', '600222333', 'Repetido', ['key' => 'clave-fija-prueba']);
        $this->check('mismo envío repetido: una sola comunicación', $again->is($again2) && Communication::query()->where('idempotency_key', 'clave-fija-prueba')->count() === 1);

        // 2. Failed sends are logged and shown as failed.
        $this->wahaDown = true;
        $failedWa = Communications::send($teamId, $marta, 'whatsapp', '600222333', 'Esto no saldrá');
        $this->wahaDown = false;
        $failedSms = Communications::send($teamId, $marta, 'sms', '611999888', 'Número que no es de prueba');
        $this->check('WhatsApp rechazado por WAHA: «No enviado» con el motivo', $failedWa->status === 'error' && filled($failedWa->error));
        $this->check('SMS a un número que no es de prueba: «No enviado», no sale', $failedSms->status === 'error' && filled($failedSms->error) && $this->whatsappSent !== []);
        $html = view('filament.communications.thread', ['party' => $marta, 'items' => Communications::timeline($marta)])->render();
        $this->check('la ficha muestra los fallos como «No enviado»', substr_count($html, __('Not sent')) >= 2);

        // 3. Inbound WhatsApp lands in the same timeline (and counts as unread).
        $line = WhatsAppLine::forTeam($teamId);
        WhatsApp::handleEvent(['id' => 'evt_'.Str::ulid(), 'event' => 'message', 'session' => $line->session,
            'payload' => ['id' => 'false_34600222333@c.us_'.Str::upper(Str::random(16)), 'from' => '34600222333@c.us', 'fromMe' => false, 'body' => 'Recibido, gracias', 'timestamp' => now()->timestamp]]);
        $timeline = Communications::timeline($marta);
        $this->check('respuesta de WhatsApp en la misma línea de tiempo que SMS y correo',
            $timeline->last()?->direction === Communication::IN && $timeline->pluck('channel')->unique()->sort()->values()->all() === ['email', 'sms', 'whatsapp']);
        $inbox = Communications::inbox($teamId);
        $this->check('bandeja «Conversaciones»: el cliente arriba con 1 sin leer', (int) $inbox->first()?->party_id === $marta->id && (int) $inbox->first()->unread === 1);
        Communications::markRead($marta);
        $this->check('al abrir la conversación queda leída', (int) Communications::inbox($teamId)->first()?->unread === 0);

        // 4. Team isolation.
        $this->check('otra empresa no ve la conversación (ni en su bandeja ni en la ficha de su contacto con el mismo teléfono)',
            Communications::inbox($otherTeamId)->isEmpty() && Communications::timeline($other)->isEmpty());
        $blocked = false;
        try {
            Communications::send($teamId, $other, 'sms', '600222333', 'No');
        } catch (RuntimeException) {
            $blocked = true;
        }
        $this->check('no se puede escribir a un contacto de otra empresa', $blocked);
        $user = new \App\Models\User;
        $user->forceFill(['id' => $actor, 'current_team_id' => $teamId]);
        auth()->setUser($user);
        $hidden = false;
        try {
            $c = new ClientTimeline;
            $c->partyId = $other->id;
            $c->party();
        } catch (ModelNotFoundException) {
            $hidden = true;
        }
        $this->check('la ficha de comunicaciones de otra empresa no se abre (404)', $hidden);

        // 5. Purpose and consent.
        $refused = false;
        try {
            Communications::send($teamId, $marta, 'email', 'marta.ficticia@example.test', 'Nueva promoción', ['subject' => 'Oferta', 'purpose' => 'marketing']);
        } catch (RuntimeException) {
            $refused = true;
        }
        $marta->forceFill(['consent_at' => now()])->save();
        $ok = Communications::send($teamId, $marta, 'email', 'marta.ficticia@example.test', 'Nueva promoción', ['subject' => 'Oferta', 'purpose' => 'marketing']);
        $this->check('comercial sin consentimiento: rechazado; con consentimiento: enviado', $refused && $ok->status === 'enviado' && $ok->purpose === 'marketing');

        // 6. Other modules write to the same log: rentals, signatures, system e-mails.
        $property = Property::query()->create(['team_id' => $teamId, 'created_by' => $actor, 'address' => 'Calle Ficticia 1', 'title' => 'Calle Ficticia 1', 'property_type' => 'apartment', 'status' => 'available', 'deal_type' => 'rent']);
        $unit = RentalUnit::query()->create(['team_id' => $teamId, 'property_id' => $property->id, 'kind' => 'vivienda', 'name' => 'Vivienda', 'max_occupants' => 2]);
        $occupancy = Occupancies::book(['unit_id' => $unit->id, 'module' => 'tradicional', 'kind' => 'contrato', 'purpose' => 'habitual', 'planned_start' => '2027-01-01', 'planned_end' => '2032-01-01', 'occupants' => 1, 'rent_amount' => 900, 'rent_period' => 'mes', 'due_day' => 5],
            [['party_id' => $marta->id, 'role' => 'arrendatario', 'name' => $marta->name, 'phone' => '+34600222333']]);
        app(CommunicationLog::class)->log($occupancy, 'sms', 'cobro', '+34600222333', 'Recibo', 'CLARTO: recibo de enero pendiente.');
        $rental = Communication::query()->where('source', 'alquileres')->latest('id')->first();
        $this->check('Alquileres envía por el canal real y queda en la ficha del inquilino',
            $rental?->party_id === $marta->id && $rental->status === 'enviado' && $rental->related_type === (new Occupancy)->getMorphClass() && $occupancy->events()->where('type', 'comunicacion')->exists());

        Communications::record($teamId, 'sms', Communication::OUT, ['phone' => '+34600222333', 'body' => 'CLARTO: tiene un documento para firmar "Encargo": [enlace de firma]', 'purpose' => 'firma', 'source' => 'firma']);
        Mail::raw('Su documento está listo para firmar.', fn ($m) => $m->to('marta.ficticia@example.test')->subject('Documento para firmar'));
        $sources = Communications::timeline($marta)->pluck('source')->all();
        $system = Communication::query()->where('source', 'sistema')->where('party_id', $marta->id)->latest('id')->first();
        $this->check('SMS de firma y correo del sistema en la ficha (correo del sistema: solo el asunto)',
            in_array('firma', $sources, true) && $system?->subject === 'Documento para firmar' && ! str_contains((string) $system->body, 'listo para firmar'));
        $this->check('el correo enviado desde la ficha no se registra dos veces', Communication::query()->where('party_id', $marta->id)->where('subject', 'Documentación')->count() === 1);
    }

    private function fakeWaha(): void
    {
        Http::preventStrayRequests();
        Http::fake(function (ClientRequest $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if (! str_starts_with($request->url(), 'http://waha.prueba:3000') || ! $request->hasHeader('X-Api-Key', 'clave-prueba')) {
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
