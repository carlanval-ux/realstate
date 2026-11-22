<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Collaborators\Collaborations;
use App\Collaborators\Collaborator;
use App\Collaborators\CollaboratorProperty;
use App\Collaborators\Import\CollaboratorImport;
use App\Collaborators\Import\CollaboratorImports;
use App\Collaborators\Import\ImportedProperty;
use App\Collaborators\Import\ImportHttp;
use App\Collaborators\Import\ListingAi;
use App\Filament\Resources\CollaboratorResource\Pages\EditCollaborator;
use App\Models\User;
use App\Modules\ClartoModules;
use Carbon\CarbonInterval;
use Filament\Facades\Filament;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\RealEstate\MediaAndDocuments\Models\MediaDocument;
use Liberu\RealEstate\Properties\Models\Property;
use RuntimeException;
use Throwable;

/**
 * «Importación de inmuebles» of collaborators end to end with FICTITIOUS data and SIMULATED sites: local fixtures
 * (database/fixtures/importacion) served through Http::fake — no real third-party site is ever contacted — and a
 * simulated AI. Covers the authorization gate (no request at all without it), Kyero and generic JSON feeds, the web
 * crawl (robots.txt, pause, User-Agent, page cap, JSON-LD → OpenGraph → AI), drafts never in the public catalog,
 * updates that respect staff edits, «retirado» instead of deleting, no owner data, no GoHighLevel. Rolled back.
 *
 *   php artisan clarto:importacion-prueba
 */
final class TestCollaboratorImport extends Command
{
    protected $signature = 'clarto:importacion-prueba';

    protected $description = 'Test the collaborator property importer with local fixtures (simulated sites, database rolled back)';

    private const FEED = 'https://feeds.colaborador-ficticio.test/kyero.xml';

    private const JSON = 'https://feeds.colaborador-ficticio.test/generico.json';

    private const WEB = 'https://web.colaborador-ficticio.test';

    private int $pass = 0;

    private int $fail = 0;

    private string $feed = '';

    private int $feedStatus = 200;

    /** @var list<float> */
    private array $slept = [];

    private int $aiCalls = 0;

    /** @var list<int> */
    private array $collaborators = [];

