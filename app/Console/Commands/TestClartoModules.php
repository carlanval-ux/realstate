<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Acquisition\GhlHandoff;
use App\Ai\Contracts\TextCompleter;
use App\Ai\PropertyTextWriter;
use App\Filament\ClartoNavigation;
use App\Models\AiSetting;
use App\Models\PortalListing;
use App\Models\PropertyText;
use App\Models\User;
use App\Modules\ClartoModules;
use App\Modules\ModuleDisabled;
use App\Modules\ModuleStatus;
use App\Portals\Pisos\PisosConnector;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Liberu\Foundation\ModuleManager\Exceptions\ModuleActivationRefused;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\RealEstate\Properties\Models\Property;
use Throwable;

/**
 * CLARTO modules end to end: listed with version and status, pending configuration detected, switching a module off
 * refuses new operations (actions, API, webhook, model writes) while existing rows stay, switching it on restores
 * them, and only administrators manage modules. Runs in a rolled-back transaction (module choices included): nothing
 * is kept, no external service is called (Http::preventStrayRequests).
 *
 *   php artisan clarto:modulos-prueba
 */
final class TestClartoModules extends Command
{
    protected $signature = 'clarto:modulos-prueba';

    protected $description = 'Test the CLARTO modules (list, pending configuration, disable/enable gating; nothing is kept)';

    private const MEDIA = 'Liberu\\RealEstate\\MediaAndDocuments\\Models\\MediaDocument';

    private const ENVELOPE = 'Liberu\\RealEstate\\MediaAndDocuments\\Models\\DocumentEnvelope';

    private int $pass = 0;

    private int $fail = 0;

