<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Acquisition\GhlHandoff;
use App\Ai\PropertyTextWriter;
use App\Filament\ClartoNavigation;
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
        foreach ([ClartoModules::FIRMA, ClartoModules::WHATSAPP] as $key) {
            if (! ClartoModules::isImplemented($key)) {
                ClartoModules::enable($key);
                $this->check("«{$key}» sin implementación en esta rama: «Pendiente de implementar», no «Activado»", ClartoModules::status($key) === ModuleStatus::NotImplemented);
            }
        }
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
        $property = Property::query()->first();
        if ($property === null) {
            $this->warn('SKIP  no hay inmuebles en esta base de datos');

            return;
        }

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

    private function pisos(): void
    {
        $this->info('5. Portal pisos.com');
        $property = Property::query()->first();
        if ($property === null) {
            $this->warn('SKIP  no hay inmuebles en esta base de datos');

            return;
        }
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
        if (! class_exists(self::MEDIA) || ($sample = (self::MEDIA)::query()->first()) === null) {
            $this->warn('SKIP  no hay documentos de inmueble para copiar en esta base de datos');

            return;
        }
        $document = fn (string $kind): Model => tap($sample->replicate(), function (Model $copy) use ($kind): void {
            $copy->forceFill(['kind' => $kind, 'path' => 'pruebas/modulos-'.uniqid().'.pdf', 'title' => 'Prueba de módulos']);
        });

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
        $this->check('un usuario sin rol de administrador no gestiona módulos', Gate::forUser(new User)->denies(ClartoModules::ABILITY));
        if ($admin !== null) {
            $this->check('un administrador gestiona módulos', Gate::forUser($admin)->allows(ClartoModules::ABILITY));
        }
        $plainAdmin = User::query()->get()->first(fn (User $u): bool => $u->isAdmin() && ! $u->isSuperAdmin());
        if ($plainAdmin !== null) {
            $this->check('la página «Modules» del paquete no se abre (administrador)', Gate::forUser($plainAdmin)->denies('manageModules'));
        }
        $this->check('la entrada de menú de la página «Modules» del paquete se oculta a todos (una sola «Módulos»)',
            str_contains(ClartoNavigation::technicalNavigationCss(), 'a[href$="/modules"]'));
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
