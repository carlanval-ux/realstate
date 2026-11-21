<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Albaranes\Albaran;
use App\Albaranes\AlbaranPdf;
use App\Albaranes\BillingClient;
use App\Albaranes\BillingConnector;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Liberu\RealEstate\Parties\Models\Party;
use Liberu\RealEstate\Properties\Models\Property;

/**
 * End-to-end test of albaranes → billing program with a SIMULATED billing API (Http::fake): nothing leaves the
 * server. Runs inside a database transaction that is rolled back, so nothing is kept.
 *
 *   php artisan clarto:albaranes-prueba [--inmueble=CL-2026-00004]
 */
final class TestAlbaranes extends Command
{
    protected $signature = 'clarto:albaranes-prueba {--inmueble= : property reference (default: the first property)}';

    protected $description = 'Test service notes (albaranes) against a simulated billing program API (nothing is kept)';

    private int $pass = 0;

    private int $fail = 0;

    public function handle(): int
    {
        $ref = (string) $this->option('inmueble');
        $property = $ref !== '' ? Property::query()->findOrFail((int) substr($ref, strrpos($ref, '-') + 1)) : Property::query()->orderBy('id')->firstOrFail();

        Cache::forget('facturador:catalogo');
        DB::beginTransaction();
        try {
            $this->scenario($property);
        } finally {
            DB::rollBack();
            Cache::forget('facturador:catalogo');
        }

        $this->line(sprintf('RESULT: %d passed, %d failed (billing program simulated with Http::fake; database rolled back)', $this->pass, $this->fail));

        return $this->fail === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function scenario(Property $property): void
    {
        app()->setLocale('es');
        config(['facturador.driver' => 'api', 'facturador.enabled' => true, 'facturador.base_url' => 'https://facturador.prueba/api/public/v1', 'facturador.api_key' => 'rt_test_clave']);

        $owner = Party::query()->create([
            'team_id' => $property->team_id, 'created_by' => (int) (DB::table('users')->min('id') ?? 1), 'type' => 'vendor',
            'name' => 'Propietaria de Prueba', 'email' => 'prueba@example.test', 'phone' => '+34 600 000 001',
            'metadata' => ['tax_id' => '12345678Z', 'address' => 'Calle Prueba 1, 46001 València'],
        ]);
        $make = fn (?Property $p, array $lines): Albaran => tap(Albaran::query()->create(['team_id' => $property->team_id, 'party_id' => $owner->getKey(), 'property_id' => $p?->getKey(), 'service_date' => now()->toDateString()] + Albaran::clientDataFor($owner)), function (Albaran $a) use ($lines): void {
            foreach ($lines as $i => $l) {
                $a->lines()->create($l + ['sort' => $i]);
            }
            $a->refresh();
        });

        // What the simulated billing program answers next, per kind of call.
        $script = ['customers-get' => [], 'customers-post' => [], 'notes-get' => [], 'notes-post' => [], 'note-get' => []];
        Http::preventStrayRequests();
        Http::fake(function (Request $r) use (&$script) {
            $path = (string) parse_url($r->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/catalog/services')) {
                return Http::response(['data' => [
                    ['code' => 'GES-CEE', 'name' => 'Certificado energético', 'unitPrice' => 150, 'requiresConformity' => false],
                    ['code' => 'INM-FOTO', 'name' => 'Reportaje fotográfico', 'unitPrice' => 120, 'requiresConformity' => true],
                ]]);
            }
            $kind = match (true) {
                str_ends_with($path, '/customers') => 'customers-'.strtolower($r->method()),
                str_ends_with($path, '/delivery-notes') => 'notes-'.strtolower($r->method()),
                str_contains($path, '/delivery-notes/') => 'note-get',
                default => 'other',
            };
            $next = array_shift($script[$kind]) ?? ['data' => []];

            return $next === 'timeout' ? Http::failedConnection() : Http::response($next, $next['_http'] ?? 200);
        });
        $count = fn (string $path, string $method): int => Http::recorded(fn (Request $r) => $r->method() === $method && str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), $path))->count();
        $c = BillingConnector::make();

        $a = $make($property, [
            ['service_code' => 'GES-CEE', 'description' => 'Certificado energético', 'quantity' => 1, 'unit_price' => 150, 'discount_pct' => 0],
            ['service_code' => null, 'description' => 'Desplazamiento (línea libre)', 'quantity' => 2, 'unit_price' => 25, 'discount_pct' => 10],
        ]);
        $b = $make(null, [['service_code' => 'INM-FOTO', 'description' => 'Reportaje fotográfico', 'quantity' => 1, 'unit_price' => 120, 'discount_pct' => 0]]);

        $this->check('albarán con inmueble: total sin impuestos 195,00 (150 + 2×25 −10 %)', (float) $a->total === 195.0 && $a->property_id === $property->getKey());
        $this->check('albarán independiente: sin inmueble, cliente precargado del propietario', $b->property_id === null && $b->client_tax_id === '12345678Z' && $b->client_name === 'Propietaria de Prueba');
        $this->check('servicio del catálogo del facturador que exige conformidad → pendiente de firma', $b->conformity === Albaran::CONFORMITY_PENDING && $a->conformity === Albaran::CONFORMITY_NONE);
        $pdf = AlbaranPdf::render($a->load(['lines', 'property']));
        $this->check('PDF generado con número y total', str_starts_with($pdf, '%PDF-1.4') && str_contains($pdf, $a->number) && str_contains($pdf, '195,00'));

        // 0. Real sends switched off → refused, nothing sent, still a draft.
        config(['facturador.enabled' => false]);
        $calls = Http::recorded()->count();
        $c->send($a);
        $this->check('envíos desactivados: no se envía nada y sigue en borrador', Http::recorded()->count() === $calls && $a->status === Albaran::DRAFT && str_contains((string) $a->last_error, 'FACTURADOR_ENABLED'));
        config(['facturador.enabled' => true]);