    public function handle(): int
    {
        config(['queue.default' => 'sync', 'importacion.web.pausa' => 1, 'importacion.web.max_paginas' => 50, 'ghl.token' => 'token-prueba', 'ghl.api_url' => 'https://ghl.prueba']);
        $this->fakeSites();
        Sleep::fake();
        Sleep::whenFakingSleep(function (CarbonInterval $duration): void {
            $this->slept[] = $duration->totalSeconds;
        });
        $this->fakeAi();

        DB::beginTransaction();
        try {
            $this->scenarios();
        } catch (Throwable $e) {
            $this->check('sin excepciones inesperadas', false, $e::class.': '.$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine());
        } finally {
            DB::rollBack();
            foreach ($this->collaborators as $id) {
                Storage::disk('public')->deleteDirectory('inmuebles/importados/'.$id);
            }
            auth()->guard('web')->logout();
        }

        $this->line(sprintf('RESULT: %d passed, %d failed (sites and AI simulated; database rolled back)', $this->pass, $this->fail));

        return $this->fail === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function scenarios(): void
    {
        $team = Team::query()->oldest()->firstOrFail();
        $teamId = (int) $team->getKey();
        $staff = User::query()->findOrFail($team->user_id);
        $staff->forceFill(['current_team_id' => $teamId]);
        $this->actAs($staff);
        ClartoModules::enable(Collaborations::MODULE);
        $tag = Str::upper(Str::random(5));
        $propertiesBefore = Property::withTrashed()->count();

        $inmo = $this->collaborator($teamId, $staff, "Inmo Ficticia Importaciones {$tag} SL");
        $this->feed = $this->fixture('kyero.xml');

        // 1. The gate: configured but without written authorization → nothing runs, not one request.
        $import = CollaboratorImport::query()->create(['team_id' => $teamId, 'collaborator_id' => $inmo->id, 'source' => 'feed', 'feed_url' => self::FEED, 'feed_format' => 'kyero']);
        $requests = $this->requests();
        $r = CollaboratorImports::run($import);
        $this->check('sin autorización por escrito: no se ejecuta (estado «sin_autorizacion»)', ! $r['ok'] && $r['status'] === 'sin_autorizacion' && $import->refresh()->last_status === 'sin_autorizacion', json_encode($r));
        $import->forceFill(['requested_at' => now()])->save();
        CollaboratorImports::runDue(daily: false);
        CollaboratorImports::runDue(daily: true);
        $this->check('ni manual («Importar ahora») ni programada: cero peticiones HTTP y ningún inmueble creado', $this->requests() === $requests
            && Property::withTrashed()->count() === $propertiesBefore && $import->refresh()->requested_at === null);
        $this->check('la ficha explica el motivo (aún no hay acuerdos firmados con colaboradores)', str_contains(__('importacion.gate.none_signed'), 'ningún acuerdo firmado')
            && str_contains(__('importacion.gate.refused'), 'autorización por escrito'));

        // 2. Settings saved from the collaborator's record (relationship section) — authorization date included.
        Filament::setCurrentPanel('admin');
        Filament::setTenant($team, isQuiet: true);
        $form = \Livewire\Livewire::test(EditCollaborator::class, ['record' => $inmo->id]);
        $form->set('data.import.source', 'feed')->set('data.import.feed_url', self::FEED)->set('data.import.feed_format', 'kyero')
            ->set('data.import.schedule', 'diario')->set('data.import.authorized_at', now()->subDay()->toDateString())->call('save');
        $import->refresh();
        $this->check('la sección «Importación de inmuebles» de la ficha guarda origen, formato, programación y fecha de autorización',
            $import->source === 'feed' && $import->feed_format === 'kyero' && $import->schedule === 'diario' && $import->isAuthorized(),
            json_encode($form->errors()->toArray()).' '.json_encode($import->only(['source', 'feed_format', 'authorized_at'])));
        $html = (string) \Livewire\Livewire::test(EditCollaborator::class, ['record' => $inmo->id])->html();
        $this->check('la ficha muestra la sección, la explicación de la autorización y «Importar ahora»', str_contains($html, e(__('importacion.section')))
            && str_contains($html, e(__('importacion.gate.title'))) && str_contains($html, e(__('importacion.actions.now'))));

        // 3. Kyero feed, authorized: drafts, linked to the collaborator, marked with their source, photos downloaded.
        $r = CollaboratorImports::run($import);
        $rows = ImportedProperty::query()->where('collaborator_id', $inmo->id)->get()->keyBy('external_ref');
        $a = Property::query()->find($rows['FIC-A1']->property_id ?? 0);
        $b = Property::query()->find($rows['FIC-B2']->property_id ?? 0);
        $c = Property::query()->find($rows['FIC-C3']->property_id ?? 0);
        $this->check('feed Kyero: 3 inmuebles nuevos, estado correcto', $r['status'] === 'ok' && $r['counts']['nuevos'] === 3 && $a && $b && $c, json_encode($r));
        $ids = array_filter([$a?->id, $b?->id, $c?->id]);
        $this->check('entran como borrador: ninguno en el catálogo público (/api/v1/catalogo) por defecto',
            Property::query()->whereKey($ids)->whereIn('status', array_keys(config('catalogo.estados_publicos')))->count() === 0
            && Property::query()->whereKey($ids)->where('status', 'draft')->count() === 3);
        $this->check('vinculados al colaborador («aportado», sin avisos) y marcados con su origen',
            CollaboratorProperty::query()->where('collaborator_id', $inmo->id)->where('relation', 'aportado')->whereIn('property_id', $ids)->count() === 3
            && $rows->every(fn (ImportedProperty $row): bool => $row->source === 'feed' && $row->status === 'activo')
            && str_contains((string) $a?->internal_notes, $inmo->name) && str_contains((string) $a?->internal_notes, 'FIC-A1')
            && DB::table('clarto_collaborator_notices')->where('collaborator_id', $inmo->id)->count() === 0);
        $this->check('campos: precio, operación, tipo, m², habitaciones, baños, municipio/zona',
            (float) $a?->price === 250000.0 && $a?->deal_type?->value === 'sale' && $a?->property_type === 'villa' && (float) $a?->area_sqft === 180.0
            && $a?->bedrooms === 4 && $a?->bathrooms === 3 && $a?->address === 'Urbanización Inventada, Pueblo Ficticio'
            && $b?->deal_type?->value === 'rent' && $b?->property_type === 'apartment' && $c?->property_type === 'townhouse',
            json_encode($a?->only(['price', 'deal_type', 'property_type', 'area_sqft', 'address', 'title'])));
        $photos = MediaDocument::query()->whereIn('property_id', $ids)->where('kind', 'photo')->get();
        $this->check('fotos descargadas al disco público (3), en orden, como fotos del inmueble', $photos->count() === 3
            && $photos->every(fn (MediaDocument $d): bool => Storage::disk('public')->exists($d->path) && str_starts_with($d->path, 'inmuebles/importados/'.$inmo->id.'/')));
        $this->check('una foto que apunta a una dirección interna (169.254…) no se pide ni se guarda',
            Http::recorded(fn (ClientRequest $q): bool => str_contains($q->url(), '169.254'))->isEmpty() && ! isset($rows['FIC-A1']->photos['http://169.254.169.254/latest/meta-data/foto.jpg']));
        $all = json_encode(Property::query()->whereKey($ids)->get()->toArray(), JSON_UNESCAPED_UNICODE);
        $this->check('ningún dato personal: ni el propietario ni los teléfonos/correos de la descripción', ! str_contains($all, 'Propietario Ficticio')
            && ! str_contains($all, '611 222 333') && ! str_contains($all, '600 123 456') && ! str_contains($all, 'propietario.uno@') && ! str_contains($all, 'Agencia Ficticia Feed')
            && str_contains((string) $a?->description, '250.000 €'), Str::limit((string) $a?->description, 200));
        $this->check('cambios registrados (auditoría de colaboradores)', DB::table('activity_log')->where('log_name', 'colaboradores')->where('event', 'importado')
            ->whereIn('subject_id', $ids)->count() === 3);

        // 4. Same feed again: nothing changes, no photo downloaded twice.
        $photoRequests = $this->requests('img.colaborador-ficticio.test');
        $r = CollaboratorImports::run($import);
        $this->check('segunda lectura igual: 3 sin cambios, ninguna foto descargada de nuevo', $r['counts']['sin_cambios'] === 3 && $r['counts']['nuevos'] === 0
            && $this->requests('img.colaborador-ficticio.test') === $photoRequests, json_encode($r['counts']));

        // 5. Staff edits the title of A; the source changes A's price and drops B.
        $a->forceFill(['title' => 'Título revisado por el equipo'])->save();
        $this->feed = str_replace(['<price>250000</price>', '<ref>FIC-B2</ref>'], ['<price>239000</price>', '<ref>FIC-B2-NO</ref>'], $this->fixture('kyero.xml'));
        $this->feed = (string) preg_replace('~<property>\s*<id>102</id>.*?</property>~s', '', $this->feed);
        $r = CollaboratorImports::run($import);
        $a->refresh();
        $b->refresh();
        $this->check('cambio en origen: se actualiza el precio y se respeta el título editado por el equipo', (float) $a->price === 239000.0 && $a->title === 'Título revisado por el equipo'
            && $r['counts']['actualizados'] === 1, json_encode($r['counts']));
        $this->check('anuncio desaparecido: «retirado» (inmueble retirado, nunca borrado)', $r['counts']['retirados'] === 1 && ImportedProperty::query()->find($rows['FIC-B2']->id)?->status === 'retirado'
            && $b->status?->value === 'withdrawn' && ! $b->trashed() && Property::withTrashed()->whereKey($ids)->whereNotNull('deleted_at')->count() === 0);
        $this->check('el cambio queda en la auditoría con valor anterior y nuevo', DB::table('activity_log')->where('event', 'importacion_actualizado')->where('subject_id', $a->id)
            ->get()->contains(fn ($log): bool => str_contains((string) $log->properties, '239000')) && DB::table('activity_log')->where('event', 'importacion_retirado')->where('subject_id', $b->id)->exists());

        // 6. Safety: a broken or empty read retires nothing; a published property stays published.
        $c->forceFill(['status' => 'available'])->save();
        $this->feedStatus = 500;
        $r = CollaboratorImports::run($import);
        $this->feedStatus = 200;
        $this->feed = '<?xml version="1.0"?><root><kyero><feed_version>3</feed_version></kyero></root>';
        $r2 = CollaboratorImports::run($import);
        $this->check('feed caído (500) o vacío: estado «error»/«parcial» y no se retira nada', $r['status'] === 'error' && $r2['counts']['retirados'] === 0
            && $c->refresh()->status?->value === 'available' && ImportedProperty::query()->where('collaborator_id', $inmo->id)->where('status', 'activo')->count() === 2, $r['status'].' '.json_encode($r2));
        $this->check('publicar sigue siendo decisión manual del equipo (el que el equipo publicó sigue publicado)', $c->status?->value === 'available');

        // 7. B comes back: reactivated as a draft (never straight to the public catalog).
        $this->feed = $this->fixture('kyero.xml');
        $r = CollaboratorImports::run($import);
        $this->check('anuncio que reaparece: vuelve a «activo» como borrador', ImportedProperty::query()->find($rows['FIC-B2']->id)?->status === 'activo'
            && $b->refresh()->status?->value === 'draft', json_encode($r['counts']));

        // 8. Generic JSON feed with field mapping (second collaborator).
        $gen = $this->collaborator($teamId, $staff, "Agencia Ficticia JSON {$tag}");
        $jsonImport = CollaboratorImport::query()->create(['team_id' => $teamId, 'collaborator_id' => $gen->id, 'source' => 'feed', 'feed_url' => self::JSON, 'feed_format' => 'json',
            'authorized_at' => now()->subDays(3), 'feed_mapping' => ['elementos' => 'datos.anuncios', 'referencia' => 'codigo', 'precio' => 'importe', 'operacion' => 'modalidad',
                'tipo' => 'clase', 'm2' => 'detalle.superficie', 'habitaciones' => 'detalle.dormitorios', 'banos' => 'detalle.aseos', 'municipio' => 'localidad',
                'zona' => 'barrio', 'descripcion' => 'texto', 'fotos' => 'galeria']]);
        $r = CollaboratorImports::run($jsonImport);
        $g1 = Property::query()->find(ImportedProperty::query()->where('collaborator_id', $gen->id)->where('external_ref', 'GEN-1')->value('property_id'));
        $g2 = Property::query()->find(ImportedProperty::query()->where('collaborator_id', $gen->id)->where('external_ref', 'GEN-2')->value('property_id'));
        $this->check('feed JSON genérico con correspondencia: «189.500 €», «92,5 m²», ático→piso, alquiler de local', $r['counts']['nuevos'] === 2
            && (float) $g1?->price === 189500.0 && (float) $g1?->area_sqft === 92.5 && $g1?->property_type === 'apartment' && $g1?->bedrooms === 3
            && (float) $g2?->price === 1200.0 && $g2?->deal_type?->value === 'rent' && $g2?->property_type === 'commercial'
            && MediaDocument::query()->where('property_id', $g1?->id)->where('kind', 'photo')->count() === 1
            && ! str_contains((string) json_encode($g1?->toArray(), JSON_UNESCAPED_UNICODE), 'Propietaria Ficticia'), json_encode($r));

        // 9. Website mode: sitemap, robots.txt, pause, User-Agent, JSON-LD → OpenGraph → AI.
        $web = $this->collaborator($teamId, $staff, "Web Ficticia {$tag}");
        $webImport = CollaboratorImport::query()->create(['team_id' => $teamId, 'collaborator_id' => $web->id, 'source' => 'web', 'web_url' => self::WEB.'/sitemap.xml',
            'web_pattern' => '/*inmueble/*', 'authorized_at' => now()->subDays(2), 'schedule' => 'manual']);
        $this->slept = [];
        $this->aiCalls = 0;
        $before = Http::recorded()->count();
        $r = CollaboratorImports::run($webImport);
        $webRequests = Http::recorded()->slice($before)->values();
        $webRows = ImportedProperty::query()->where('collaborator_id', $web->id)->get();
        $w1 = Property::query()->find($webRows->first(fn ($row) => str_ends_with((string) $row->source_url, 'fic-web-1'))?->property_id);
        $w2 = Property::query()->find($webRows->first(fn ($row) => str_ends_with((string) $row->source_url, 'fic-web-2'))?->property_id);
        $this->check('web: 2 fichas importadas desde el sitemap, marcadas «web»', $r['status'] === 'ok' && $r['counts']['nuevos'] === 2 && $webRows->every(fn ($row) => $row->source === 'web'), json_encode($r));
        $this->check('robots.txt respetado: /privado/ nunca se pide; tampoco otra web ni páginas fuera del patrón',
            $webRequests->every(fn (array $pair): bool => ! str_contains($pair[0]->url(), '/privado/') && ! str_contains($pair[0]->url(), 'otra-web-ficticia') && ! str_ends_with($pair[0]->url(), '/contacto'))
            && $webRequests->contains(fn (array $pair): bool => str_ends_with($pair[0]->url(), '/robots.txt')));
        $this->check('User-Agent que identifica a CLARTO en todas las peticiones', $webRequests->isNotEmpty()
            && $webRequests->every(fn (array $pair): bool => str_contains($pair[0]->header('User-Agent')[0] ?? '', 'ClartoBot')));
        $pages = $webRequests->filter(fn (array $pair): bool => str_starts_with($pair[0]->url(), self::WEB) && ! str_contains($pair[0]->url(), '/fotos/'))->count();
        $this->check('una petición cada pocos segundos (Crawl-delay 2 s de robots.txt > pausa 1 s)', count(array_filter($this->slept, fn (float $s): bool => $s >= 2.0)) >= $pages - 1,
            'pausas '.json_encode($this->slept).' páginas '.$pages);
        $this->check('JSON-LD primero: precio, m², habitaciones, municipio; sin el logo ni el teléfono de la agencia; sin IA para esa ficha',
            (float) $w1?->price === 215000.0 && (float) $w1?->area_sqft === 88.0 && $w1?->bedrooms === 3 && $w1?->property_type === 'apartment' && $w1?->deal_type?->value === 'sale'
            && $w1?->address === 'Ciudad Imaginaria' && $w1?->title === 'Piso ficticio luminoso' && ! str_contains((string) $w1?->description, '611 000 111')
            && MediaDocument::query()->where('property_id', $w1?->id)->where('kind', 'photo')->count() === 3
            && ! collect($webRows->firstWhere('property_id', $w1?->id)?->photos ?? [])->keys()->contains(fn ($u) => str_contains($u, 'logo')),
            json_encode($w1?->only(['price', 'area_sqft', 'title', 'address', 'description'])));
        $this->check('solo OpenGraph: el resto lo completa la IA (simulada) — 1 sola llamada', $this->aiCalls === 1 && (float) $w2?->price === 340000.0
            && $w2?->property_type === 'house' && $w2?->bedrooms === 4 && $w2?->address === 'Zona Norte Ficticia, Pueblo Ficticio' && $w2?->title === 'Casa ficticia con jardín',
            'IA '.$this->aiCalls.' '.json_encode($w2?->only(['price', 'property_type', 'address'])));
        config(['importacion.web.max_paginas' => 3]);
        $r = CollaboratorImports::run($webImport);
        config(['importacion.web.max_paginas' => 50]);
        $this->check('límite de páginas por ejecución: se para, queda «parcial» y no retira nada', $r['status'] === 'parcial' && $r['counts']['paginas'] <= 3 && $r['counts']['retirados'] === 0
            && ImportedProperty::query()->where('collaborator_id', $web->id)->where('status', 'activo')->count() === 2, json_encode($r));

        // 10. Guards and wiring.
        $blocked = collect(['http://127.0.0.1/feed.xml', 'http://localhost/x', 'file:///etc/passwd', 'http://10.0.0.5/x', 'gopher://x.test/'])
            ->every(function (string $url): bool {
                try {
                    ImportHttp::assertSafe($url);

                    return false;
                } catch (RuntimeException) {
                    return true;
                }
            });
        $this->check('nunca a direcciones internas ni esquemas raros (127.0.0.1, localhost, 10.x, file:, gopher:)', $blocked);
        $events = collect(app(Schedule::class)->events())->map(fn ($e) => (string) $e->command)->implode("\n");
        $this->check('programado: diaria a las 05:30 y «Importar ahora» cada minuto (solo si hay solicitudes)', str_contains($events, 'clarto:importar-colaboradores --diario')
            && substr_count($events, 'clarto:importar-colaboradores') === 2);
        $inmo->forceFill(['status' => Collaborator::INACTIVE])->save();
        $before = $this->requests();
        $r = CollaboratorImports::run($import->refresh());
        $this->check('colaborador dado de baja: no se importa', $r['status'] === 'error' && $this->requests() === $before);
        $this->check('nada sale hacia GoHighLevel', Http::recorded(fn (ClientRequest $q): bool => str_starts_with($q->url(), 'https://ghl.prueba') || str_contains($q->url(), 'leadconnectorhq'))->isEmpty());
        $this->check('todas las peticiones fueron a sitios simulados (*.test)', Http::recorded()->every(fn (array $pair): bool => str_ends_with((string) parse_url($pair[0]->url(), PHP_URL_HOST), '.test')));
    }

    private function collaborator(int $teamId, User $staff, string $name): Collaborator
    {
        $c = Collaborator::query()->create(['team_id' => $teamId, 'kind' => 'empresa', 'name' => $name, 'email' => Str::slug($name).'@example.test',
            'locale' => 'es', 'owner_id' => $staff->id, 'status' => Collaborator::PENDING, 'created_by' => $staff->id]);
        $this->collaborators[] = $c->id;

        return $c;
    }

    private function fakeSites(): void
    {
        $jpeg = "\xFF\xD8\xFF\xE0".str_repeat("\0", 64)."\xFF\xD9";
        Http::fake(function (ClientRequest $request) use ($jpeg) {
            $url = $request->url();
            $path = (string) parse_url($url, PHP_URL_PATH);

            return match (true) {
                $url === self::FEED => Http::response($this->feed, $this->feedStatus, ['Content-Type' => 'application/xml']),
                $url === self::JSON => Http::response($this->fixture('generico.json'), 200, ['Content-Type' => 'application/json']),
                str_starts_with($url, 'https://img.colaborador-ficticio.test/'), str_starts_with($url, self::WEB.'/fotos/') => Http::response($jpeg, 200, ['Content-Type' => 'image/jpeg']),
                $url === self::WEB.'/robots.txt' => Http::response($this->fixture('robots.txt'), 200, ['Content-Type' => 'text/plain']),
                $url === self::WEB.'/sitemap.xml' => Http::response($this->fixture('sitemap.xml'), 200, ['Content-Type' => 'application/xml']),
                $path === '/inmueble/fic-web-1' => Http::response($this->fixture('ficha-jsonld.html'), 200, ['Content-Type' => 'text/html']),
                $path === '/inmueble/fic-web-2' => Http::response($this->fixture('ficha-og.html'), 200, ['Content-Type' => 'text/html']),
                default => Http::response('no encontrado', 404),
            };
        });
    }

    private function fakeAi(): void
    {
        app()->instance(ListingAi::class, new class($this->aiCalls) implements ListingAi
        {
            public function __construct(private int &$calls) {}

            public function extract(string $url, string $text): array
            {
                $this->calls++;

                return str_contains($text, '340.000') ? ['es_inmueble' => true, 'precio' => 340000, 'operacion' => 'venta', 'tipo' => 'casa', 'm2' => 150,
                    'habitaciones' => 4, 'banos' => 2, 'municipio' => 'Pueblo Ficticio', 'zona' => 'Zona Norte Ficticia', 'referencia' => 'WEB-REF-2'] : ['es_inmueble' => false];
            }
        });
    }

    private function requests(?string $host = null): int
    {
        return Http::recorded(fn (ClientRequest $q): bool => $host === null || parse_url($q->url(), PHP_URL_HOST) === $host)->count();
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(database_path('fixtures/importacion/'.$name));
    }

    private function actAs(User $user): void
    {
        app('session')->driver()->flush();
        auth()->forgetGuards();
        auth()->guard('web')->setUser($user);
        auth()->shouldUse('web');
    }

    private function check(string $label, bool $ok, string $detail = ''): void
    {
        $ok ? $this->pass++ : $this->fail++;
        $this->line(($ok ? 'PASS  ' : 'FAIL  ').$label.(! $ok && $detail !== '' ? '  ['.$detail.']' : ''));
    }
}
