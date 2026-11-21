<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Rentals\Models\Occupancy;
use App\Rentals\Models\RentalLicence;
use App\Rentals\Models\RentalSeason;
use App\Rentals\Models\RentalUnit;
use App\Rentals\Occupancies;
use App\Rentals\OccupancyConflict;
use App\Rentals\RentalAccess;
use App\Rentals\RentalFlows;
use App\Rentals\RentalModules;
use App\Rentals\RentalRefused;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Liberu\RealEstate\Parties\Models\Party;
use Liberu\RealEstate\Properties\Models\Property;
use Throwable;

/**
 * The three rental flows end to end with fictitious data, inside a transaction that is rolled back: nothing is kept.
 * Integrations use their local/simulated drivers (albarán, Formularios, communications, SES.HOSPEDAJES).
 *
 *   php artisan clarto:alquileres-prueba
 */
final class TestAlquileres extends Command
{
    protected $signature = 'clarto:alquileres-prueba';

    protected $description = 'Test the rental modules (traditional, rooms, holiday) with fictitious data (nothing is kept)';

    private int $pass = 0;

    private int $fail = 0;

    private int $team;

    private object $admin;

    public function handle(): int
    {
        // Communications go out for real now (App\Communications\RentalCommunicationLog): WAHA simulated, mail to memory.
        config(['mail.default' => 'array', 'firma.sms.driver' => 'simulado']);
        \Illuminate\Support\Facades\Mail::forgetMailers();
        \Illuminate\Support\Facades\Http::fake([rtrim((string) config('whatsapp.url'), '/').'/*' => fn ($r) => str_ends_with($r->url(), '/api/sendText')
            ? \Illuminate\Support\Facades\Http::response(['id' => 'true_PRUEBA_'.uniqid()], 201)
            : \Illuminate\Support\Facades\Http::response(['name' => 'default', 'status' => 'WORKING', 'me' => ['id' => '34960000000@c.us']])]);
        DB::beginTransaction();
        try {
            $this->scenarios();
        } catch (Throwable $e) {
            $this->check('sin excepciones inesperadas', false, $e::class.': '.$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine());
        } finally {
            DB::rollBack();
        }
        $this->line(sprintf('RESULT: %d passed, %d failed (integrations local/simulated; database rolled back)', $this->pass, $this->fail));

        return $this->fail === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function scenarios(): void
    {
        $this->admin = new class
        {
            public function isAdmin(): bool
            {
                return true;
            }
        };
        $this->team = (int) (DB::table('teams')->value('id') ?? DB::table('teams')->insertGetId(['user_id' => 1, 'name' => 'Equipo de prueba', 'personal_team' => 0, 'created_at' => now(), 'updated_at' => now()]));
        $userId = (int) (DB::table('users')->value('id') ?? 1);

        $this->traditional($userId);
        $this->rooms($userId);
        $this->holiday($userId);
        $this->concurrency($userId);
        $this->accessAndModules($userId);
    }

    /** Dwelling: contract, guarantees, keys, rent, non-payment, breakdown, renewal, exit, partial justified refund. */
    private function traditional(int $userId): void
    {
        $this->info('— Alquiler tradicional');
        [$property, $dwelling] = $this->dwelling($userId, 'Calle Ficticia 1, 2ºA, Valencia', 3);
        $owner = $this->party($userId, 'landlord', 'Propietaria Ficticia Uno');
        $tenant = $this->party($userId, 'tenant', 'Inquilino Ficticio Uno');

        $o = Occupancies::book([
            'unit_id' => $dwelling->id, 'module' => 'tradicional', 'kind' => 'contrato', 'purpose' => 'habitual',
            'planned_start' => '2027-01-01', 'planned_end' => '2028-01-01', 'occupants' => 2, 'rent_amount' => 900, 'rent_period' => 'mes', 'due_day' => 5,
            'utilities' => ['agua' => ['modo' => 'importe', 'valor' => 25], 'luz' => ['modo' => 'porcentaje', 'valor' => 100]],
        ], [
            ['party_id' => $tenant->id, 'role' => 'arrendatario', 'name' => $tenant->name, 'phone' => '+34600000001', 'identity' => ['tipo_documento' => 'DNI', 'numero_documento' => '00000000T'], 'solvency' => ['ingresos_mensuales' => 2500]],
            ['party_id' => $tenant->id, 'role' => 'firmante', 'name' => $tenant->name],
            ['role' => 'ocupante', 'name' => 'Ocupante Ficticia'],
            ['role' => 'avalista', 'name' => 'Avalista Ficticio', 'solvency' => ['nomina' => 3000]],
            ['party_id' => $owner->id, 'role' => 'propietario', 'name' => $owner->name],
        ]);
        $this->check('contrato vivienda habitual confirmado con roles separados', $o->status === 'confirmada' && $o->people()->count() === 5);
        $this->check('aviso de prórroga obligatoria (LAU art. 9) en el historial', str_contains(json_encode($o->events()->first()->data, JSON_UNESCAPED_UNICODE), '5'));
        $this->refused('fianza distinta de 1 mensualidad rechazada (LAU art. 36.1)', fn () => RentalFlows::receiveGuarantee($o, 'fianza', 1800, '2026-12-20'));
        $deposit = RentalFlows::receiveGuarantee($o, 'fianza', 900, '2026-12-20');
        $this->refused('garantía adicional > 2 mensualidades rechazada (LAU art. 36.5)', fn () => RentalFlows::receiveGuarantee($o, 'garantia_adicional', 2700, '2026-12-20'));
        $this->refused('seguro de impago exigido al inquilino rechazado (LAU art. 36.5, RDL 29/2026)', fn () => RentalFlows::receiveGuarantee($o, 'seguro_impago', 900, '2026-12-20', 'Aseguradora Ficticia'));
        RentalFlows::receiveGuarantee($o, 'aval', 5400, '2026-12-20', 'Banco Ficticio');
        RentalFlows::lodgeDeposit($deposit, 'MOD816-FICTICIO-0001', '2026-12-15');
        $this->check('fianza depositada en la GVA con resguardo (modelo 816) y aval como concepto distinto', $deposit->fresh()->status === 'depositada' && $o->guarantees()->where('type', 'aval')->exists() && $o->charges()->count() === 0
            && ! isset($o->events()->where('type', 'fianza_depositada')->first()->data['aviso']));

        $contract = RentalFlows::prepareDocument($o, 'contrato', [], true, $this->admin);
        $inventory = RentalFlows::prepareDocument($o, 'inventario', ['estancias' => ['salón' => 'sofá, mesa', 'cocina' => 'nevera, horno']], false, $this->admin);
        $this->check('contrato a firma e inventario preparados vía Formularios (local)', $contract->status === 'enviado_firma' && str_starts_with((string) $contract->external_ref, 'FORM-LOCAL-') && $inventory->status === 'preparado' && (float) $contract->fresh()->values['renta'] === 900.0,
            json_encode([$contract->status, $contract->external_ref, $inventory->status, $contract->fresh()->values['renta'] ?? null]));
        Occupancies::start($o, '2027-01-01', 'entrega_llaves', ['juegos_llaves' => 2]);

        $jan = RentalFlows::issueRent($o, '2027-01');
        RentalFlows::pay($jan, '2027-01-04');
        $feb = RentalFlows::issueRent($o, '2027-02');
        $this->refused('impago antes del vencimiento rechazado', fn () => RentalFlows::markUnpaid($feb, '2027-02-03'));
        RentalFlows::markUnpaid($feb, '2027-02-15');
        $logged = \App\Models\Communication::query()->where('related_type', $o->getMorphClass())->where('related_id', (string) $o->id)
            ->where('source', 'alquileres')->where('purpose', 'cobro')->where('status', 'enviado')->where('party_id', $tenant->id)->exists();
        $this->check('impago: cargo impagado, historial y requerimiento enviado a la ficha del inquilino (Comunicaciones)', $feb->fresh()->status === 'impagado' && $o->events()->where('type', 'impago')->exists() && $logged);
        $water = RentalFlows::splitUtilityBill($property->id, 'agua', 40, '2027-02', '2027-03-05');
        $this->check('suministro repartido según contrato (agua: importe fijo 25 €)', count($water) === 1 && (float) $water[0]->amount === 25.0);

        $leak = RentalFlows::openIncident($o, ['kind' => 'averia', 'title' => 'Fuga en el baño', 'priority' => 'alta', 'photos' => ['incidencias/fuga-ficticia.jpg'], 'access_authorized' => false]);
        RentalFlows::quoteIncident($leak, 'Fontanería Ficticia', 180, 'propietario');
        $this->refused('reparación sin acceso autorizado con inquilino dentro rechazada', fn () => RentalFlows::resolveIncident($leak, 175));
        $leak->forceFill(['access_authorized' => true, 'access_notes' => 'Martes 9-11 h'])->save();
        RentalFlows::resolveIncident($leak, 175);
        RentalFlows::verifyIncident($leak, $userId);
        $this->check('avería: fotos, presupuesto, responsable del coste y cierre verificado', $leak->fresh()->status === 'cerrada' && $leak->verified_by === $userId && $leak->cost_bearer === 'propietario');

        Occupancies::extend($o, '2029-01-01');
        Occupancies::record($o, 'preaviso', ['emisor' => 'arrendatario', 'fecha' => '2028-06-01', 'salida_prevista' => '2028-07-01']);
        RentalFlows::prepareDocument($o, 'anexo', ['cambio' => 'Prórroga hasta 01/01/2029'], false, $this->admin);
        Occupancies::finish($o, '2028-07-01', 'preaviso_arrendatario', 'salida');
        RentalFlows::prepareDocument($o, 'acta_salida', ['estado' => 'Arañazo en puerta del dormitorio'], false, $this->admin);
        $o->refresh();
        $this->check('vivienda con prórroga y salida (acta de salida)', $o->renewals === 1 && $o->status === 'finalizada' && $o->actual_end->toDateString() === '2028-07-01' && $o->documents()->where('kind', 'acta_salida')->exists());

        $this->refused('deducción de fianza sin justificar rechazada', fn () => RentalFlows::settleGuarantee($deposit->fresh(), [['concept' => 'daños', 'amount' => 100, 'justification' => '']], '2028-07-20'));
        $settled = RentalFlows::settleGuarantee($deposit->fresh(), [
            ['concept' => 'Reparación puerta', 'amount' => 120, 'justification' => 'Acta de salida: arañazo en puerta; factura ficticia F-1'],
            ['concept' => 'Renta impagada 2027-02 (parte)', 'amount' => 80, 'justification' => 'Cargo impagado', 'charge_id' => $feb->id],
        ], '2028-07-20');
        $this->check('devolución parcial justificada de fianza (700 € de 900 €)', $settled->status === 'devuelta_parcial' && (float) $settled->returned_amount === 700.0 && $o->charges()->where('concept', 'devolucion')->value('amount') == 700);
        $this->check('vivienda libre tras la salida (nuevo contrato desde 01/07/2028)', Occupancies::conflicts($dwelling, now()->setDate(2028, 7, 1), now()->setDate(2029, 1, 1))->isEmpty());
    }

    /** Rooms: two simultaneous occupancies, independent charges, utilities split, room change, dwelling vs room blocks. */
    private function rooms(int $userId): void
    {
        $this->info('— Alquiler por habitaciones');
        [$property, $dwelling] = $this->dwelling($userId, 'Avenida Ficticia 22, 4º, Valencia', 4);
        $dwelling->forceFill(['common_areas' => ['cocina', 'salón', 'baño compartido'], 'reference_rent' => 900])->save();
        $rooms = [];
        foreach (['H1', 'H2', 'H3'] as $name) {
            $rooms[$name] = RentalUnit::query()->create(['team_id' => $this->team, 'property_id' => $property->id, 'parent_id' => $dwelling->id, 'kind' => 'habitacion', 'name' => $name, 'max_occupants' => 1]);
        }
        $split = ['luz' => ['modo' => 'porcentaje', 'valor' => 25], 'internet' => ['modo' => 'importe', 'valor' => 10]];
        $a = Occupancies::book(['unit_id' => $rooms['H1']->id, 'module' => 'habitaciones', 'purpose' => 'habitual', 'planned_start' => '2027-02-01', 'planned_end' => '2028-02-01', 'rent_amount' => 400, 'rent_period' => 'mes', 'due_day' => 1, 'utilities' => $split],
            [['role' => 'arrendatario', 'name' => 'Inquilina Ficticia H1']]);
        $b = Occupancies::book(['unit_id' => $rooms['H2']->id, 'module' => 'habitaciones', 'purpose' => 'temporal', 'temporal_cause' => 'Contrato laboral temporal en Valencia (6 meses), documento ficticio', 'planned_start' => '2027-02-15', 'planned_end' => '2027-08-15', 'rent_amount' => 450, 'rent_period' => 'mes', 'due_day' => 15, 'utilities' => $split],
            [['role' => 'arrendatario', 'name' => 'Inquilino Ficticio H2']]);
        $this->check('dos habitaciones ocupadas a la vez', $a->status === 'confirmada' && $b->status === 'confirmada');
        $this->refused('temporal sin causa documentada rechazado', fn () => Occupancies::book(['unit_id' => $rooms['H3']->id, 'module' => 'habitaciones', 'purpose' => 'temporal', 'planned_start' => '2027-03-01', 'planned_end' => '2027-06-01', 'rent_amount' => 300, 'rent_period' => 'mes']));
        $this->refused('vivienda completa bloqueada por sus habitaciones', fn () => Occupancies::book(['unit_id' => $dwelling->id, 'module' => 'tradicional', 'purpose' => 'habitual', 'planned_start' => '2027-05-01', 'planned_end' => '2028-05-01', 'rent_amount' => 1200, 'rent_period' => 'mes']), OccupancyConflict::class);
        $this->refused('capacidad de la habitación respetada', fn () => Occupancies::book(['unit_id' => $rooms['H3']->id, 'module' => 'habitaciones', 'purpose' => 'habitual', 'occupants' => 2, 'planned_start' => '2027-03-01', 'planned_end' => '2028-03-01', 'rent_amount' => 300, 'rent_period' => 'mes']));
        $this->refused('temporal de 31 días o menos rechazado (LAU art. 9 bis)', fn () => Occupancies::book(['unit_id' => $rooms['H3']->id, 'module' => 'habitaciones', 'purpose' => 'temporal', 'temporal_cause' => 'Curso ficticio', 'planned_start' => '2027-03-01', 'planned_end' => '2027-03-21', 'rent_amount' => 300, 'rent_period' => 'mes']));
        $this->refused('suma de rentas de habitaciones > renta de la vivienda rechazada (LAU art. 17.9)', fn () => Occupancies::book(['unit_id' => $rooms['H3']->id, 'module' => 'habitaciones', 'purpose' => 'habitual', 'planned_start' => '2027-03-01', 'planned_end' => '2028-03-01', 'rent_amount' => 100, 'rent_period' => 'mes']));

        $ra = RentalFlows::issueRent($a, '2027-03');
        $rb = RentalFlows::issueRent($b, '2027-03');
        $light = RentalFlows::splitUtilityBill($property->id, 'luz', 120, '2027-03', '2027-04-05');
        $this->check('cobros independientes y luz repartida 25 % por habitación', (float) $ra->amount === 400.0 && (float) $rb->amount === 450.0 && count($light) === 2 && collect($light)->every(fn ($c) => (float) $c->amount === 30.0));

        $moved = Occupancies::changeRoom($b, $rooms['H3'], '2027-05-01');
        $b->refresh();
        $this->check('cambio de habitación H2 → H3 con fechas e historial', $b->status === 'finalizada' && $b->end_reason === 'cambio_habitacion' && $moved->unit_id === $rooms['H3']->id && $moved->previous_occupancy_id === $b->id
            && $moved->planned_start->toDateString() === '2027-05-01' && $moved->events()->where('type', 'cambio_habitacion')->exists() && $moved->people()->count() === 1);
        $this->check('H2 queda libre desde el cambio; H3 ocupada', Occupancies::conflicts($rooms['H2'], now()->setDate(2027, 5, 1), now()->setDate(2027, 8, 1))->isEmpty()
            && Occupancies::conflicts($rooms['H3'], now()->setDate(2027, 5, 1), now()->setDate(2027, 6, 1))->isNotEmpty());

        // A dwelling let whole blocks its rooms.
        [, $whole] = $this->dwelling($userId, 'Plaza Ficticia 3, 1º, Valencia', 4);
        $room = RentalUnit::query()->create(['team_id' => $this->team, 'property_id' => $whole->property_id, 'parent_id' => $whole->id, 'kind' => 'habitacion', 'name' => 'H1', 'max_occupants' => 1]);
        Occupancies::book(['unit_id' => $whole->id, 'module' => 'tradicional', 'purpose' => 'habitual', 'planned_start' => '2027-01-01', 'planned_end' => '2028-01-01', 'rent_amount' => 1000, 'rent_period' => 'mes']);
        $this->refused('habitación bloqueada por la vivienda completa', fn () => Occupancies::book(['unit_id' => $room->id, 'module' => 'habitaciones', 'purpose' => 'habitual', 'planned_start' => '2027-06-01', 'planned_end' => '2028-06-01', 'rent_amount' => 350, 'rent_period' => 'mes']), OccupancyConflict::class);
    }

    /** Holiday: VUT circuit, seasons, booking, overlap, cancellation with refund, check-in + SES, check-out + cleaning, settlement. */
    private function holiday(int $userId): void
    {
        $this->info('— Alquiler vacacional');
        [$property, $flat] = $this->dwelling($userId, 'Paseo Marítimo Ficticio 7, Gandia', 4);
        RentalSeason::query()->create(['team_id' => $this->team, 'unit_id' => $flat->id, 'name' => 'Media', 'starts_on' => '2027-06-01', 'ends_on' => '2027-06-30', 'nightly_rate' => 90, 'min_nights' => 2]);
        RentalSeason::query()->create(['team_id' => $this->team, 'unit_id' => $flat->id, 'name' => 'Alta', 'starts_on' => '2027-07-01', 'ends_on' => '2027-08-31', 'nightly_rate' => 140, 'min_nights' => 3]);
        $owner = ['role' => 'propietario', 'name' => 'Propietario Ficticio Vacacional'];
        $travellers = [
            ['role' => 'titular', 'name' => 'Titular Ficticia', 'email' => 'titular@example.test'],
            ['role' => 'viajero', 'name' => 'Viajera Ficticia', 'identity' => ['nombre' => 'Viajera', 'apellido1' => 'Ficticia', 'sexo' => 'M', 'tipo_documento' => 'NIE', 'numero_documento' => 'X0000000T', 'soporte_documento' => 'E00000000', 'nacionalidad' => 'FRA', 'fecha_nacimiento' => '1990-04-02', 'direccion' => 'Rue Fictive 1, Lyon', 'contacto' => '+33600000000', 'parentesco' => 'madre']],
            ['role' => 'viajero', 'name' => 'Menor Ficticio', 'identity' => ['nombre' => 'Menor', 'apellido1' => 'Ficticio', 'sexo' => 'H', 'nacionalidad' => 'FRA', 'fecha_nacimiento' => '2018-09-09', 'direccion' => 'Rue Fictive 1, Lyon', 'contacto' => '+33600000000', 'parentesco' => 'hijo']],
            $owner,
        ];

        $this->refused('sin registro VUT vigente no se reserva (circuito VUT, Comunitat Valenciana)', fn () => RentalFlows::bookHoliday($flat, '2027-06-10', '2027-06-14', 'directo', $travellers));
        RentalLicence::query()->create(['team_id' => $this->team, 'property_id' => $property->id, 'type' => 'vut_cv', 'number' => 'VT-000000-V (ficticio)', 'status' => 'vigente', 'valid_from' => '2026-01-01', 'authority' => 'Registro de Turismo de la Comunitat Valenciana']);
        $this->refused('más de 10 noches seguidas no es VUT (Ley 15/2018 CV, art. 65.1)', fn () => RentalFlows::bookHoliday($flat, '2027-07-01', '2027-07-15', 'directo', $travellers));
        $this->refused('estancia por debajo del mínimo de noches rechazada', fn () => RentalFlows::bookHoliday($flat, '2027-07-01', '2027-07-03', 'directo', $travellers));
        $this->refused('habitación suelta no admitida como VUT', fn () => Occupancies::book(['unit_id' => RentalUnit::query()->create(['team_id' => $this->team, 'property_id' => $property->id, 'parent_id' => $flat->id, 'kind' => 'habitacion', 'name' => 'H1'])->id, 'module' => 'vacacional', 'kind' => 'reserva', 'purpose' => 'turistica', 'planned_start' => '2027-06-01', 'planned_end' => '2027-06-05']));

        $cancelled = RentalFlows::bookHoliday($flat, '2027-06-29', '2027-07-03', 'airbnb', $travellers, 'HM-FICTICIO-1');
        $this->check('tarifa por temporada (2×90 + 2×140 = 460 €)', (float) $cancelled->rent_amount === 460.0);
        RentalFlows::pay($cancelled->charges()->where('concept', 'tarifa')->first(), '2027-05-01');
        RentalFlows::cancelHoliday($cancelled, 50, 'Cancelación del huésped (política moderada ficticia)', '2027-06-01');
        $cancelled->refresh();
        $this->check('cancelación con devolución del 50 % (230 €) y fechas liberadas', $cancelled->status === 'cancelada' && (float) $cancelled->charges()->where('concept', 'devolucion')->value('amount') === 230.0
            && Occupancies::conflicts($flat, now()->setDate(2027, 6, 29), now()->setDate(2027, 7, 3))->isEmpty());

        $stay = RentalFlows::bookHoliday($flat, '2027-07-10', '2027-07-15', 'directo', $travellers);
        $this->refused('solape de reservas rechazado', fn () => RentalFlows::bookHoliday($flat, '2027-07-14', '2027-07-18', 'booking', $travellers), OccupancyConflict::class);
        $next = RentalFlows::bookHoliday($flat, '2027-07-15', '2027-07-18', 'booking', $travellers);
        $this->check('entrada el mismo día de la salida anterior permitida', $next->status === 'confirmada');
        Occupancies::book(['unit_id' => $flat->id, 'module' => 'vacacional', 'kind' => 'bloqueo', 'planned_start' => '2027-08-01', 'planned_end' => '2027-08-08', 'notes' => 'Uso del propietario']);
        $this->refused('bloqueo de calendario respetado', fn () => RentalFlows::bookHoliday($flat, '2027-08-05', '2027-08-10', 'directo', $travellers), OccupancyConflict::class);

        foreach ($stay->charges as $charge) {
            RentalFlows::pay($charge, '2027-07-10');
        }
        $incomplete = $stay->people()->where('role', 'viajero')->first();
        $identity = $incomplete->identity;
        $incomplete->forceFill(['identity' => array_diff_key($identity, ['soporte_documento' => 1])])->save();
        $this->refused('check-in sin datos completos de viajeros rechazado (SES.HOSPEDAJES)', fn () => RentalFlows::checkIn($stay->fresh(), '2027-07-10'));
        $incomplete->forceFill(['identity' => $identity])->save();
        $ses = RentalFlows::checkIn($stay->fresh(), '2027-07-10');
        $this->check('check-in con parte a SES.HOSPEDAJES (simulado)', $ses['status'] === 'aceptado_simulado' && $stay->fresh()->status === 'en_curso');
        $cleaning = RentalFlows::checkOut($stay->fresh(), '2027-07-15');
        RentalFlows::resolveIncident($cleaning, 60);
        RentalFlows::verifyIncident($cleaning, $userId);
        $this->check('check-out y limpieza verificada', $stay->fresh()->status === 'finalizada' && $cleaning->fresh()->status === 'cerrada');
        $ics = \App\Rentals\Integration\IcalCalendar::export($flat);
        $feed = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:reserva-ficticia-1@airbnb.example\r\nDTSTART;VALUE=DATE:20270901\r\nDTEND;VALUE=DATE:20270905\r\nEND:VEVENT\r\n"
            ."BEGIN:VEVENT\r\nUID:reserva-ficticia-2@airbnb.example\r\nDTSTART;VALUE=DATE:20270712\r\nDTEND;VALUE=DATE:20270714\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $first = \App\Rentals\Integration\IcalCalendar::import($flat, 'airbnb', $feed);
        $again = \App\Rentals\Integration\IcalCalendar::import($flat, 'airbnb', $feed);
        $this->check('calendario iCal: exporta noches ocupadas e importa bloqueos del canal sin duplicar (driver preparado)', str_contains($ics, 'DTSTART;VALUE=DATE:20270710') && str_contains($ics, 'DTEND;VALUE=DATE:20270715')
            && $first['created'] === 1 && count($first['conflicts']) === 1 && $again['created'] === 0 && $again['skipped'] === 1, json_encode([$first, $again]));
        $settlement = RentalFlows::settleOwner($stay->fresh());
        // 5 nights × 140 = 700 + 60 cleaning = 760 collected; 20 % commission = 152; owner-borne cleaning 60 → 548.
        $this->check('liquidación al propietario (760 − 152 − 60 = 548 €)', $settlement['neto'] === 548.0 && $settlement['comision'] === 152.0);
        $this->check('albarán generado para la comisión (punto de integración)', str_starts_with((string) $settlement['albaran'], 'ALB-ALQ-')
            && $stay->charges()->where('concept', 'servicio_clarto')->where('albaran_ref', $settlement['albaran'])->exists()
            && $stay->events()->where('type', 'albaran_solicitado')->exists());
        $habitual = Occupancy::query()->where('purpose', 'habitual')->firstOrFail();
        $this->refused('honorarios de gestión o renovación al inquilino de vivienda rechazados (LAU art. 20.2)', fn () => RentalFlows::requestAlbaran($habitual, [['description' => 'Honorarios de renovación', 'quantity' => 1, 'unit_price' => 300, 'tipo' => 'renovacion']], 'inquilino'));
        $this->refused('servicio opcional al inquilino sin solicitud escrita rechazado (LAU art. 20.2)', fn () => RentalFlows::requestAlbaran($habitual, [['description' => 'Limpieza extra opcional', 'quantity' => 1, 'unit_price' => 50, 'tipo' => 'opcional']], 'inquilino'));
    }

    /** Two requests for the same dates at the same time: the second one cannot slip in. */
    private function concurrency(int $userId): void
    {
        $this->info('— Concurrencia');
        [, $flat] = $this->dwelling($userId, 'Calle Concurrente Ficticia 9, Valencia', 2);
        $first = Occupancies::book(['unit_id' => $flat->id, 'module' => 'tradicional', 'purpose' => 'habitual', 'planned_start' => '2027-09-01', 'planned_end' => '2028-09-01', 'rent_amount' => 700, 'rent_period' => 'mes']);
        // Request A is still open (this command's transaction holds the property lock). Request B arrives through a
        // second database connection at the same moment, for overlapping dates.
        $default = config('database.default');
        $config = config("database.connections.{$default}");
        if (($config['driver'] ?? null) !== 'sqlite') {
            $this->check('dos solicitudes simultáneas (solo comprobado sobre SQLite)', true, 'omitido: driver '.$config['driver']);

            return;
        }
        config(['database.connections.alquileres_b' => ['busy_timeout' => 300, 'transaction_mode' => 'DEFERRED'] + $config]);
        $outcome = 'aceptada';
        try {
            Occupancies::book(['unit_id' => $flat->id, 'module' => 'tradicional', 'purpose' => 'habitual', 'planned_start' => '2028-03-01', 'planned_end' => '2029-03-01', 'rent_amount' => 750, 'rent_period' => 'mes'], [], 'alquileres_b', $flat);
        } catch (OccupancyConflict $e) {
            $outcome = 'rechazada: '.$e->getMessage();
        } catch (Throwable $e) {
            $outcome = 'error: '.$e->getMessage();
        } finally {
            DB::purge('alquileres_b');
        }
        $this->check('dos solicitudes simultáneas: la segunda espera el bloqueo y se rechaza', str_starts_with($outcome, 'rechazada') && Occupancy::query()->where('unit_id', $flat->id)->count() === 1, $outcome);
        // Once A is known, the same request B (now sequential) is rejected by the overlap check itself.
        $this->refused('la misma solicitud, ya en serie, choca con la primera', fn () => Occupancies::book(['unit_id' => $flat->id, 'module' => 'tradicional', 'purpose' => 'habitual', 'planned_start' => '2028-03-01', 'planned_end' => '2029-03-01', 'rent_amount' => 750, 'rent_period' => 'mes']), OccupancyConflict::class);
        $this->check('la primera sigue intacta', $first->fresh()->status === 'confirmada');
    }

    private function accessAndModules(int $userId): void
    {
        $this->info('— Permisos y módulos');
        $person = \App\Rentals\Models\OccupancyPerson::query()->where('role', 'arrendatario')->whereNotNull('identity')->firstOrFail();
        $raw = DB::table('clarto_rent_people')->where('id', $person->id)->value('identity');
        $this->check('identidad cifrada en la base de datos', ! str_contains((string) $raw, '00000000T'));
        $nobody = new class {};
        $this->check('sin permiso no se ve identidad ni solvencia; con permiso sí', $person->identityFor($nobody) === null && $person->solvencyFor($nobody) === null && ($person->identityFor($this->admin)['numero_documento'] ?? null) === '00000000T');
        $this->refused('preparar contratos exige permiso', fn () => RentalFlows::prepareDocument($person->occupancy, 'contrato', [], false, $nobody));

        $history = Occupancy::query()->where('module', 'vacacional')->count();
        DB::table('module_activations')->updateOrInsert(['module' => 'clarto-alquiler-vacacional'], ['enabled' => false, 'created_at' => now(), 'updated_at' => now()]);
        app()->forgetScopedInstances();
        $off = ! RentalModules::enabled('vacacional');
        [, $flat] = $this->dwelling($userId, 'Calle Desactivada Ficticia 1, Valencia', 2);
        $this->refused('módulo desactivado: no admite nuevas reservas', fn () => Occupancies::book(['unit_id' => $flat->id, 'module' => 'vacacional', 'kind' => 'bloqueo', 'planned_start' => '2027-01-01', 'planned_end' => '2027-01-05']));
        $this->check('módulo desactivado conserva el historial', $off && Occupancy::query()->where('module', 'vacacional')->count() === $history && $history > 0);
    }

    /** @return array{0: Property, 1: RentalUnit} */
    private function dwelling(int $userId, string $address, int $capacity): array
    {
        $property = Property::query()->create(['team_id' => $this->team, 'created_by' => $userId, 'address' => $address, 'title' => $address, 'property_type' => 'apartment', 'status' => 'available', 'deal_type' => 'rent']);
        $unit = RentalUnit::query()->create(['team_id' => $this->team, 'property_id' => $property->id, 'kind' => 'vivienda', 'name' => 'Vivienda', 'max_occupants' => $capacity]);

        return [$property, $unit];
    }

    private function party(int $userId, string $type, string $name): Party
    {
        return Party::query()->create(['team_id' => $this->team, 'created_by' => $userId, 'type' => $type, 'name' => $name, 'email' => str($name)->slug().'@example.test']);
    }

    private function refused(string $label, callable $operation, string $expected = RentalRefused::class): void
    {
        try {
            DB::transaction(fn () => $operation());
            $this->check($label, false, 'se aceptó');
        } catch (Throwable $e) {
            $this->check($label, $e instanceof $expected, $e instanceof $expected ? $e->getMessage() : $e::class.': '.$e->getMessage());
        }
    }

    private function check(string $label, bool $ok, ?string $detail = null): void
    {
        $ok ? $this->pass++ : $this->fail++;
        $this->line(($ok ? '  PASS ' : '  FAIL ').$label.($detail && (! $ok || $this->output->isVerbose()) ? '  ['.$detail.']' : ''));
    }
}