        // 1. Client created once, then the billing program fails on the albarán → Error with reason.
        $script['customers-post'][] = ['data' => ['id' => 'CUS-77']];
        $script['notes-post'][] = ['_http' => 503, 'error' => ['code' => 'RT-UNAVAILABLE', 'message' => 'Mantenimiento']];
        $c->send($a);
        $customer = Http::recorded(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/customers'))->first()[0] ?? null;
        $this->check('cliente creado en el facturador con NIF y referencia del propietario', $customer?->data()['document'] === '12345678Z' && $customer->data()['externalRef'] === 'clarto-party-'.$owner->getKey());
        $this->check('fallo del facturador: estado Error con el motivo', $a->status === Albaran::ERROR && str_contains((string) $a->last_error, 'Mantenimiento') && str_contains((string) $a->last_error, 'RT-UNAVAILABLE'));
        $firstKey = Http::recorded(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/delivery-notes'))->first()[0]->header('Idempotency-Key')[0] ?? null;

        // 2. Retry: same client, same albarán, same idempotency key → Enviado.
        $script['notes-post'][] = ['data' => ['id' => 'DN-501', 'status' => 'pending']];
        $c->send($a);
        $post = Http::recorded(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/delivery-notes'))->last()[0];
        $this->check('reintento: Enviado con el id externo del albarán', $a->status === Albaran::SENT && $a->external_id === 'DN-501' && blank($a->last_error));
        $this->check('reintento: no duplica el cliente (un solo alta, un solo vínculo)', $count('/customers', 'POST') === 1 && BillingClient::query()->where('party_id', $owner->getKey())->count() === 1);
        $this->check('reintento: no duplica el albarán (misma Idempotency-Key, mismo número)', $firstKey !== null && ($post->header('Idempotency-Key')[0] ?? null) === $firstKey && $post->data()['externalRef'] === $a->number && Albaran::query()->where('party_id', $owner->getKey())->count() === 2);
        $this->check('envío: cliente vinculado, líneas con descuento y referencia del inmueble', $post->data()['customerId'] === 'CUS-77' && count($post->data()['lines']) === 2 && (float) $post->data()['lines'][1]['discountPct'] === 10.0 && $post->data()['context']['property'] === $property->reference());
        $this->check('confirmar no emite factura (ninguna llamada a facturas) y sigue sin facturar', Http::recorded(fn (Request $r) => str_contains($r->url(), 'invoice'))->count() === 0 && $a->invoice_number === null);
        $c->send($a);
        $this->check('enviar otra vez un albarán ya enviado no hace nada', $count('/delivery-notes', 'POST') === 2);

        // 3. Albarán that needs conformity: refused until signed; then a timeout leaves it «pendiente de envío».
        $c->send($b);
        $this->check('sin conformidad firmada: no se envía y lo explica', $b->status === Albaran::DRAFT && str_contains((string) $b->last_error, 'conformidad') && $count('/delivery-notes', 'POST') === 2);
        $b->markConformitySigned();
        $script['notes-post'][] = 'timeout';
        $c->send($b);
        $this->check('sin respuesta: queda «Pendiente de envío» sin id externo', $b->status === Albaran::PENDING && blank($b->external_id));
        $this->check('mismo propietario: reutiliza el cliente vinculado (sin buscar ni crear)', $count('/customers', 'POST') === 1 && $count('/customers', 'GET') === 2);
        $script['notes-get'][] = ['data' => [['id' => 'DN-502', 'status' => 'pending', 'externalRef' => $b->number]]];
        $posts = $count('/delivery-notes', 'POST');
        $c->send($b);
        $this->check('reintento tras «pendiente»: lo encuentra por número, sin segundo envío', $b->status === Albaran::SENT && $b->external_id === 'DN-502' && $count('/delivery-notes', 'POST') === $posts);

        // 4. The billing program issues the invoice → Facturado, number and date linked back to the owner.
        $script['note-get'][] = ['data' => ['id' => 'DN-501', 'status' => 'pending']];
        $c->refresh($a);
        $this->check('consultar: todavía pendiente de facturar', $a->status === Albaran::SENT && $a->invoice_number === null);
        $script['note-get'][] = ['data' => ['id' => 'DN-501', 'status' => 'invoiced', 'invoice' => ['id' => 'INV-9', 'number' => 'F2026-0042', 'issueDate' => '2026-11-21T10:00:00Z']]];
        BillingConnector::syncSent();
        $a->refresh();
        $this->check('facturado: número y fecha de factura del facturador', $a->status === Albaran::INVOICED && $a->invoice_number === 'F2026-0042' && $a->invoice_date?->toDateString() === '2026-11-21');
        $ownerInvoices = Albaran::query()->where('party_id', $owner->getKey())->whereNotNull('invoice_number')->pluck('invoice_number')->all();
        $this->check('ficha del propietario: la factura aparece en sus albaranes', $ownerInvoices === ['F2026-0042']);
        $this->check('un albarán facturado ya no se puede editar', ! $a->isEditable());

        // 5. Billing program down while checking: error kept, the albarán keeps its state and ids.
        $script['note-get'][] = ['_http' => 500];
        $c->refresh($b);
        $this->check('facturador caído al consultar: motivo guardado sin perder estado ni id', $b->status === Albaran::SENT && $b->external_id === 'DN-502' && str_contains((string) $b->last_error, 'HTTP 500'));
    }

    private function check(string $label, bool $ok): void
    {
        $ok ? $this->pass++ : $this->fail++;
        $this->line(($ok ? 'PASS  ' : 'FAIL  ').$label);
    }
}
