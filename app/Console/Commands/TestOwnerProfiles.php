<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Acquisition\GhlHandoff;
use App\Models\User;
use App\Owners\OwnerProfile;
use App\Owners\SpanishIds;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\RealEstate\Instructions\Models\Instruction;
use Liberu\RealEstate\Parties\Application\CreateParty;
use Liberu\RealEstate\Parties\Models\Party;
use Liberu\RealEstate\Properties\Models\Property;

/**
 * Propietarios: GHL won opportunity → owner with 360 profile, same owner → same profile, ambiguous identity →
 * pending linking → linked by a person. SIMULATED GoHighLevel (Http::fake) through the real webhook route, in a
 * rolled-back transaction: nothing is kept.
 *
 *   php artisan clarto:propietarios-prueba
 */
final class TestOwnerProfiles extends Command
{
    protected $signature = 'clarto:propietarios-prueba';

    protected $description = 'Test owner profiles and pending linking with simulated GHL answers (nothing is kept)';

    private int $pass = 0;

    private int $fail = 0;

    public function handle(): int
    {
        config(['ghl.token' => 'pit-prueba', 'ghl.location_id' => 'LOC-CLARTO', 'ghl.pipeline_id' => 'PIPE-CAPTACION', 'ghl.webhook_secret' => 'secreto-webhook',
            'ghl.api_url' => 'https://services.leadconnectorhq.com',
            'ghl.fields' => ['operacion' => 'f-op', 'tipo_inmueble' => 'f-tipo', 'municipio' => 'f-mun', 'codigo_postal' => 'f-cp', 'direccion' => 'f-dir', 'superficie_m2' => 'f-m2'],
            'ghl.contact_fields' => ['nif' => 'cf-nif', 'idioma' => 'cf-idioma', 'canal_preferido' => 'cf-canal']]);

        $this->validators();

        DB::beginTransaction();
        try {
            $this->scenarios();
        } finally {
            DB::rollBack();
        }

        $this->line(sprintf('RESULT: %d passed, %d failed (GoHighLevel simulated with Http::fake; database rolled back)', $this->pass, $this->fail));

        return $this->fail === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function validators(): void
    {
        $this->check('NIF, NIE y CIF válidos (12345678Z, X1234567L, B12345674)', SpanishIds::taxIdType('12345678-z') === 'nif' && SpanishIds::taxIdType('X1234567L') === 'nie' && SpanishIds::taxIdType('B12345674') === 'cif');
        $this->check('NIF/CIF con dígito de control erróneo: rechazados', SpanishIds::taxIdType('12345678A') === null && SpanishIds::taxIdType('B12345675') === null);
        $this->check('IBAN: control válido e inválido', SpanishIds::isValidIban('ES91 2100 0418 4502 0005 1332') && ! SpanishIds::isValidIban('ES9121000418450200051333'));
        $masked = (string) SpanishIds::maskIban('ES9121000418450200051332');
        $this->check('IBAN y NIF enmascarados (solo los 4 últimos)', str_ends_with($masked, '1332') && ! str_contains($masked, '0418') && SpanishIds::maskTaxId('12345678Z') === '•••••678Z');
        $this->check('pasaporte (5–20) y otro documento (2–40) según CRM001', SpanishIds::isValidId('passport', 'AB123456') && ! SpanishIds::isValidId('passport', 'AB1')
            && SpanishIds::isValidId('other', 'X9') && ! SpanishIds::isValidId('other', str_repeat('A', 41)) && ! SpanishIds::isValidId('nie', '12345678Z'));
        $this->check('correo, teléfono (6–15 cifras) y BIC según CRM001', SpanishIds::isValidEmail('Ana@Example.com') && ! SpanishIds::isValidEmail('ana@example')
            && SpanishIds::isValidPhone('+34 611 00 00 01') && ! SpanishIds::isValidPhone('12345') && SpanishIds::isValidBic('CAIXESBBXXX') && ! SpanishIds::isValidBic('CAIX'));
        $this->check('código postal → provincia (46500 → Valencia; 99999 no existe)', SpanishIds::provinceForPostalCode('46500') === 'Valencia/València' && SpanishIds::provinceForPostalCode('99999') === null);
    }

    private function scenarios(): void
    {
        $team = Team::query()->oldest()->firstOrFail();
        $user = User::query()->oldest('id')->first();
        $opp = fn (string $id, string $status, string $contact, string $municipio) => [
            'id' => $id, 'status' => $status, 'contactId' => $contact, 'locationId' => 'LOC-CLARTO', 'pipelineId' => 'PIPE-CAPTACION',
            'customFields' => [['id' => 'f-op', 'fieldValue' => 'venta'], ['id' => 'f-tipo', 'fieldValue' => 'piso'], ['id' => 'f-mun', 'fieldValue' => $municipio],
                ['id' => 'f-cp', 'fieldValue' => '46520'], ['id' => 'f-dir', 'fieldValue' => 'Calle Ficticia 1'], ['id' => 'f-m2', 'fieldValue' => '90']],
        ];
        $opportunities = [
            'OPP-P-ABIERTA' => $opp('OPP-P-ABIERTA', 'open', 'CON-PA', 'Sagunto'),
            'OPP-P-PERDIDA' => $opp('OPP-P-PERDIDA', 'lost', 'CON-PA', 'Sagunto'),
            'OPP-P-GANADA-1' => $opp('OPP-P-GANADA-1', 'won', 'CON-PA', 'Sagunto'),
            'OPP-P-GANADA-2' => $opp('OPP-P-GANADA-2', 'won', 'CON-PA', 'Valencia'),
            'OPP-P-DUDOSA' => $opp('OPP-P-DUDOSA', 'won', 'CON-PB', 'Puçol'),
            'OPP-P-NOMBRE' => $opp('OPP-P-NOMBRE', 'won', 'CON-PC', 'Burjassot'),
            'OPP-P-NOMBRE-2' => $opp('OPP-P-NOMBRE-2', 'won', 'CON-PC', 'Paterna'),
            'OPP-P-NIF' => $opp('OPP-P-NIF', 'won', 'CON-PD', 'Godella'),
            'OPP-P-NIF-OTRO' => $opp('OPP-P-NIF-OTRO', 'won', 'CON-PE', 'Alboraia'),
        ];
        $contacts = [
            'CON-PA' => ['id' => 'CON-PA', 'firstName' => 'Ana', 'lastName' => 'Ficticia Prueba', 'name' => 'Ana Ficticia Prueba', 'email' => 'ana.ficticia@example.com',
                'phone' => '+34 611 000 001', 'address1' => 'Avenida Inventada 3', 'city' => 'Sagunto', 'state' => 'Valencia', 'postalCode' => '46500', 'country' => 'ES',
                'tags' => ['captacion-web'], 'customFields' => [['id' => 'cf-nif', 'value' => '12345678-Z'], ['id' => 'cf-idioma', 'value' => 'es'], ['id' => 'cf-canal', 'value' => 'whatsapp']]],
            'CON-PB' => ['id' => 'CON-PB', 'name' => 'Bruno Ficticio', 'email' => 'bruno.ficticio@example.com', 'phone' => '+34 622 000 002'],
            'CON-PC' => ['id' => 'CON-PC', 'name' => 'Carmen Ficticia', 'email' => 'carmen.ficticia@example.com', 'phone' => '+34 633 000 003'],
            'CON-PD' => ['id' => 'CON-PD', 'name' => 'Diego Ficticio', 'email' => 'diego.nuevo@example.com', 'phone' => '+34 644 000 004',
                'customFields' => [['id' => 'cf-nif', 'value' => 'X1234567L']]],
            'CON-PE' => ['id' => 'CON-PE', 'name' => 'Elena Ficticia', 'email' => 'elena.ficticia@example.com', 'customFields' => [['id' => 'cf-nif', 'value' => 'Z1234567R']]],
        ];
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($opportunities, $contacts) {
            if (! $request->hasHeader('Authorization', 'Bearer pit-prueba')) {
                return Http::response(['message' => 'unauthorized'], 401);
            }
            if (preg_match('#/opportunities/([A-Z0-9-]+)$#', $request->url(), $m)) {
                return isset($opportunities[$m[1]]) ? Http::response(['opportunity' => $opportunities[$m[1]]]) : Http::response([], 404);
            }
            if (preg_match('#/contacts/([A-Z0-9-]+)$#', $request->url(), $m)) {
                return isset($contacts[$m[1]]) ? Http::response(['contact' => $contacts[$m[1]]]) : Http::response([], 404);
            }

            return Http::response([], 404);
        });

        $kernel = app(Kernel::class);
        $post = fn (string $id): array => (array) json_decode((string) $kernel->handle(Request::create('/api/v1/ghl/oportunidad-ganada', 'POST', [], [], [], [
            'HTTP_X_CLARTO_WEBHOOK_SECRET' => 'secreto-webhook', 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], json_encode(['opportunity_id' => $id])))->getContent(), true);
        $counts = fn (): array => [Party::query()->count(), OwnerProfile::query()->count(), Property::query()->count(), Instruction::query()->count(), GhlHandoff::query()->count()];

        // Open / lost: nothing.
        $before = $counts();
        $r1 = $post('OPP-P-ABIERTA');
        $r2 = $post('OPP-P-PERDIDA');
        $this->check('abierta / perdida: ni propietario ni ficha ni inmueble', ($r1['estado'] ?? '') === 'ignorado' && ($r2['estado'] ?? '') === 'ignorado'
            && array_slice($counts(), 0, 4) === array_slice($before, 0, 4));

        // Won: owner + 360 profile prefilled from GHL.
        $before = $counts();
        $r = $post('OPP-P-GANADA-1');
        $h1 = GhlHandoff::query()->where('opportunity_id', 'OPP-P-GANADA-1')->first();
        $owner = Party::query()->find($h1?->party_id);
        $profile = $owner ? OwnerProfile::query()->where('party_id', $owner->getKey())->first() : null;
        $this->check('ganada: propietario con ficha 360, inmueble y encargo', ($r['estado'] ?? '') === 'procesado' && $profile !== null
            && $counts() === [$before[0] + 1, $before[1] + 1, $before[2] + 1, $before[3] + 1, $before[4] + 1]);
        $this->check('ficha prefijada desde GHL (nombre, NIF normalizado, dirección fiscal, canal, idioma, etiquetas)', $profile?->first_name === 'Ana' && $profile->last_name === 'Ficticia Prueba'
            && $profile->tax_id === '12345678Z' && $profile->fiscal_postal_code === '46500' && $profile->fiscal_city === 'Sagunto'
            && $profile->preferred_channel === 'whatsapp' && $profile->preferred_language === 'es' && $profile->tags === ['captacion-web'] && $profile->source === 'ghl'
            && $profile->tax_id_type === 'nif' && $profile->fiscal_province === 'Valencia/València' && $profile->code() === 'PR-'.str_pad((string) $profile->getKey(), 6, '0', STR_PAD_LEFT));
        $this->check('posibles duplicados (CRM001): mismo teléfono escrito de otra forma, mismo NIF', $owner !== null
            && OwnerProfile::duplicates($team->getKey(), null, '611000001', null)->contains('id', $owner->getKey())
            && OwnerProfile::duplicates($team->getKey(), null, null, '12345678z')->contains('id', $owner->getKey())
            && OwnerProfile::duplicates($team->getKey(), 'nadie@example.com', '699999999', null)->isEmpty());
        $this->check('ficha: pendientes de completar (IBAN, consentimiento RGPD)', $profile !== null && in_array('iban', $profile->missingFields(), true) && in_array('privacy_consent_at', $profile->missingFields(), true)
            && ! in_array('tax_id', $profile->missingFields(), true));
        $this->check('sin permiso: NIF enmascarado', OwnerProfile::canSeeSensitive() === false && $profile?->displayTaxId() === '•••••678Z');

        // Repeated webhook: nothing new.
        $before = $counts();
        $r = $post('OPP-P-GANADA-1');
        $this->check('webhook repetido: sin duplicados', ($r['estado'] ?? '') === 'duplicado' && $counts() === $before);

        // Same owner, second property: same profile.
        $r = $post('OPP-P-GANADA-2');
        $h2 = GhlHandoff::query()->where('opportunity_id', 'OPP-P-GANADA-2')->first();
        $this->check('mismo propietario, segundo inmueble: misma ficha', ($r['estado'] ?? '') === 'procesado' && $h2?->party_id === $h1?->party_id
            && $counts() === [$before[0], $before[1], $before[2] + 1, $before[3] + 1, $before[4] + 1]
            && OwnerProfile::query()->where('party_id', $owner?->getKey())->count() === 1
            && OwnerProfile::propertyIds($owner)->sort()->values()->all() === collect([$h1?->property_id, $h2?->property_id])->map(fn ($v) => (int) $v)->sort()->values()->all());

        // Ambiguous: e-mail matches one party and phone another → pending, nothing attached.
        $create = fn (string $name, ?string $email, ?string $phone) => app(CreateParty::class)->handle($team->getKey(), (int) ($user?->getKey() ?? 1), ['type' => 'vendor', 'name' => $name, 'email' => $email, 'phone' => $phone]);
        $byEmail = $create('Bruno Ficticio', 'bruno.ficticio@example.com', null);
        $byPhone = $create('Bruno Ficticio Otro', null, '622000002');
        $before = $counts();
        $r = $post('OPP-P-DUDOSA');
        $hb = GhlHandoff::query()->where('opportunity_id', 'OPP-P-DUDOSA')->first();
        $this->check('identidad dudosa (e-mail de uno, teléfono de otro): pendiente de vinculación', ($r['estado'] ?? '') === 'pendiente' && $hb?->party_id === null
            && $hb->candidate_party_ids === [$byEmail->getKey(), $byPhone->getKey()] && $counts() === [$before[0], $before[1], $before[2] + 1, $before[3] + 1, $before[4] + 1]
            && Instruction::query()->find($hb->instruction_id)?->party_id === null);
        $this->check('cola de pendientes del equipo', GhlHandoff::pendingForTeam($team->getKey())->whereKey($hb?->getKey())->exists());
        $before = $counts();
        $r = $post('OPP-P-DUDOSA');
        $this->check('pendiente + webhook repetido: sin duplicados', ($r['estado'] ?? '') === 'duplicado' && $counts() === $before);

        $linked = $hb?->link($byEmail, $user);
        $hb?->refresh();
        $this->check('vinculación por una persona: inmueble y encargo pasan al propietario elegido', $linked?->is($byEmail) && $hb->status === 'procesado'
            && Instruction::query()->find($hb->instruction_id)?->party_id === $byEmail->getKey() && OwnerProfile::propertyIds($byEmail)->contains((int) $hb->property_id)
            && $hb->linked_at !== null && ($byEmail->refresh()->metadata['ghl_contact_id'] ?? null) === 'CON-PB' && OwnerProfile::query()->where('party_id', $byEmail->getKey())->exists()
            && ! GhlHandoff::pendingForTeam($team->getKey())->whereKey($hb->getKey())->exists());
        $again = false;
        try {
            $hb?->link($byPhone, $user);
        } catch (\RuntimeException) {
            $again = true;
        }
        $this->check('una vinculación hecha no se puede repetir', $again && Instruction::query()->find($hb?->instruction_id)?->party_id === $byEmail->getKey());

        // Same e-mail, different name → pending; the person confirms a NEW owner; later opportunities link to it.
        $other = $create('Persona Distinta', 'carmen.ficticia@example.com', null);
        $r = $post('OPP-P-NOMBRE');
        $hc = GhlHandoff::query()->where('opportunity_id', 'OPP-P-NOMBRE')->first();
        $this->check('coincidencia con nombre distinto: pendiente', ($r['estado'] ?? '') === 'pendiente' && $hc?->candidate_party_ids === [$other->getKey()]);
        $before = $counts();
        $new = $hc?->link(null, $user);
        $this->check('confirmar propietario nuevo: se crea con su ficha y recibe el encargo', $new !== null && ! $new->is($other) && $new->name === 'Carmen Ficticia'
            && $counts()[0] === $before[0] + 1 && $counts()[1] === $before[1] + 1 && Instruction::query()->find($hc->instruction_id)?->party_id === $new->getKey());
        $r = $post('OPP-P-NOMBRE-2');
        $hc2 = GhlHandoff::query()->where('opportunity_id', 'OPP-P-NOMBRE-2')->first();
        $this->check('después, otra oportunidad del mismo contacto se vincula sola a esa ficha', ($r['estado'] ?? '') === 'procesado' && $hc2?->party_id === $new?->getKey());

        // Same NIF (sent by GHL) as an owner with other e-mail/phone → that owner; other NIF on the matching owner → pending.
        $diego = $create('Diego Ficticio', null, null);
        OwnerProfile::for($diego)->fill(['tax_id' => 'X1234567L'])->save();
        $r = $post('OPP-P-NIF');
        $hd = GhlHandoff::query()->where('opportunity_id', 'OPP-P-NIF')->first();
        $this->check('mismo NIF que un propietario existente: se vincula a él', ($r['estado'] ?? '') === 'procesado' && $hd?->party_id === $diego->getKey());
        $elena = $create('Elena Ficticia', 'elena.ficticia@example.com', null);
        OwnerProfile::for($elena)->fill(['tax_id' => 'Y1234567X'])->save();
        $r = $post('OPP-P-NIF-OTRO');
        $he = GhlHandoff::query()->where('opportunity_id', 'OPP-P-NIF-OTRO')->first();
        $this->check('coincide el correo pero el NIF es otro: pendiente de vinculación', ($r['estado'] ?? '') === 'pendiente' && $he?->candidate_party_ids === [$elena->getKey()]
            && str_contains((string) $he->reason, 'NIF'));
    }

    private function check(string $label, bool $ok): void
    {
        $ok ? $this->pass++ : $this->fail++;
        $this->line(($ok ? 'PASS  ' : 'FAIL  ').$label);
    }
}
