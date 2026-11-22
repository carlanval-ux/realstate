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
use App\Models\PropertyText;
use App\Models\User;
use App\Filament\Collaborators\PropertyOrigin;
use Liberu\RealEstate\PropertiesFilament\Resources\PropertyResource\Pages\EditProperty;
use Liberu\RealEstate\PropertiesFilament\Resources\PropertyResource\Pages\ListProperties;
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
            $public = Storage::disk('public');
            foreach ($this->collaborators as $id) {
                $public->deleteDirectory('inmuebles/importados/'.$id);
            }
            foreach (['inmuebles/importados', 'inmuebles'] as $dir) {   // leave no empty folders behind (never touches real files)
                if ($public->exists($dir) && $public->allFiles($dir) === [] && $public->allDirectories($dir) === []) {
                    $public->deleteDirectory($dir);
                }
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
        $aPhotos = $this->origins($a, 'photo');
        $expected = array_map(fn (int $i) => "https://img.colaborador-ficticio.test/fic-a1-{$i}.jpg", [1, 2, 3, 4, 5, 6, 8, 9, 10, 11, 12, 13]);
        $this->check('TODAS las fotos (12 + 1) descargadas al disco público, en el orden del anuncio; la primera es la portada', $photos->count() === 13
            && $aPhotos === $expected && $photos->every(fn (MediaDocument $d): bool => Storage::disk('public')->exists($d->path) && str_starts_with($d->path, 'inmuebles/importados/'.$inmo->id.'/')),
            json_encode($aPhotos));
        $this->check('planos, vídeos; la visita virtual del colaborador queda en «otros datos» (no se publica)', count($this->origins($a, 'floorplan')) === 1
            && str_ends_with((string) MediaDocument::query()->where('property_id', $a?->id)->where('kind', 'floorplan')->value('path'), '.pdf')
            && $this->origins($a, 'video') === ['https://www.youtube.com/watch?v=ficticio01'] && $a?->virtual_tour_url === null && ($rows['FIC-A1']->refresh()->extra[__('importacion.extra.tour')] ?? []) === ['https://my.matterport.com/show/?m=ficticio01']);
        $features = (array) $a?->features;
        $this->check('toda la información: características, certificado energético, año, comunidad, CP, coordenadas',
            array_diff(['Ascensor', 'Garaje', 'Trastero', 'Piscina', 'Terraza', 'Aire acondicionado', 'Calefacción', 'Amueblado', 'Barbacoa'], $features) === []
            && $a?->has_parking === true && $a?->energy_rating === 'E' && ($a?->epc['calificacion_emisiones'] ?? null) === 'F' && (float) ($a?->epc['consumo_kwh_m2_ano'] ?? 0) === 120.5
            && (float) ($a?->epc['emisiones_kg_co2_m2_ano'] ?? 0) === 25.0 && (int) $a?->year_built === 1998 && (float) $a?->service_charge === 60.0
            && $a?->postal_code === '03999' && round((float) $a?->latitude, 4) === 38.1234 && $a?->title === 'Chalet ficticio con piscina',
            json_encode($a?->only(['features', 'energy_rating', 'epc', 'year_built', 'service_charge', 'postal_code', 'latitude', 'title']), JSON_UNESCAPED_UNICODE));
        $extra = $rows['FIC-A1']->refresh()->extra ?? [];
        $this->check('lo que Liberu no tiene va a «otros datos»: útil, parcela, planta, orientación, estado, IBI, calefacción, alemán',
            ($extra['Superficie útil'] ?? '') === '150 m²' && ($extra['Superficie de parcela'] ?? '') === '800 m²' && ($extra['Planta'] ?? '') === '3ª'
            && ($extra['Orientación'] ?? '') === 'Sur' && ($extra['Estado de conservación'] ?? '') === 'Buen estado' && ($extra['IBI'] ?? '') === '420,00 €/año'
            && ($extra['Heating type'] ?? '') === 'Gas natural' && str_contains((string) ($extra['Descripciones en otros idiomas']['de'] ?? ''), 'Fiktive Villa'),
            json_encode($extra, JSON_UNESCAPED_UNICODE));
        $en = PropertyText::query()->where('property_id', $a?->id)->where('locale', 'en')->first();
        $this->check('descripción en cada idioma: inglés como traducción en BORRADOR (la web solo lee aprobadas)', $en?->status === PropertyText::DRAFT
            && $en?->title === 'Fictitious villa with pool' && str_contains((string) $en?->description, 'Fictitious villa') && ! str_contains((string) $en?->description, '600 123 456'));
        $this->check('«otros datos» visibles en la pestaña «Colaboradores» de la ficha del inmueble', $this->propertyPage($a, $team, ['Datos importados del colaborador', 'Superficie útil', '150 m²', 'Gas natural']));
        $this->check('una foto que apunta a una dirección interna (169.254…) no se pide ni se guarda',
            Http::recorded(fn (ClientRequest $q): bool => str_contains($q->url(), '169.254'))->isEmpty() && ! isset($rows['FIC-A1']->photos['http://169.254.169.254/latest/meta-data/foto.jpg']));
        $all = json_encode([Property::query()->whereKey($ids)->get()->toArray(), ImportedProperty::query()->where('collaborator_id', $inmo->id)->get()->toArray(),
            PropertyText::query()->whereIn('property_id', $ids)->get()->toArray()], JSON_UNESCAPED_UNICODE);
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

        // 4b. The source changes its gallery: a new cover, photo 3 gone. Staff's own upload stays, after the source's.
        MediaDocument::query()->create(['team_id' => $teamId, 'property_id' => $a->id, 'kind' => 'photo', 'path' => 'inmuebles/subida-del-equipo.jpg', 'title' => 'Foto del equipo', 'sort_order' => 99]);
        $first = '<image id="1"><url>https://img.colaborador-ficticio.test/fic-a1-1.jpg';
        $this->feed = str_replace(['<image id="3"><url>https://img.colaborador-ficticio.test/fic-a1-3.jpg</url></image>', $first],
            ['', '<image id="0"><url>https://img.colaborador-ficticio.test/fic-a1-nueva.jpg</url></image>'.$first], $this->fixture('kyero.xml'));
        $before = $this->requests('img.colaborador-ficticio.test');
        $r = CollaboratorImports::run($import);
        $order = MediaDocument::query()->where('property_id', $a->id)->where('kind', 'photo')->orderBy('sort_order')->get();
        $originsNow = $this->origins($a, 'photo');
        $this->check('resincronizar fotos: añade la nueva (portada), quita la que ya no está, no duplica, conserva la del equipo al final',
            $r['counts']['fotos'] === 1 && $r['counts']['fotos_quitadas'] === 1 && $this->requests('img.colaborador-ficticio.test') === $before + 1
            && ($originsNow[0] ?? '') === 'https://img.colaborador-ficticio.test/fic-a1-nueva.jpg' && ! in_array('https://img.colaborador-ficticio.test/fic-a1-3.jpg', $originsNow, true)
            && count($originsNow) === 12 && count(array_unique($originsNow)) === 12 && $order->count() === 13 && $order->last()?->path === 'inmuebles/subida-del-equipo.jpg'
            && $order->pluck('sort_order')->all() === range(1, 13), json_encode($r['counts']).' '.json_encode($originsNow));

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
        $gen = $this->collaborator($teamId, $staff, 'Inmobiliaria Ejemplo Costa SL');
        $jsonImport = CollaboratorImport::query()->create(['team_id' => $teamId, 'collaborator_id' => $gen->id, 'source' => 'feed', 'feed_url' => self::JSON, 'feed_format' => 'json',
            'authorized_at' => now()->subDays(3), 'feed_mapping' => ['elementos' => 'datos.anuncios', 'referencia' => 'codigo', 'precio' => 'importe', 'operacion' => 'modalidad',
                'tipo' => 'clase', 'm2' => 'detalle.superficie', 'habitaciones' => 'detalle.dormitorios', 'banos' => 'detalle.aseos', 'municipio' => 'localidad',
                'zona' => 'barrio', 'titulo' => 'titulo', 'descripcion' => 'texto', 'fotos' => 'galeria']]);
        $r = CollaboratorImports::run($jsonImport);
        $g1 = Property::query()->find(ImportedProperty::query()->where('collaborator_id', $gen->id)->where('external_ref', 'GEN-1')->value('property_id'));
        $g2 = Property::query()->find(ImportedProperty::query()->where('collaborator_id', $gen->id)->where('external_ref', 'GEN-2')->value('property_id'));
        $this->check('feed JSON genérico con correspondencia: «189.500 €», «92,5 m²», ático→piso, alquiler de local', $r['counts']['nuevos'] === 2
            && (float) $g1?->price === 189500.0 && (float) $g1?->area_sqft === 92.5 && $g1?->property_type === 'apartment' && $g1?->bedrooms === 3
            && (float) $g2?->price === 1200.0 && $g2?->deal_type?->value === 'rent' && $g2?->property_type === 'commercial'
            && MediaDocument::query()->where('property_id', $g1?->id)->where('kind', 'photo')->count() === 1
            && ! str_contains((string) json_encode($g1?->toArray(), JSON_UNESCAPED_UNICODE), 'Propietaria Ficticia'), json_encode($r));
        $gExtra = ImportedProperty::query()->where('collaborator_id', $gen->id)->where('external_ref', 'GEN-1')->value('extra');
        $gExtra = is_string($gExtra) ? json_decode($gExtra, true) : (array) $gExtra;
        $this->check('feed genérico: los campos sin correspondencia también se leen (planta, año, comunidad, certificado, extras, «Vistas»), sin el contacto',
            (int) $g1?->year_built === 1975 && (float) $g1?->service_charge === 80.0 && $g1?->energy_rating === 'C'
            && array_diff(['Ascensor', 'Terraza', 'Portero'], (array) $g1?->features) === [] && ($gExtra['Planta'] ?? '') === '4ª' && ($gExtra['Vistas'] ?? '') === 'Despejadas'
            && ! str_contains((string) json_encode($gExtra, JSON_UNESCAPED_UNICODE), '622 333 444'), json_encode([$g1?->only(['year_built', 'service_charge', 'energy_rating', 'features']), $gExtra], JSON_UNESCAPED_UNICODE));

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
        $this->check('JSON-LD primero: precio, m², habitaciones, año, dirección publicada, CP; sin datos de la agencia ni del propietario; sin IA para esa ficha',
            (float) $w1?->price === 215000.0 && (float) $w1?->area_sqft === 88.0 && $w1?->bedrooms === 3 && $w1?->property_type === 'apartment' && $w1?->deal_type?->value === 'sale'
            && $w1?->address === 'Calle Ficticia del Anuncio 7, Ciudad Imaginaria' && $w1?->postal_code === '46999' && (int) $w1?->year_built === 2004
            && $w1?->title === 'Piso ficticio luminoso' && ! str_contains((string) $w1?->description, '611 000 111'),
            json_encode($w1?->only(['price', 'area_sqft', 'title', 'address', 'postal_code', 'year_built']), JSON_UNESCAPED_UNICODE));
        $web1 = '/fotos/originales/web-1-';
        $this->check('web: TODAS las fotos de la galería a su mayor tamaño (original, zoom o srcset 1920w), en orden, sin miniaturas, logo, iconos ni otros inmuebles',
            $this->origins($w1, 'photo') === [self::WEB.$web1.'a.jpg', self::WEB.$web1.'b.jpg', self::WEB.$web1.'c.jpg', self::WEB.'/fotos/grandes/web-1-d-1920x1280.jpg'],
            json_encode($this->origins($w1, 'photo')));
        $this->check('web: plano y vídeo; la visita virtual del colaborador no se publica', $this->origins($w1, 'floorplan') === [self::WEB.'/fotos/plano-web-1.jpg']
            && $this->origins($w1, 'video') === ['https://www.youtube.com/embed/ficticio02'] && $w1?->virtual_tour_url === null && ($webRows->firstWhere('property_id', $w1?->id)?->extra[__('importacion.extra.tour')] ?? []) === ['https://my.matterport.com/show/?m=ficticio02']);
        $wExtra = $webRows->firstWhere('property_id', $w1?->id)?->extra ?? [];
        $this->check('web: características y tabla de datos (planta, orientación, útil, comunidad, IBI, certificado, consumo, emisiones, carpintería)',
            array_diff(['Piscina', 'Ascensor', 'Trastero', 'Calefacción'], (array) $w1?->features) === [] && ! in_array('Jardín', (array) $w1?->features, true)
            && ! in_array('Amueblado', (array) $w1?->features, true) && $w1?->energy_rating === 'D' && (float) ($w1?->epc['consumo_kwh_m2_ano'] ?? 0) === 95.0
            && (float) ($w1?->epc['emisiones_kg_co2_m2_ano'] ?? 0) === 20.0 && (float) $w1?->service_charge === 45.0
            && ($wExtra['Planta'] ?? '') === '2ª' && ($wExtra['Orientación'] ?? '') === 'Este' && ($wExtra['Superficie útil'] ?? '') === '75 m²'
            && ($wExtra['IBI'] ?? '') === '300,00 €/año' && ($wExtra['Carpintería exterior'] ?? '') === 'Aluminio con climalit',
            json_encode([$w1?->only(['features', 'energy_rating', 'epc', 'service_charge']), $wExtra], JSON_UNESCAPED_UNICODE));
        $wEn = PropertyText::query()->where('property_id', $w1?->id)->where('locale', 'en')->first();
        $wAll = json_encode([$w1?->toArray(), $wExtra, $wEn?->toArray()], JSON_UNESCAPED_UNICODE);
        $this->check('web: versión en inglés (hreflang) como borrador; nada del propietario ni de la agencia', $wEn?->status === PropertyText::DRAFT
            && str_contains((string) $wEn?->description, 'Fictitious renovated flat') && ! str_contains($wAll, 'Propietaria Ficticia Web') && ! str_contains($wAll, '600 999 111')
            && ! str_contains($wAll, '622 111 000') && ! str_contains($wAll, 'Calle de la Agencia') && ! str_contains($wAll, '600 777 888'), Str::limit($wAll, 600));
        $this->check('solo OpenGraph: el resto lo completa la IA (simulada) — 1 sola llamada', $this->aiCalls === 1 && (float) $w2?->price === 340000.0
            && $w2?->property_type === 'house' && $w2?->bedrooms === 4 && $w2?->address === 'Zona Norte Ficticia, Pueblo Ficticio' && $w2?->title === 'Casa ficticia con jardín',
            'IA '.$this->aiCalls.' '.json_encode($w2?->only(['price', 'property_type', 'address'])));
        config(['importacion.web.max_paginas' => 3]);
        $r = CollaboratorImports::run($webImport);
        config(['importacion.web.max_paginas' => 50]);
        $this->check('límite de páginas por ejecución: se para, queda «parcial» y no retira nada', $r['status'] === 'parcial' && $r['counts']['paginas'] <= 3 && $r['counts']['retirados'] === 0
            && ImportedProperty::query()->where('collaborator_id', $web->id)->where('status', 'activo')->count() === 2, json_encode($r));

        // 10. White label (owner, 22/11/2026): staff see whose it is; clarto.es sees a CLARTO property and nothing else.
        $this->whiteLabel($team, $teamId, $staff, $inmo, $gen, $a, $w1, $w2, $g1);

        // 11. Guards and wiring.
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

    private function whiteLabel(Team $team, int $teamId, User $staff, Collaborator $inmo, Collaborator $gen, Property $a, ?Property $w1, ?Property $w2, ?Property $g1): void
    {
        $a->refresh();
        $aRow = ImportedProperty::query()->where('property_id', $a->id)->firstOrFail();
        $gRow = ImportedProperty::query()->where('property_id', $g1?->id)->first();
        $origDesc = __('importacion.extra.original_description');
        $this->check('marca blanca: textos públicos sin web, marca, «ref.», teléfono ni correo del colaborador; el original queda en «otros datos»',
            ! preg_match('/colaborador|FIC-A1|www\.|https?:|600 123 456|@|\[…\]/iu', (string) $a->description) && str_contains((string) $a->description, '250.000 €')
            && str_contains((string) ($aRow->extra[$origDesc] ?? ''), 'Colaborador Ficticio') && ! str_contains((string) ($aRow->extra[$origDesc] ?? ''), '600 123 456'),
            Str::limit((string) $a->description, 300));
        $en = PropertyText::query()->where('property_id', $a->id)->where('locale', 'en')->first();
        $this->check('marca blanca también en las traducciones importadas y en el nombre comercial del colaborador («Inmobiliaria Ejemplo Costa»)',
            ! preg_match('/colaborador|FIC-A1|https?:|600 123 456/iu', (string) $en?->description) && str_contains((string) $en?->description, 'Fictitious villa')
            && $g1?->title === 'Ático en el centro' && ! preg_match('/Ejemplo Costa|655 444 333/u', (string) $g1?->description.' '.$g1?->title)
            && str_contains((string) ($gRow?->extra[__('importacion.extra.original_title')] ?? ''), 'Inmobiliaria Ejemplo Costa'),
            json_encode([$en?->description, $g1?->title, $g1?->description], JSON_UNESCAPED_UNICODE));
        $review = $aRow->review ?? [];
        $w1Review = ImportedProperty::query()->where('property_id', $w1?->id)->value('review');
        $w2Review = ImportedProperty::query()->where('property_id', $w2?->id)->value('review');
        [$w1Review, $w2Review] = [is_string($w1Review) ? json_decode($w1Review, true) : $w1Review, is_string($w2Review) ? json_decode($w2Review, true) : $w2Review];
        $this->check('quedan avisos de revisión: textos limpiados, visita virtual del colaborador y fotos con la misma marca en la esquina',
            ! empty($review['textos']) && ($review['visita'] ?? null) === 'https://my.matterport.com/show/?m=ficticio01'
            && ($review['fotos'] ?? null) === __('importacion.review.photos_repeated'), json_encode($review, JSON_UNESCAPED_UNICODE));
        $this->check('fotos: el nombre del colaborador en los datos de la imagen avisa; fotos limpias y distintas no avisan',
            str_contains((string) ($w2Review['fotos'] ?? ''), 'colaborador') && ! isset($w1Review['fotos']) && ! isset($gRow?->review['fotos']),
            json_encode([$w1Review, $w2Review], JSON_UNESCAPED_UNICODE));

        // The internal record: prominent notice with collaborator, origin, reference, last sync, link and flags.
        $this->check('ficha interna: aviso «Inmueble de colaborador» arriba, con origen, ref., última sincronización, enlace y avisos',
            $this->propertyPage($a, $team, [__('importacion.origin.heading', ['name' => $inmo->name]), 'origen: feed', 'ref. del colaborador: FIC-A1', 'última sincronización',
                __('importacion.origin.open'), 'Revisar fotos (posible marca de agua del colaborador)', 'Revisar la visita virtual', __('importacion.origin.reviewed')]));
        $this->check('«Marcar como revisado» quita los avisos (el aviso de colaborador sigue)', PropertyOrigin::markReviewed($a)
            && ImportedProperty::query()->find($aRow->id)?->review === null && PropertyOrigin::of($a)['origin'] === 'feed');

        // A property a collaborator contributed by hand («aportado», no import) and one of CLARTO's own.
        $manual = Property::query()->create(['team_id' => $teamId, 'created_by' => $staff->id, 'title' => 'Piso ficticio aportado a mano', 'address' => 'Zona Ficticia',
            'property_type' => 'apartment', 'status' => 'available', 'deal_type' => 'sale', 'price' => 100000]);
        CollaboratorProperty::query()->create(['team_id' => $teamId, 'collaborator_id' => $gen->id, 'property_id' => $manual->id, 'relation' => 'aportado', 'assigned_at' => now()]);
        $own = Property::query()->create(['team_id' => $teamId, 'created_by' => $staff->id, 'title' => 'Piso ficticio propio de CLARTO', 'address' => 'Zona Ficticia',
            'property_type' => 'apartment', 'status' => 'draft', 'deal_type' => 'sale', 'price' => 120000]);
        $this->check('aportado a mano por un colaborador: también lleva el aviso (origen «manual»); uno propio no', PropertyOrigin::of($manual)['origin'] === 'manual'
            && PropertyOrigin::of($manual)['collaborator']->is($gen) && PropertyOrigin::of($own) === null
            && $this->propertyPage($manual, $team, [__('importacion.origin.heading', ['name' => $gen->name]), 'origen: manual']));

        // Property list: column and the three filters.
        Filament::setCurrentPanel('admin');
        Filament::setTenant($team, isQuiet: true);
        $ids = function (array $filter): array {
            $list = \Livewire\Livewire::test(ListProperties::class);
            foreach ($filter as $name => $value) {
                $list->set("tableFilters.{$name}.value", $value);
            }

            return $list->instance()->getFilteredTableQuery()->pluck('real_estate_properties.id')->map(fn ($id) => (int) $id)->all();
        };
        $mine = $ids(['clarto_origen' => 'colaborador']);
        $ownList = $ids(['clarto_origen' => 'propio']);
        $byGen = $ids(['clarto_colaborador' => (string) $gen->id]);
        $manualList = $ids(['clarto_importacion' => 'manual']);
        $webList = $ids(['clarto_importacion' => 'web']);
        $this->check('listado: filtros «Origen», «Colaborador» y «Origen de importación»', in_array($a->id, $mine, true) && in_array($manual->id, $mine, true) && ! in_array($own->id, $mine, true)
            && in_array($own->id, $ownList, true) && ! in_array($a->id, $ownList, true)
            && in_array($manual->id, $byGen, true) && in_array($g1?->id, $byGen, true) && ! in_array($a->id, $byGen, true)
            && in_array($manual->id, $manualList, true) && ! in_array($a->id, $manualList, true) && in_array($w1?->id, $webList, true) && ! in_array($a->id, $webList, true),
            json_encode(compact('mine', 'byGen', 'manualList')));
        $listHtml = (string) \Livewire\Livewire::test(ListProperties::class)->set('tableFilters.clarto_colaborador.value', (string) $gen->id)->html();
        $this->check('listado: columna «Colaborador» con el nombre', str_contains($listHtml, e($gen->name)) && str_contains($listHtml, e(__('importacion.origin.column'))));

        // clarto.es: the public catalog shows CLARTO's reference and nothing about the collaborator.
        foreach ([$a, $w1, $g1] as $p) {
            $p?->forceFill(['status' => 'available'])->save();
        }
        PropertyText::query()->where('property_id', $a->id)->where('locale', 'en')->update(['status' => PropertyText::APPROVED]);
        $catalog = app(\App\Http\Controllers\CatalogoController::class);
        $responses = [];
        foreach (array_filter([$a, $w1, $g1, $manual]) as $p) {
            foreach (['es', 'en'] as $lang) {
                $responses[] = (string) $catalog->show(\Illuminate\Http\Request::create('/api/v1/catalogo/inmuebles/'.$p->reference(), 'GET', ['idioma' => $lang]), $p->reference())->getContent();
            }
        }
        $responses[] = (string) $catalog->index(\Illuminate\Http\Request::create('/api/v1/catalogo/inmuebles', 'GET', ['por_pagina' => 48, 'q' => 'ficticio']))->getContent();
        $public = implode("\n", $responses);
        $forbidden = [$inmo->name, 'Inmo Ficticia', $gen->name, 'Ejemplo Costa', 'Web Ficticia', 'colaborador', 'FIC-A1', 'WEB-REF-1', 'GEN-1', 'matterport', 'importad',
            'img.colaborador', 'web.colaborador', 'inmuebles/importados', '/storage/', '"origen"', 'feed', '611 000 111', '600 123 456', 'youtube'];
        $leaks = array_values(array_filter($forbidden, fn (string $s): bool => stripos($public, $s) !== false || stripos($public, json_encode($s)) !== false));
        $show = json_decode($responses[0], true)['data'] ?? [];
        $images = array_merge(array_column($show['galeria'] ?? [], 'src'), array_column($show['planos'] ?? [], 'src'));
        $this->check('catálogo público (/api/v1/catalogo): ningún dato del colaborador (nombre, marca, ref., origen, URLs, vídeo, visita)', $leaks === [], 'fuga: '.implode(' | ', $leaks));
        $this->check('catálogo: referencia propia de CLARTO, todas las fotos por URL firmada del catálogo (nunca enlazadas a la web del colaborador)',
            ($show['referencia'] ?? null) === $a->reference() && count($show['galeria'] ?? []) >= 12 && $images !== []
            && collect($images)->every(fn (string $u): bool => str_contains($u, '/api/v1/catalogo/fotos/') && str_contains($u, 'signature=')),
            json_encode(['ref' => $show['referencia'] ?? null, 'fotos' => count($show['galeria'] ?? [])]));
        $doc = MediaDocument::query()->where('property_id', $a->id)->where('kind', 'photo')->orderBy('sort_order')->first();
        $file = $catalog->foto((int) $doc?->id, (int) config('catalogo.anchos_foto')[0]);
        $this->check('catálogo: la foto se sirve desde CLARTO como WebP (re-codificada, sin los metadatos originales)', $file->headers->get('Content-Type') === 'image/webp'
            && ! str_contains((string) file_get_contents($file->getFile()->getPathname()), 'Colaborador'));
        foreach (glob(storage_path('app/catalogo-cache').'/'.$doc?->id.'-*') ?: [] as $cached) {
            @unlink($cached);
        }
    }

    /**
     * A fictitious JPEG per URL (same bytes every time): random shapes; the Kyero listing A photos carry the same
     * «logo» box in the bottom-right corner (simulated watermark); the web OG photo names the brand in a JPEG comment.
     */
    private function photo(string $url): string
    {
        static $cache = [];
        if (isset($cache[$url])) {
            return $cache[$url];
        }
        mt_srand(crc32($url));
        $img = imagecreatetruecolor(320, 240);
        imagefill($img, 0, 0, imagecolorallocate($img, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
        for ($i = 0; $i < 14; $i++) {
            $x = mt_rand(0, 300);
            $y = mt_rand(0, 220);
            imagefilledrectangle($img, $x, $y, $x + mt_rand(20, 140), $y + mt_rand(20, 100), imagecolorallocate($img, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
        }
        if (str_contains($url, '/fic-a1-')) {
            imagefilledrectangle($img, 248, 188, 316, 236, imagecolorallocate($img, 255, 255, 255));
            imagestring($img, 3, 252, 195, 'INMO', imagecolorallocate($img, 0, 0, 0));
            imagestring($img, 3, 252, 212, 'FICT.', imagecolorallocate($img, 0, 0, 0));
        }
        ob_start();
        imagejpeg($img, null, 90);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);
        mt_srand();
        if (str_contains($url, 'web-2-a')) {
            $comment = 'Foto (c) Colaborador Ficticio';
            $bytes = substr($bytes, 0, 2)."\xFF\xFE".pack('n', strlen($comment) + 2).$comment.substr($bytes, 2);
        }

        return $cache[$url] = $bytes;
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
        $jpeg = fn (string $url): string => $this->photo($url);
        Http::fake(function (ClientRequest $request) use ($jpeg) {
            $url = $request->url();
            $path = (string) parse_url($url, PHP_URL_PATH);

            return match (true) {
                $url === self::FEED => Http::response($this->feed, $this->feedStatus, ['Content-Type' => 'application/xml']),
                $url === self::JSON => Http::response($this->fixture('generico.json'), 200, ['Content-Type' => 'application/json']),
                str_ends_with($path, '.pdf') => Http::response("%PDF-1.4\n% plano ficticio\n", 200, ['Content-Type' => 'application/pdf']),
                str_starts_with($url, 'https://img.colaborador-ficticio.test/'), str_starts_with($url, self::WEB.'/fotos/') => Http::response($jpeg($url), 200, ['Content-Type' => 'image/jpeg']),
                $path === '/en/property/fic-web-1' => Http::response($this->fixture('ficha-jsonld-en.html'), 200, ['Content-Type' => 'text/html']),
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

    /** @return list<string> source URLs of the property's imported media of a kind, in display order */
    private function origins(?Property $property, string $kind): array
    {
        return MediaDocument::query()->where('property_id', $property?->id ?? 0)->where('kind', $kind)->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (MediaDocument $d) => data_get($d->metadata, 'origen'))->filter()->values()->all();
    }

    /** @param list<string> $expected */
    private function propertyPage(?Property $property, Team $team, array $expected): bool
    {
        Filament::setCurrentPanel('admin');
        Filament::setTenant($team, isQuiet: true);
        $html = (string) \Livewire\Livewire::test(EditProperty::class, ['record' => $property?->id])->html();
        $missing = array_filter($expected, fn (string $s): bool => ! str_contains($html, e($s)) && ! str_contains($html, $s));
        if ($missing !== []) {
            $this->line('   falta en la ficha: '.implode(' | ', $missing));
        }

        return $missing === [];
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
