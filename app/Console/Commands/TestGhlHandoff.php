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
                'codigo_postal' => 'f-cp', 'direccion' => 'f-dir', 'superficie_m2' => 'f-m2', 'dormitorios' => 'f-dorm', 'banos' => 'f-banos', 'plazo' => 'f-plazo']]);

        DB::beginTransaction();
        try {
            $this->scenarios();
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

    private function check(string $label, bool $ok): void
    {
        $ok ? $this->pass++ : $this->fail++;
        $this->line(($ok ? 'PASS  ' : 'FAIL  ').$label);
    }
}
