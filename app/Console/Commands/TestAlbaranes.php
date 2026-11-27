<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Albaranes\Albaran;
use App\Albaranes\AlbaranPdf;
use App\Albaranes\BillingArticle;
use App\Albaranes\BillingClient;
use App\Albaranes\BillingConnector;
use App\Albaranes\FacturaloTransport;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\RealEstate\Parties\Models\Party;
use Liberu\RealEstate\Properties\Models\Property;

/**
 * End-to-end test of albaranes → Factúralo with a SIMULATED Factúralo API `/api/3` (Http::fake): nothing leaves the
 * server. Runs inside a database transaction that is rolled back, so nothing is kept.
 *
 *   php artisan clarto:albaranes-prueba
 */
final class TestAlbaranes extends Command
{
    protected $signature = 'clarto:albaranes-prueba';

    protected $description = 'Test service notes (albaranes) against a simulated Factúralo API (nothing is kept)';

    private int $pass = 0;

    private int $fail = 0;

    public function handle(): int
    {
        DB::beginTransaction();
        try {
            // Fictitious property of the first company, created inside the rolled-back transaction: no real data needed.
            $team = Team::query()->oldest()->firstOrFail();
            $this->scenario(Property::query()->create([
                'team_id' => $team->id, 'created_by' => $team->getAttribute('user_id'), 'title' => 'Piso ficticio para albaranes', 'address' => 'Calle Ficticia 1, 46001 Valencia',
                'postal_code' => '46001', 'property_type' => 'apartment', 'status' => 'available', 'deal_type' => 'sale', 'price' => 150000, 'area_sqft' => 80,
            ]));
        } finally {
            DB::rollBack();
        }

        $this->line(sprintf('RESULT: %d passed, %d failed (Factúralo simulated with Http::fake; database rolled back)', $this->pass, $this->fail));

        return $this->fail === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function scenario(Property $property): void
    {
        app()->setLocale('es');
        config([
            'facturador.driver' => 'facturalo', 'facturador.enabled' => true, 'facturador.base_url' => 'https://prueba.facturalo.test',
            'facturador.api_key' => 'clave-de-prueba', 'facturador.facturalo' => ['codserie' => 'A', 'codpago' => 'CONT', 'codalmacen' => null],
        ]);

        $newOwner = fn (string $name, string $nif): Party => Party::query()->create([
            'team_id' => $property->team_id, 'created_by' => $property->getAttribute('created_by'), 'type' => 'vendor',
            'name' => $name, 'email' => 'prueba@example.test', 'phone' => '+34 600 000 001',
            'metadata' => ['tax_id' => $nif, 'address' => 'Calle Prueba 1, 46001 València'],
        ]);
        $owner = $newOwner('Propietaria de Prueba', '12345678Z');
        $make = fn (Party $o, ?Property $p, array $lines, ?string $date = null): Albaran => tap(Albaran::query()->create(['team_id' => $property->team_id, 'party_id' => $o->getKey(), 'property_id' => $p?->getKey(), 'service_date' => $date ?? now()->toDateString()] + Albaran::clientDataFor($o)), function (Albaran $a) use ($lines): void {
            foreach ($lines as $i => $l) {
                $a->lines()->create($l + ['sort' => $i]);
            }
            $a->refresh();
        });

        // Articles made by hand (no predefined catalogue): one reusable with a Factúralo reference, one that asks for conformity.
        $cee = BillingArticle::query()->create(['team_id' => $property->team_id, 'reference' => 'PRB-CEE', 'name' => 'Certificado energético', 'unit_price' => 150, 'vat_pct' => 21]);
        $foto = BillingArticle::query()->create(['team_id' => $property->team_id, 'reference' => null, 'name' => 'Reportaje fotográfico', 'unit_price' => 120, 'vat_pct' => 21, 'requires_conformity' => true]);

        // What the simulated Factúralo answers next, per call («GET clientes/{id}», «POST crearAlbaranCliente»…).
        $script = [];
        Http::preventStrayRequests();
        Http::fake(function (Request $r) use (&$script) {
            $resource = explode('/', (string) preg_replace('#^.*/api/3/#', '', (string) parse_url($r->url(), PHP_URL_PATH)));
            $kind = $r->method().' '.$resource[0].(isset($resource[1]) ? '/{id}' : '');
            $next = array_key_exists($kind, $script) && $script[$kind] !== [] ? array_shift($script[$kind]) : match ($kind) {
                'GET clientes/{id}' => ['_http' => 404, 'error' => 'record-not-found'],
                default => [],
            };

            return $next === 'timeout' ? Http::failedConnection() : Http::response(array_diff_key($next, ['_http' => 0]), $next['_http'] ?? 200);
        });
        $calls = fn (string $kind): \Illuminate\Support\Collection => Http::recorded(function (Request $r) use ($kind): bool {
            $resource = explode('/', (string) preg_replace('#^.*/api/3/#', '', (string) parse_url($r->url(), PHP_URL_PATH)));

            return $r->method().' '.$resource[0].(isset($resource[1]) ? '/{id}' : '') === $kind;
        })->map(fn (array $pair): Request => $pair[0])->values();
        $query = fn (Request $r): array => (function () use ($r): array {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

            return $q;
        })();
        $c = BillingConnector::make();

        $a = $make($owner, $property, [
            ['article_id' => $cee->getKey(), 'description' => 'Certificado energético del piso', 'quantity' => 1, 'unit_price' => 150, 'discount_pct' => 0, 'vat_pct' => 21],
            ['article_id' => null, 'description' => 'Desplazamiento (línea libre)', 'quantity' => 2, 'unit_price' => 25, 'discount_pct' => 10, 'vat_pct' => 10],
        ]);
        $b = $make($owner, null, [['article_id' => $foto->getKey(), 'description' => 'Reportaje fotográfico', 'quantity' => 1, 'unit_price' => 120, 'discount_pct' => 0, 'vat_pct' => 21]]);

        $totals = $a->totals();
        $this->check('líneas de texto libre: base 195,00 (150 + 2×25 −10 %), IVA 36,00 (21 % y 10 %), total 231,00', (float) $a->total === 195.0 && $totals === ['base' => 195.0, 'vat' => 36.0, 'total' => 231.0]);
        $this->check('línea con artículo hecho a mano: guarda su referencia; la línea libre no tiene referencia', $a->lines[0]->service_code === 'PRB-CEE' && $a->lines[1]->service_code === null && $a->lines[1]->article_id === null);
        $this->check('albarán independiente: sin inmueble, cliente precargado del propietario', $b->property_id === null && $b->client_tax_id === '12345678Z' && $b->client_name === 'Propietaria de Prueba');
        $this->check('artículo que pide conformidad → pendiente de firma; sin él, no', $b->conformity === Albaran::CONFORMITY_PENDING && $a->conformity === Albaran::CONFORMITY_NONE);
        $pdf = AlbaranPdf::render($a->load(['lines', 'property']));
        $this->check('PDF con número, base, IVA y total', str_starts_with($pdf, '%PDF-1.4') && str_contains($pdf, $a->number) && str_contains($pdf, '195,00') && str_contains($pdf, '36,00') && str_contains($pdf, '231,00'));
        $this->check('código de cliente estable para Factúralo (10 caracteres)', FacturaloTransport::clientCode('clarto-party-12') === 'CLP0000012' && FacturaloTransport::taxCode(10.0) === 'IVA10' && FacturaloTransport::taxCode(0.0) === 'IVA0');

        // 0. Real sends switched off → refused, nothing sent, still a draft.
        config(['facturador.enabled' => false]);
        $c->send($a);
        $this->check('envíos desactivados: no se envía nada y sigue en borrador', Http::recorded()->count() === 0 && $a->status === Albaran::DRAFT && str_contains((string) $a->last_error, 'FACTURADOR_ENABLED'));
        config(['facturador.enabled' => true]);

        // 1. Client not in Factúralo (neither by our code nor by NIF) → created once; Factúralo fails on the albarán → Error.
        $code = FacturaloTransport::clientCode('clarto-party-'.$owner->getKey());
        $script['POST productos'][] = ['ok' => 'record-updated-correctly', 'data' => ['idproducto' => 33, 'referencia' => 'PRB-CEE']];
        $script['POST crearAlbaranCliente'][] = ['_http' => 503, 'status' => 'error', 'message' => 'Mantenimiento'];
        $c->send($a);
        $client = $calls('POST clientes')->first();
        $this->check('cliente creado en Factúralo con código estable, NIF y nombre del propietario', $client?->data()['codcliente'] === $code && $client->data()['cifnif'] === '12345678Z' && $client->data()['nombre'] === 'Propietaria de Prueba');
        $this->check('antes de crearlo se buscó por código y por NIF', $calls('GET clientes/{id}')->count() === 1 && ($query($calls('GET clientes')->first())['filter']['cifnif'] ?? null) === '12345678Z');
        $product = $calls('POST productos')->first();
        $this->check('artículo con referencia dado de alta como producto (servicio, sin stock) y enlazado', $product?->data()['referencia'] === 'PRB-CEE' && $product->data()['nostock'] === '1' && $product->data()['codimpuesto'] === 'IVA21' && $cee->fresh()->external_id === '33');
        $this->check('fallo de Factúralo: estado Error con el motivo', $a->status === Albaran::ERROR && str_contains((string) $a->last_error, 'Mantenimiento') && str_contains((string) $a->last_error, 'HTTP 503'));
        $firstKey = $calls('POST crearAlbaranCliente')->first()?->header('Idempotency-Key')[0] ?? null;

        // 2. Retry: same client, no duplicate albarán (looked up by numero2 first) → Enviado.
        $script['POST crearAlbaranCliente'][] = ['doc' => ['idalbaran' => 501, 'codigo' => 'ALB2026A1', 'numero2' => $a->number, 'total' => 231], 'lines' => []];
        $c->send($a);
        $post = $calls('POST crearAlbaranCliente')->last();
        $lines = json_decode((string) ($post->data()['lineas'] ?? '[]'), true);
        $this->check('reintento: Enviado con el id del albarán en Factúralo', $a->status === Albaran::SENT && $a->external_id === '501' && blank($a->last_error));
        $this->check('reintento: no duplica el cliente (un solo alta, un solo vínculo)', $calls('POST clientes')->count() === 1 && BillingClient::query()->where('party_id', $owner->getKey())->count() === 1);
        $this->check('reintento: antes de enviar busca el albarán por nuestro número (numero2)', ($query($calls('GET albaranclientes')->last())['filter']['numero2'] ?? null) === $a->number && $post->data()['numero2'] === $a->number && ($post->header('Idempotency-Key')[0] ?? null) === $firstKey);
        $this->check('envío por formulario con la clave Token, serie y forma de pago', str_contains($post->header('Content-Type')[0] ?? '', 'application/x-www-form-urlencoded') && ($post->header('Token')[0] ?? null) === 'clave-de-prueba' && $post->data()['codserie'] === 'A' && $post->data()['codpago'] === 'CONT' && ! isset($post->data()['codalmacen']));
        $this->check('líneas: concepto libre, precio sin IVA, descuento, IVA como código e inmueble en observaciones', $post->data()['codcliente'] === $code && count($lines) === 2
            && $lines[0]['referencia'] === 'PRB-CEE' && $lines[0]['descripcion'] === 'Certificado energético del piso' && $lines[0]['codimpuesto'] === 'IVA21'
            && ! isset($lines[1]['referencia']) && $lines[1]['pvpunitario'] === '25.00' && (float) $lines[1]['dtopor'] === 10.0 && $lines[1]['codimpuesto'] === 'IVA10'
            && str_contains((string) $post->data()['observaciones'], $property->reference()));
        $this->check('el producto no se vuelve a dar de alta en el reintento', $calls('POST productos')->count() === 1);
        $this->check('confirmar no emite factura (ninguna llamada a facturas) y sigue sin facturar', Http::recorded(fn (Request $r) => (bool) preg_match('#/api/3/\w*factura#i', (string) parse_url($r->url(), PHP_URL_PATH)))->count() === 0 && $a->invoice_number === null);
        $c->send($a);
        $this->check('enviar otra vez un albarán ya enviado no hace nada', $calls('POST crearAlbaranCliente')->count() === 2);

        // 3. Albarán that needs conformity: refused until signed; then a timeout leaves it «pendiente de envío».
        $c->send($b);
        $this->check('sin conformidad firmada: no se envía y lo explica', $b->status === Albaran::DRAFT && str_contains((string) $b->last_error, 'conformidad') && $calls('POST crearAlbaranCliente')->count() === 2);
        $b->markConformitySigned();
        $script['POST crearAlbaranCliente'][] = 'timeout';
        $c->send($b);
        $this->check('sin respuesta: queda «Pendiente de envío» sin id externo', $b->status === Albaran::PENDING && blank($b->external_id));
        $this->check('mismo propietario: reutiliza el cliente vinculado (sin buscar ni crear)', $calls('POST clientes')->count() === 1 && $calls('GET clientes/{id}')->count() === 1);
        $this->check('artículo sin referencia: va como texto, sin producto en Factúralo', $calls('POST productos')->count() === 1 && ! isset(json_decode((string) $calls('POST crearAlbaranCliente')->last()->data()['lineas'], true)[0]['referencia']));
        $script['GET albaranclientes'][] = [['idalbaran' => 502, 'numero2' => $b->number]];
        $script['GET albaranclientes/{id}'][] = ['idalbaran' => 502, 'numero2' => $b->number, 'idestado' => 7, 'editable' => true];
        $posts = $calls('POST crearAlbaranCliente')->count();
        $c->send($b);
        $this->check('reintento tras «pendiente»: lo encuentra por número, sin segundo envío', $b->status === Albaran::SENT && $b->external_id === '502' && $calls('POST crearAlbaranCliente')->count() === $posts);

        // 4. Staff invoice the albarán in Factúralo → Facturado, invoice code and date linked back to the owner.
        $script['GET albaranclientes/{id}'][] = ['idalbaran' => 501, 'idestado' => 7, 'editable' => true];
        $c->refresh($a);
        $this->check('consultar: todavía pendiente de facturar', $a->status === Albaran::SENT && $a->invoice_number === null);
        $script['GET albaranclientes/{id}'][] = ['idalbaran' => 501, 'idestado' => 8, 'editable' => false];
        $script['GET doctransformations'][] = [['model1' => 'AlbaranCliente', 'iddoc1' => 501, 'model2' => 'FacturaCliente', 'iddoc2' => 9]];
        $script['GET facturaclientes/{id}'][] = ['idfactura' => 9, 'codigo' => 'FAC2026A42', 'fecha' => '21-11-2026', 'total' => 231, 'editable' => true];
        $script['GET albaranclientes/{id}'][] = ['idalbaran' => 502, 'idestado' => 7, 'editable' => true];
        // Only the test's albaranes may be «Enviado» here, or real ones would consume the scripted answers.
        Albaran::query()->where('status', Albaran::SENT)->whereNotIn('id', [$a->getKey(), $b->getKey()])->update(['status' => Albaran::DRAFT]);
        BillingConnector::syncSent();
        $a->refresh();
        $this->check('facturado: código y fecha de la factura de Factúralo', $a->status === Albaran::INVOICED && $a->invoice_number === 'FAC2026A42' && $a->invoice_date?->toDateString() === '2026-11-21' && $a->invoice_external_id === '9');
        $this->check('la factura se busca por el enlace albarán → factura de Factúralo', $calls('GET doctransformations')->contains(fn (Request $r): bool => ($query($r)['filter'] ?? []) == ['model1' => 'AlbaranCliente', 'iddoc1' => '501', 'model2' => 'FacturaCliente']));
        $ownerInvoices = Albaran::query()->where('party_id', $owner->getKey())->whereNotNull('invoice_number')->pluck('invoice_number')->all();
        $this->check('ficha del propietario: la factura aparece en sus albaranes', $ownerInvoices === ['FAC2026A42']);
        $this->check('un albarán facturado ya no se puede editar', ! $a->isEditable());

        // 5. Factúralo down while checking: error kept, the albarán keeps its state and ids.
        $script['GET albaranclientes/{id}'][] = ['_http' => 500];
        $c->refresh($b);
        $this->check('Factúralo caído al consultar: motivo guardado sin perder estado ni id', $b->status === Albaran::SENT && $b->external_id === '502' && str_contains((string) $b->last_error, 'HTTP 500'));

        // 6. Another owner whose NIF already exists in Factúralo (client created there by hand): reused, not created.
        $other = $newOwner('Empresa Ficticia SL', 'B00000000');
        $d = $make($other, null, [['article_id' => null, 'description' => 'Gestión puntual', 'quantity' => 1, 'unit_price' => 80, 'discount_pct' => 0, 'vat_pct' => 21]], now()->addDays(5)->toDateString());
        $script['GET clientes'][] = [['codcliente' => 'C0042', 'cifnif' => 'B00000000']];
        $script['POST crearAlbaranCliente'][] = ['doc' => ['idalbaran' => 503, 'codigo' => 'ALB2026A3']];
        $c->send($d);
        $last = $calls('POST crearAlbaranCliente')->last();
        $this->check('cliente que ya estaba en Factúralo con el mismo NIF: se reutiliza sin crear otro', $d->status === Albaran::SENT && $last->data()['codcliente'] === 'C0042' && $calls('POST clientes')->count() === 1);
        $this->check('fecha de servicio futura: va con la fecha de hoy (Factúralo no admite fechas futuras)', $last->data()['fecha'] === today()->toDateString());

        // 7. Factúralo rejects the product (e.g. key without permission on «productos») → Error with the article named.
        $e = $make($other, null, [['article_id' => BillingArticle::query()->create(['team_id' => $property->team_id, 'reference' => 'PRB-NUEVO', 'name' => 'Servicio nuevo', 'unit_price' => 10, 'vat_pct' => 21])->getKey(), 'description' => 'Servicio nuevo', 'quantity' => 1, 'unit_price' => 10, 'discount_pct' => 0, 'vat_pct' => 21]]);
        $script['POST productos'][] = ['_http' => 403, 'error' => 'forbidden'];
        $c->send($e);
        $this->check('producto rechazado: Error con el artículo y sin enviar el albarán', $e->status === Albaran::ERROR && str_contains((string) $e->last_error, 'PRB-NUEVO') && $calls('POST crearAlbaranCliente')->count() === $posts + 1);
    }

    private function check(string $label, bool $ok): void
    {
        $ok ? $this->pass++ : $this->fail++;
        $this->line(($ok ? 'PASS  ' : 'FAIL  ').$label);
    }
}