    public function handle(): int
    {
        Http::preventStrayRequests();
        $config = config()->all();

        DB::beginTransaction();
        try {
            $this->listing();
            $this->pendingConfiguration();
            $this->captacion();
            $this->idiomas();
            $this->traduccionIa();
            $this->pisos();
            $this->documentos();
            $this->firma();
            $this->whatsapp();
            $this->permissions();
        } finally {
            DB::rollBack();
            config($config);
            app()->forgetInstance(ClartoModules::CHOICES);
        }

        $this->line(sprintf('RESULT: %d passed, %d failed (database rolled back; no external service called)', $this->pass, $this->fail));

        return $this->fail === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function listing(): void
    {
        $this->info('1. Módulos listados');
        $rows = ClartoModules::rows();
        $this->table(['Módulo', 'Nombre', 'Versión', 'Estado', 'Nota'], array_map(fn (array $r): array => [
            $r['module'], $r['name'], $r['version'], ModuleStatus::from($r['status'])->label(), mb_strimwidth((string) $r['note'], 0, 70, '…'),
        ], $rows));

        foreach ([ClartoModules::FIRMA, ClartoModules::DOCUMENTOS, ClartoModules::WHATSAPP, ClartoModules::IDIOMAS] as $key) {
            $row = $rows[ClartoModules::moduleName($key)] ?? null;
            $this->check("«{$key}» aparece con nombre, versión y estado", $row !== null && filled($row['name']) && preg_match('/^\d+\.\d+\.\d+$/', $row['version']) === 1 && ModuleStatus::tryFrom($row['status']) !== null);
        }
        // WhatsApp (WAHA) and signing (DocuSeal) are in this build: never «Pendiente de implementar» once switched on.
        foreach ([ClartoModules::FIRMA, ClartoModules::WHATSAPP] as $key) {
            $was = ClartoModules::switchedOn($key);
            ClartoModules::enable($key);
            $this->check("«{$key}» implementado: activado sale «Activado» o «Pendiente de configurar», nunca «Pendiente de implementar»",
                ClartoModules::isImplemented($key) && in_array(ClartoModules::status($key), [ModuleStatus::Enabled, ModuleStatus::PendingConfiguration], true), ClartoModules::status($key)->value);
            $was || ClartoModules::disable($key);
        }
        $driver = config('firma.driver');
        config(['firma.driver' => 'simulado']);
        $was = ClartoModules::switchedOn(ClartoModules::FIRMA);
        ClartoModules::enable(ClartoModules::FIRMA);
        $this->check('firma sin DocuSeal en el servidor: «Pendiente de configurar» con el motivo', ClartoModules::status(ClartoModules::FIRMA) === ModuleStatus::PendingConfiguration
            && str_contains((string) ClartoModules::missingConfiguration(ClartoModules::FIRMA), 'DocuSeal'));
        $was || ClartoModules::disable(ClartoModules::FIRMA);
        config(['firma.driver' => $driver]);
        $this->check('los manifiestos son features de Liberu (module_type feature)', collect(ClartoModules::manifests())->every(fn ($m): bool => $m->isFeature()));
    }

    private function pendingConfiguration(): void
    {
        $this->info('2. Pendiente de configurar');
        ClartoModules::enable(ClartoModules::CAPTACION);
        config(['ghl.token' => null, 'ghl.location_id' => 'LOC', 'ghl.pipeline_id' => 'PIPE', 'ghl.webhook_secret' => 'secreto-modulos']);
        $row = ClartoModules::rows()[ClartoModules::moduleName(ClartoModules::CAPTACION)];
        $this->check('captación sin token de GHL: «Pendiente de configurar» y nombra lo que falta (sin valores)',
            ClartoModules::status(ClartoModules::CAPTACION) === ModuleStatus::PendingConfiguration && str_contains((string) $row['note'], 'GHL_API_TOKEN'));
        $this->check('pendiente de configurar no bloquea: el módulo sigue activo', ClartoModules::enabled(ClartoModules::CAPTACION));
        config(['ghl.token' => 'pit-prueba']);
        $this->check('con la configuración completa: «Activado»', ClartoModules::status(ClartoModules::CAPTACION) === ModuleStatus::Enabled);

        config(['pisos.driver' => 'simulado']);
        $this->check('pisos.com en simulación: «Pendiente de configurar»', ClartoModules::status(ClartoModules::PISOS) === ModuleStatus::PendingConfiguration);

        $idiomas = ClartoModules::status(ClartoModules::IDIOMAS);
        $this->check('idiomas: «Activado» con clave de IA o «Pendiente de configurar» sin ella ('.$idiomas->label().')',
            in_array($idiomas, [ModuleStatus::Enabled, ModuleStatus::PendingConfiguration], true));

        config(['modules.disabled' => [ClartoModules::moduleName(ClartoModules::WHATSAPP)]]);
        try {
            ClartoModules::enable(ClartoModules::WHATSAPP);
            $refused = false;
        } catch (ModuleActivationRefused) {
            $refused = true;
        }
        $this->check('MODULES_DISABLED manda: bloqueado y no se puede activar desde el panel', $refused && ClartoModules::locked(ClartoModules::WHATSAPP) !== null && ! ClartoModules::enabled(ClartoModules::WHATSAPP));
        config(['modules.disabled' => []]);
    }

    private function captacion(): void
    {
        $this->info('3. Captación GoHighLevel (webhook)');
        $kernel = app(Kernel::class);
        $post = fn (array $body) => $kernel->handle(Request::create('/api/v1/ghl/oportunidad-ganada', 'POST', [], [], [], [
            'HTTP_X_CLARTO_WEBHOOK_SECRET' => 'secreto-modulos', 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], (string) json_encode($body)));
        $history = GhlHandoff::query()->count();

        ClartoModules::disable(ClartoModules::CAPTACION);
        $response = $post(['opportunity_id' => 'OPP-MODULOS-1']);
        $body = json_decode((string) $response->getContent(), true);
        $this->check('desactivado: el webhook responde 403 con mensaje claro y no abre expediente',
            $response->getStatusCode() === 403 && ($body['estado'] ?? null) === 'desactivado' && str_contains((string) ($body['message'] ?? ''), ClartoModules::displayName(ClartoModules::CAPTACION)));
        $this->check('el historial de captaciones se conserva', GhlHandoff::query()->count() === $history);

        ClartoModules::enable(ClartoModules::CAPTACION);
        $this->check('reactivado: el webhook vuelve a aceptar (llega a la validación: 422 sin id)', $post([])->getStatusCode() === 422);
    }

    private function idiomas(): void
    {
        $this->info('4. Idiomas y traducción');
        $property = $this->property();

        ClartoModules::enable(ClartoModules::IDIOMAS);
        $existing = PropertyText::query()->updateOrCreate(['property_id' => $property->getKey(), 'locale' => 'en', 'status' => PropertyText::APPROVED], ['title' => 'Modules test '.uniqid()]);
        $texts = PropertyText::query()->count();

        ClartoModules::disable(ClartoModules::IDIOMAS);
        $result = PropertyTextWriter::make()->generateAll($property);
        $this->check('desactivado: la IA no se llama y explica por qué', $result['saved'] === [] && $result['error'] === ClartoModules::refusal(ClartoModules::IDIOMAS));
        $this->check('desactivado: no se crea un borrador nuevo', $this->refused(fn () => PropertyText::query()->create(['property_id' => $property->getKey(), 'locale' => 'tr', 'status' => PropertyText::DRAFT, 'title' => 'x'])));
        $this->check('desactivado: no se edita una traducción', $this->refused(fn () => $existing->fresh()->update(['title' => 'cambio'])));
        $this->check('desactivado: guardar sin cambios no se bloquea (el formulario del inmueble sigue guardando)', ! $this->refused(fn () => $existing->fresh()->save()));
        $this->check('desactivado: las traducciones existentes se conservan', PropertyText::query()->count() === $texts && PropertyText::query()->whereKey($existing->getKey())->exists());
        $this->check('desactivado: el texto aprobado en castellano no es parte del módulo', ! $this->refused(fn () => PropertyText::query()->updateOrCreate(['property_id' => $property->getKey(), 'locale' => 'es', 'status' => PropertyText::APPROVED], ['summary' => 'Resumen '.uniqid()])));

        ClartoModules::enable(ClartoModules::IDIOMAS);
        $this->check('reactivado: se vuelven a crear borradores', ! $this->refused(fn () => PropertyText::query()->updateOrCreate(['property_id' => $property->getKey(), 'locale' => 'tr', 'status' => PropertyText::DRAFT], ['title' => 'Borrador '.uniqid()])));
    }

    /**
     * AI translation of the approved Castilian (provider simulated with Http::fake; fictitious key and property): drafts
     * en/tr with source ia, never published until approved, no key → «Pendiente de configurar» without calling anyone,
     * a reviewed translation or a draft a person corrected is never written over, and another TextCompleter can be bound.
     */
    private function traduccionIa(): void
    {
        $this->info('4b. Traducción asistida por IA (proveedor simulado)');
        ClartoModules::enable(ClartoModules::IDIOMAS);

        $calls = [];
        $answers = [
            'en' => ['title' => 'Fictitious flat for sale in Valencia', 'summary' => 'Fictitious 70 m² flat.', 'description' => 'Fictitious flat of 70 m² for sale in Valencia for 120.000 EUR.', 'seo_title' => 'Fictitious flat in Valencia', 'seo_description' => 'Fictitious flat of 70 m² in Valencia.'],
            'tr' => ['title' => 'Valencia\'da satılık hayali daire', 'summary' => '70 m² hayali daire.', 'description' => 'Valencia\'da 120.000 EUR fiyatla satılık 70 m² hayali daire.', 'seo_title' => 'Valencia\'da hayali daire', 'seo_description' => 'Valencia\'da 70 m² hayali daire.'],
        ];
        Http::fake(['openrouter.ai/api/v1/chat/completions' => function ($request) use (&$calls, $answers) {
            $system = (string) data_get($request->data(), 'messages.0.content');
            $locale = str_contains($system, 'turco') ? 'tr' : 'en';
            $calls[] = $locale;

            return Http::response(['model' => 'proveedor/modelo-ficticio:free', 'choices' => [['message' => ['content' => json_encode($answers[$locale], JSON_UNESCAPED_UNICODE)]]]]);
        }]);

        $property = $this->property();
        $property->forceFill(['description' => 'Piso ficticio de 70 m² en venta en Valencia por 120.000 EUR. Texto aprobado para la prueba.'])->save();
        $drafts = fn (): int => PropertyText::query()->where('property_id', $property->getKey())->where('status', PropertyText::DRAFT)->count();
        $text = fn (string $locale, string $status): ?PropertyText => PropertyText::query()->where('property_id', $property->getKey())->where('locale', $locale)->where('status', $status)->first();

        // No key: «Pendiente de configurar», the button explains it and nobody is called.
        AiSetting::current()->forceFill(['provider' => 'openrouter', 'openrouter_api_key' => null, 'translation_model' => 'proveedor/modelo-ficticio:free'])->save();
        $result = PropertyTextWriter::make()->translateApproved($property);
        $this->check('sin clave de IA: idiomas «Pendiente de configurar»', ClartoModules::status(ClartoModules::IDIOMAS) === ModuleStatus::PendingConfiguration);
        $this->check('sin clave de IA: el botón explica que falta la clave y dónde se pone, sin llamar a nadie ni crear borradores',
            $result['saved'] === [] && str_contains((string) $result['error'], __('Pending configuration')) && str_contains((string) $result['error'], __('Artificial intelligence'))
            && $calls === [] && $drafts() === 0);

        // With a (fictitious) key: drafts en and tr from the approved Castilian.
        AiSetting::current()->forceFill(['openrouter_api_key' => 'clave-ficticia-de-prueba'])->save();
        $this->check('con clave de IA: idiomas «Activado»', ClartoModules::status(ClartoModules::IDIOMAS) === ModuleStatus::Enabled);
        $result = PropertyTextWriter::make()->translateApproved($property);
        $en = $text('en', PropertyText::DRAFT);
        $tr = $text('tr', PropertyText::DRAFT);
        $this->check('traduce el castellano aprobado: borradores EN y TR (source ia, modelo anotado)',
            $result['saved'] === ['en', 'tr'] && $result['error'] === null && $calls === ['en', 'tr']
            && $en?->source === PropertyText::SOURCE_AI && $tr?->source === PropertyText::SOURCE_AI
            && $en->title === $answers['en']['title'] && $tr->title === $answers['tr']['title'] && $tr->model === 'proveedor/modelo-ficticio:free');
        $sent = collect(Http::recorded())->map(fn (array $pair): string => json_encode($pair[0]->data(), JSON_UNESCAPED_UNICODE))->implode("\n");
        $this->check('al proveedor solo va el texto aprobado del anuncio (ni dirección ni propietario)',
            str_contains($sent, 'Texto aprobado para la prueba') && ! str_contains($sent, 'Calle Ficticia'));
        $this->check('los borradores no se publican: la web sigue mostrando el castellano en EN y TR',
            $text('en', PropertyText::APPROVED) === null && $text('tr', PropertyText::APPROVED) === null
            && PropertyText::resolve($property->refresh(), 'en')['values']['title'] === $property->title
            && PropertyText::resolve($property, 'tr')['languages']['description'] === 'es');

        // A person corrects the Turkish draft: it becomes theirs and no AI proposal replaces it.
        $tr->update(['title' => 'Valencia\'da satılık daire (düzeltildi)']);
        $this->check('corregir un borrador de IA en la ficha lo marca como de una persona (source manual)', $tr->fresh()?->source === PropertyText::SOURCE_MANUAL);
        $before = count($calls);
        $result = PropertyTextWriter::make()->regenerate($property, 'tr');
        $this->check('«Traducir al Türkçe con IA» no sustituye el borrador corregido: lo explica y no llama a la IA',
            $result['saved'] === [] && isset($result['skipped']['tr']) && count($calls) === $before && $text('tr', PropertyText::DRAFT)?->title === 'Valencia\'da satılık daire (düzeltildi)');

        // Approving: the English draft becomes the published text, reviewed by a person.
        PropertyTextWriter::approve($property->refresh(), 'en');
        $approved = $text('en', PropertyText::APPROVED);
        $this->check('aprobar el borrador EN: pasa a ser el texto publicado (source manual, modelo de referencia) y el borrador desaparece',
            $approved?->title === $answers['en']['title'] && $approved->source === PropertyText::SOURCE_MANUAL && $approved->model === 'proveedor/modelo-ficticio:free'
            && $text('en', PropertyText::DRAFT) === null && PropertyText::resolve($property->refresh(), 'en')['values']['title'] === $answers['en']['title']);

        // Translating again: the reviewed English and the corrected Turkish draft are left alone.
        $before = count($calls);
        $result = PropertyTextWriter::make()->translateApproved($property);
        $this->check('volver a traducir no toca la traducción EN revisada ni el borrador TR corregido (sin llamar a la IA)',
            $result['saved'] === [] && array_keys($result['skipped']) === ['en', 'tr'] && count($calls) === $before
            && $text('en', PropertyText::APPROVED)?->title === $answers['en']['title'] && $text('en', PropertyText::DRAFT) === null);

        // The Castilian changes: the English is flagged, a new proposal is a draft and the approved English stays published.
        $property->forceFill(['description' => 'Piso ficticio de 70 m² en venta en Valencia por 120.000 EUR. Texto aprobado y cambiado.'])->save();
        $result = PropertyTextWriter::make()->translateApproved($property, ['en']);
        $this->check('si cambia el castellano: nueva propuesta EN como borrador; la traducción aprobada sigue publicada y marcada para revisar',
            $result['saved'] === ['en'] && $text('en', PropertyText::DRAFT)?->source === PropertyText::SOURCE_AI
            && $text('en', PropertyText::APPROVED)?->title === $answers['en']['title'] && $text('en', PropertyText::APPROVED)?->review_required_at !== null);

        // Extension point: another TextCompleter (for example a self-hosted model) is used without the provider key.
        $binding = app()->getBindings()[TextCompleter::class]['concrete'] ?? null;
        AiSetting::current()->forceFill(['openrouter_api_key' => null])->save();
        app()->bind(TextCompleter::class, fn (): TextCompleter => new class implements TextCompleter
        {
            public function complete(string $task, string $system, string $user): array
            {
                return ['content' => (string) json_encode(['title' => 'Local model draft', 'description' => 'Local model description.']), 'model' => 'local/modelo-ficticio'];
            }

            public function missingConfiguration(): ?string
            {
                return null;
            }
        });
        $before = count($calls);
        $result = PropertyTextWriter::make()->regenerate($property, 'en');
        $this->check('punto de extensión: otro TextCompleter enlazado se usa sin clave del proveedor y sin llamar a OpenRouter',
            $result['saved'] === ['en'] && count($calls) === $before && $text('en', PropertyText::DRAFT)?->model === 'local/modelo-ficticio'
            && ClartoModules::status(ClartoModules::IDIOMAS) === ModuleStatus::Enabled);
        if ($binding !== null) {
            app()->bind(TextCompleter::class, $binding);
        }
    }

    private function pisos(): void
    {
        $this->info('5. Portal pisos.com');
        $property = $this->property();
        config(['pisos.driver' => 'simulado']);
        $listings = PortalListing::query()->whereNotNull('external_id')->count();

        ClartoModules::disable(ClartoModules::PISOS);
        $listing = PisosConnector::make()->publish($property);
        $this->check('desactivado: publicar se rechaza con el motivo y sin enviar', $listing->last_error === ClartoModules::refusal(ClartoModules::PISOS) && $listing->last_action === 'alta');
        $this->check('desactivado: los anuncios existentes se conservan', PortalListing::query()->whereNotNull('external_id')->count() === $listings);

        ClartoModules::enable(ClartoModules::PISOS);
        $listing = PisosConnector::make()->publish($property);
        $this->check('reactivado: publicar vuelve a llegar al conector (simulado)', $listing->last_error !== ClartoModules::refusal(ClartoModules::PISOS));
    }

    private function documentos(): void
    {
        $this->info('6. Documentos y formularios');
        if (! class_exists(self::MEDIA)) {
            $this->warn('SKIP  el paquete de documentos de inmueble no está instalado');

            return;
        }
        $property = $this->property();
        $document = fn (string $kind): Model => (new (self::MEDIA))->forceFill([
            'team_id' => $property->getAttribute('team_id'), 'property_id' => $property->getKey(), 'kind' => $kind, 'path' => 'pruebas/modulos-'.uniqid().'.pdf', 'title' => 'Prueba de módulos',
        ]);

        ClartoModules::enable(ClartoModules::DOCUMENTOS);
        $kept = $document('certificate');
        $kept->save();
        $documents = (self::MEDIA)::query()->count();

        ClartoModules::disable(ClartoModules::DOCUMENTOS);
        $this->check('desactivado: no se crea un documento nuevo', $this->refused(fn () => $document('certificate')->save()));
        $this->check('desactivado: las fotos y planos del inmueble no son documentos y siguen funcionando', ! $this->refused(fn () => $document('photo')->save()));
        $this->check('desactivado: los documentos existentes se conservan', (self::MEDIA)::query()->whereKey($kept->getKey())->exists() && (self::MEDIA)::query()->count() === $documents + 1);

        ClartoModules::enable(ClartoModules::DOCUMENTOS);
        $this->check('reactivado: se vuelven a crear documentos', ! $this->refused(fn () => $document('certificate')->save()));
    }

    private function firma(): void
    {
        $this->info('7. Firma electrónica');
        if (! class_exists(self::ENVELOPE)) {
            $this->warn('SKIP  el paquete oficial de sobres de firma no está instalado');

            return;
        }
        $envelopes = (self::ENVELOPE)::query()->count();

        ClartoModules::disable(ClartoModules::FIRMA);
        $this->check('desactivado: no se crea un sobre de firma', $this->refused(fn () => (self::ENVELOPE)::query()->create([])));
        $this->check('desactivado: los sobres existentes se conservan', (self::ENVELOPE)::query()->count() === $envelopes);

        ClartoModules::enable(ClartoModules::FIRMA);
        $this->check('reactivado: el módulo ya no bloquea la creación de sobres', ! $this->refused(fn () => (self::ENVELOPE)::query()->create([])));
    }

    private function whatsapp(): void
    {
        $this->info('8. WhatsApp');
        ClartoModules::disable(ClartoModules::WHATSAPP);
        $this->check('desactivado: enabled() = false y ensureEnabled() lo rechaza', ! ClartoModules::enabled(ClartoModules::WHATSAPP) && $this->refused(fn () => ClartoModules::ensureEnabled(ClartoModules::WHATSAPP)));
        ClartoModules::enable(ClartoModules::WHATSAPP);
        $this->check('reactivado: enabled() = true', ClartoModules::enabled(ClartoModules::WHATSAPP));
    }

    private function permissions(): void
    {
        $this->info('9. Permisos');
        $admin = User::query()->get()->first(fn (User $u): bool => method_exists($u, 'isAdmin') && $u->isAdmin());
        $this->check('un usuario sin rol de administrador no gestiona módulos', Gate::forUser(new User())->denies(ClartoModules::ABILITY));
        if ($admin !== null) {
            $this->check('un administrador gestiona módulos', Gate::forUser($admin)->allows(ClartoModules::ABILITY));
        }
        $plainAdmin = User::query()->get()->first(fn (User $u): bool => $u->isAdmin() && ! $u->isSuperAdmin());
        if ($plainAdmin !== null) {
            $this->check('la página «Modules» del paquete no se abre (administrador)', Gate::forUser($plainAdmin)->denies('manageModules'));
        }
        $this->check('la entrada de menú de la página «Modules» del paquete se oculta a todos (una sola «Módulos»)',
            str_contains(ClartoNavigation::technicalNavigationCss(), 'a[href$="/modules"]'));

        // Labels the packages hard-code in English, translated from lang/*.json without touching modules/.
        $locale = app()->getLocale();
        $label = function (string $name, string $text, string $in): string {
            app()->setLocale($in);

            return (string) \Filament\Actions\Action::make($name)->label($text)->getLabel();
        };
        $this->check('botón del Resumen traducido: «Ajustes de instalación» / «Configurar la plataforma» (es), «Kurulum ayarları» (tr), inglés en en',
            $label('setup', 'Installation settings', 'es') === 'Ajustes de instalación' && $label('setup', 'Set up platform', 'es') === 'Configurar la plataforma'
            && $label('setup', 'Installation settings', 'tr') === 'Kurulum ayarları' && $label('setup', 'Installation settings', 'en') === 'Installation settings');
        $this->check('control: otra acción con el mismo texto no se traduce (solo la del Resumen)', $label('otra', 'Installation settings', 'es') === 'Installation settings');
        app()->setLocale('es');
        $this->check('«Módulos»: correo entrante y solicitudes con nombre en castellano', ClartoModules::displayName('correo') === 'Correo entrante' && ClartoModules::displayName('solicitudes') === 'Solicitudes de inmuebles');
        app()->setLocale($locale);
    }

    /** Fictitious property of the first company, created inside the rolled-back transaction (the real database may have none). */
    private function property(): Property
    {
        $team = Team::query()->oldest()->firstOrFail();

        return Property::query()->create([
            'team_id' => $team->id, 'created_by' => $team->getAttribute('user_id'), 'title' => 'Piso ficticio para módulos', 'address' => 'Calle Ficticia 3, 46001 Valencia',
            'postal_code' => '46001', 'property_type' => 'apartment', 'status' => 'available', 'deal_type' => 'sale', 'price' => 120000, 'area_sqft' => 70,
        ]);
    }

    /** Did this new operation stop at the module gate? (Any other failure is the operation's own and rolled back.) */
    private function refused(callable $operation): bool
    {
        try {
            DB::transaction(fn () => $operation());

            return false;
        } catch (ModuleDisabled) {
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function check(string $label, bool $ok): void
    {
        $ok ? $this->pass++ : $this->fail++;
        $this->line(($ok ? 'PASS  ' : 'FAIL  ').$label);
    }
}
