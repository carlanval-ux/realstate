<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PortalListing;
use App\Filament\PropertyPortals;
use App\Models\User;
use App\Portals\Pisos\PisosConnector;
use App\Portals\Portals;
use Filament\Facades\Filament;
use Illuminate\Console\Scheduling\Schedule;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\RealEstate\PropertiesFilament\Resources\PropertyResource\Pages\ListProperties;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Liberu\RealEstate\MediaAndDocuments\Models\MediaDocument;
use Liberu\RealEstate\Properties\Models\Property;

/**
 * End-to-end test of the pisos.com connector with SIMULATED pisos.com responses (Http::fake): real HTTP never
 * leaves the server. Runs inside a database transaction that is rolled back, so the properties are untouched.
 *
 *   php artisan clarto:pisos-prueba [--inmuebles=CL-2026-00004,CL-2026-00005]
 */
final class TestPisosConnector extends Command
{
    protected $signature = 'clarto:pisos-prueba {--inmuebles=CL-2026-00004,CL-2026-00005}';

    protected $description = 'Test the pisos.com connector against simulated API responses (nothing is kept)';

    private int $pass = 0;

    private int $fail = 0;

    public function handle(): int
    {
        [$a, $b] = collect(explode(',', (string) $this->option('inmuebles')))
            ->map(fn (string $ref) => Property::query()->with(['city', 'district'])->whereKey((int) Str::afterLast(trim($ref), '-'))->firstOrFail())
            ->all();

        DB::beginTransaction();
        try {
            $this->scenario($a, $b);
        } finally {
            DB::rollBack();
        }

        $this->line(sprintf('RESULT: %d passed, %d failed (pisos.com simulated with Http::fake; database rolled back)', $this->pass, $this->fail));

        return $this->fail === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function scenario(Property $a, Property $b): void
    {
        $zones = [$a->district?->slug ?? $a->city?->slug => 'M-PRUEBA-A', $b->district?->slug ?? $b->city?->slug => 'M-PRUEBA-B'];
        config(['pisos.enabled' => false, 'pisos.driver' => 'api', 'pisos.api_key' => 'clave-de-prueba', 'pisos.user_id' => '4242',
            'pisos.base_url' => 'https://api.pisos.com/v5', 'pisos.operaciones.sale' => 'venta', 'pisos.operaciones.rent' => 'alquiler',
            'pisos.contacto.telefono' => '+34 600 000 000', 'pisos.zonas' => []]);
        foreach ([$a, $b] as $p) {
            $p->forceFill(['postal_code' => '46520'])->saveQuietly();
        }

        // What the simulated pisos.com answers next, per kind of call.
        $script = ['post' => [], 'put' => [], 'get' => [], 'deactivate' => [], 'media' => [], 'list' => []];
        Http::preventStrayRequests();
        Http::fake(function (Request $r) use (&$script) {
            $kind = match (true) {
                str_contains($r->url(), '/media/saveMultimediaXml') => 'media',
                str_contains($r->url(), '/inmueble/deactivate/') => 'deactivate',
                str_contains($r->url(), '/usuarios/') => 'list',
                $r->method() === 'POST' => 'post',
                $r->method() === 'PUT' => 'put',
                default => 'get',
            };
            $next = array_shift($script[$kind]) ?? ['Respuesta' => ['StatusCodeId' => 200, 'Text' => 'OK']];

            return $next === 'timeout' ? Http::failedConnection() : ($next instanceof \Closure ? $next() : Http::response($next, $next['_http'] ?? 200));
        });
        $count = fn (string $needle, ?string $method = null): int => Http::recorded(fn (Request $r) => str_contains($r->url(), $needle) && ($method === null || $r->method() === $method))->count();
        $c = PisosConnector::make();

        // 0. Real sends switched off → refused, nothing sent.
        $l = $c->publish($a);
        $this->check('envíos desactivados: no se envía nada', $count('pisos.com') === 0 && str_contains((string) $l->last_error, 'desactivados'));
        config(['pisos.enabled' => true]);

        // 1. Missing required data → listed, nothing sent.
        $l = $c->publish($a);
        $this->check('faltan datos: indica el código de zona', $count('pisos.com') === 0 && str_contains((string) $l->last_error, 'Código de zona'));
        config(['pisos.zonas' => $zones]);

        // 2. Create.
        $script['post'][] = ['ID' => 991560325.1097, 'Estado' => -4, 'Respuesta' => ['StatusCodeId' => 200, 'Text' => 'OK']];
        $l = $c->publish($a);
        $post = Http::recorded(fn (Request $r) => $r->method() === 'POST' && str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/inmueble'))->last()[0] ?? null;
        $body = $post?->data() ?? [];
        $this->check('alta: aceptado con identificador de pisos.com', $l->status === PortalListing::ACCEPTED && $l->external_id === '991560325.1097');
        $this->check('alta: clave en la URL y precio', str_contains((string) $post?->url(), 'apiKey=clave-de-prueba') && (float) ($body['Precios'][0]['Valor'] ?? 0) === (float) $a->price);
        $this->check('alta: título y descripción aprobados + referencia', str_contains((string) ($body['Descripcion'] ?? ''), (string) $a->title) && str_contains((string) $body['Descripcion'], $a->reference()));
        $this->check('alta: sin dirección exacta ni notas internas', ! str_contains(json_encode($body, JSON_UNESCAPED_UNICODE), (string) $a->address) && ! str_contains(json_encode($body), 'internal'));
        $this->check('alta: contacto autorizado y zona', ($body['Usuario']['Telefono'] ?? null) === '+34 600 000 000' && ($body['Localizacion']['CodigoZonaGeo'] ?? null) === 'M-PRUEBA-A');
        $photos = MediaDocument::query()->where('property_id', $a->getKey())->where('kind', 'photo')->count();
        $media = Http::recorded(fn (Request $r) => str_contains($r->url(), 'saveMultimediaXml'))->last()[0] ?? null;
        $this->check("alta: fotos enviadas en orden ({$photos})", $photos > 0 && count($media?->data()['mediasList'] ?? []) === $photos && ($media->data()['mediasList'][0]['Orden'] ?? 0) === 1);

        // 3. Retrying the create never duplicates: it becomes an update.
        $c->publish($a);
        $this->check('reintento de alta: no crea un duplicado (PUT, un solo POST)', $count('/v5/inmueble?', 'POST') === 1 && $count('/v5/inmueble?', 'PUT') === 1);

        // 4. Price and photos change.
        $a->forceFill(['price' => (float) $a->price - 10000])->saveQuietly();
        $first = MediaDocument::query()->where('property_id', $a->getKey())->where('kind', 'photo')->orderByDesc('sort_order')->first();
        $first?->forceFill(['sort_order' => -1])->saveQuietly();
        $l = $c->update($a);
        $put = Http::recorded(fn (Request $r) => $r->method() === 'PUT')->last()[0];
        $media = Http::recorded(fn (Request $r) => str_contains($r->url(), 'saveMultimediaXml'))->last()[0];
        $this->check('cambio de precio: PUT con el precio nuevo y el mismo ID', (float) $put->data()['Precios'][0]['Valor'] === (float) $a->price && (string) $put->data()['ID'] === '991560325.1097');
        $this->check('cambio de fotos: nuevo orden enviado', str_contains((string) $media->data()['mediasList'][0]['Datos']['UrlCDATA'], 'documento='.$first?->getKey()) || str_contains((string) $media->data()['mediasList'][0]['Datos']['UrlCDATA'], '/'.$first?->getKey().'/'));

        // 5. Status: active on the portal → published.
        $script['get'][] = ['ID' => 991560325.1097, 'Estado' => 0];
        $l = $c->refresh($a);
        $this->check('consultar estado: publicado (Estado 0)', $l->status === PortalListing::PUBLISHED);

        // 6. Rejection with reason, then a successful retry.
        $script['put'][] = ['Respuesta' => ['StatusCodeId' => 400, 'ErrorCodeId' => 'E-SUPERFICIE', 'Text' => 'La superficie no es válida']];
        $l = $c->update($a);
        $this->check('rechazo: estado rechazado con motivo y código', $l->status === PortalListing::REJECTED && str_contains((string) $l->last_error, 'La superficie no es válida') && str_contains((string) $l->last_error, 'E-SUPERFICIE'));
        $l = $c->update($a);
        $this->check('reintento tras rechazo: aceptado y motivo limpio', $l->status === PortalListing::ACCEPTED && blank($l->last_error));

        // 7. Create without confirmation (timeout) → "enviado"; the retry finds it by reference, no duplicate.
        $script['post'][] = 'timeout';
        $l = $c->publish($b);
        $this->check('alta sin respuesta: queda «enviado» sin identificador', $l->status === PortalListing::SENT && blank($l->external_id));
        $script['list'][] = [['ID' => 77001, 'Descripcion' => 'Texto … Ref. '.$b->reference()]];
        $posts = $count('/v5/inmueble?', 'POST');
        $l = $c->publish($b);
        $this->check('reintento tras «enviado»: lo encuentra por referencia y actualiza (sin segundo POST)', $l->external_id === '77001' && $count('/v5/inmueble?', 'POST') === $posts && $l->status === PortalListing::ACCEPTED);

        // 8. Withdraw: deactivated on the portal, the property stays as it was in the panel.
        $statusBefore = $a->fresh()->status;
        $l = $c->withdraw($a);
        $this->check('retirar: desactivado en pisos.com (deactivate/{id}/{userId})', $l->status === PortalListing::WITHDRAWN && $count('/inmueble/deactivate/991560325.1097/4242') === 1);
        $this->check('retirar: el inmueble sigue igual en el panel', $a->fresh() !== null && $a->fresh()->status === $statusBefore);

        // 9. Portal down.
        $script['get'][] = ['_http' => 503];
        $l = $c->refresh($a);
        $this->check('pisos.com caído: error sin perder el identificador', $l->status === PortalListing::ERROR && $l->external_id === '991560325.1097');

        $this->selection($a, $b, $count, $script);
    }

    /** 10. Portal registry and choosing what is sent from the property list (owner: «Debo poder seleccionar los inmuebles que envío»). */
    /** @param array<string, list<mixed>> $script what the simulated pisos.com answers next (one Http::fake per process) */
    private function selection(Property $a, Property $b, \Closure $count, array &$script): void
    {
        $this->check('registro de portales: pisos.com es el único y tiene su conector', Portals::names() === ['pisos' => 'pisos.com'] && Portals::connector('pisos') instanceof PisosConnector);
        $this->check('nada se envía solo: ninguna tarea programada envía a portales', ! collect(app(Schedule::class)->events())
            ->contains(fn ($e): bool => (bool) preg_match('/pisos|portal/i', (string) $e->command.' '.(string) $e->description)));

        // Bulk «Enviar a pisos.com…» over the two selected properties (a was withdrawn, b accepted): both go out as updates.
        $puts = $count('/v5/inmueble?', 'PUT');
        $sent = Portals::runMany('pisos', 'publish', collect([$a->fresh(), $b->fresh()]));
        $this->check('envío masivo de los seleccionados: 2 enviados (actualización, sin duplicar altas)', $sent['ok'] === 2 && $sent['failed'] === [] && $count('/v5/inmueble?', 'PUT') === $puts + 2,
            json_encode($sent, JSON_UNESCAPED_UNICODE));
        $this->check('la confirmación muestra cuántos se envían', str_contains(PropertyPortals::confirmation('pisos', collect([$a, $b])), '2'));

        // Property list: filters, column and bulk actions (as staff of the properties' team).
        $team = Team::query()->findOrFail($a->team_id);
        $staff = User::query()->findOrFail($team->user_id);
        $staff->forceFill(['current_team_id' => $team->getKey()]);
        auth()->guard('web')->setUser($staff);
        Filament::setCurrentPanel('admin');
        Filament::setTenant($team, isQuiet: true);
        $ids = fn (?string $value): array => \Livewire\Livewire::test(ListProperties::class)->set('tableFilters.portal_pisos.value', $value)
            ->instance()->getFilteredTableQuery()->pluck('real_estate_properties.id')->map(fn ($id) => (int) $id)->all();
        $published = $ids('publicado');
        $notPublished = $ids('no_publicado');
        $this->check('filtros «Publicado en pisos.com» / «No publicado en pisos.com»', in_array($a->id, $published, true) && in_array($b->id, $published, true)
            && ! in_array($a->id, $notPublished, true) && ! in_array($b->id, $notPublished, true));
        $script['get'][] = ['_http' => 503];
        $c = PisosConnector::make();
        $c->refresh($b);
        $this->check('filtro «Con error en pisos.com»', in_array($b->id, $ids('error'), true) && ! in_array($a->id, $ids('error'), true));
        $list = \Livewire\Livewire::test(ListProperties::class)->set('tableFilters.portal_pisos.value', 'publicado');
        $this->check('columna «Portales» con el estado en cada portal', str_contains((string) $list->html(), 'pisos.com: '));
        $names = collect($list->instance()->getTable()->getToolbarActions())
            ->flatMap(fn ($action) => method_exists($action, 'getActions') ? array_values($action->getActions()) : [$action])->map(fn ($action) => $action->getName())->all();
        $this->check('acciones masivas «Enviar a pisos.com…» y «Retirar de pisos.com…» en el listado', in_array('portal_send_pisos', $names, true) && in_array('portal_withdraw_pisos', $names, true),
            implode(', ', $names));

        // Bulk «Retirar de pisos.com…».
        $withdrawn = Portals::runMany('pisos', 'withdraw', collect([$a->fresh(), $b->fresh()]));
        $this->check('retirada masiva de los seleccionados: 2 retirados', $withdrawn['ok'] === 2
            && PortalListing::query()->whereIn('property_id', [$a->id, $b->id])->where('portal', 'pisos')->where('status', PortalListing::WITHDRAWN)->count() === 2, json_encode($withdrawn));
    }

    private function check(string $label, bool $ok): void
    {
        $ok ? $this->pass++ : $this->fail++;
        $this->line(($ok ? 'PASS  ' : 'FAIL  ').$label);
    }
}
