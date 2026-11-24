<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Documents\CompanyDocument;
use App\Filament\Resources\CompanyDocumentResource;
use App\Models\User;
use App\Processes\Assistant\ProcessIndex;
use Filament\Facades\Filament;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Liberu\Foundation\Organizations\Models\Team;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * «Documentación de la empresa», end to end with fictitious companies, users and files: who has access by default,
 * the access action, uploads checked on the bytes, replacing files, expiry marks and notices, the file route
 * (management team 200 + log; staff, another company, no session and the disk's own route refused), the pages, and
 * isolation (admin panel only, no global search, no API, not in the assistant's index, referenced nowhere else).
 * Database in a rolled-back transaction, private disk faked: nothing is kept.
 *
 *   php artisan clarto:gestor-documental-prueba
 */
final class TestCompanyDocuments extends Command
{
    protected $signature = 'clarto:gestor-documental-prueba';

    protected $description = 'Test the company documents manager (access, uploads, expiry, logged downloads, isolation); nothing is kept';

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private int $pass = 0;

    private int $fail = 0;

    public function handle(): int
    {
        $root = sys_get_temp_dir().'/clarto-gestor-documental-prueba-'.bin2hex(random_bytes(4));
        Storage::fake(CompanyDocument::DISK, ['root' => $root]);
        config(['session.driver' => 'array']);
        app()->setLocale('es');

        DB::beginTransaction();
        try {
            $this->scenarios();
        } catch (Throwable $e) {
            $this->check('sin errores inesperados: '.$e::class.': '.$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine(), false);
        } finally {
            DB::rollBack();
            Auth::forgetGuards();
            File::deleteDirectory($root);
        }

        $this->line(sprintf('RESULT: %d passed, %d failed (database rolled back, private disk faked)', $this->pass, $this->fail));

        return $this->fail === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function scenarios(): void
    {
        $disk = Storage::disk(CompanyDocument::DISK);
        [$teamA, $teamB] = [$this->team('Inmobiliaria Ficticia A'), $this->team('Inmobiliaria Ficticia B')];
        $adminA = $this->user('Marta Admin Prueba', $teamA, 'admin');
        $staffA = $this->user('Ana Staff Prueba', $teamA, 'staff');
        $directorA = $this->user('Diego Director Prueba', $teamA, 'staff');
        $superA = $this->user('Root Prueba', $teamA, (string) config('filament-shield.super_admin.name', 'super_admin'));
        $adminB = $this->user('Otra Empresa Prueba', $teamB, 'admin');

        // 1. Permission and default access: administrators only.
        $this->check('existe el permiso «documentacion.ver» (guard web)',
            DB::table(config('permission.table_names.permissions', 'permissions'))->where('name', CompanyDocument::PERMISSION)->where('guard_name', 'web')->exists());
        $this->check('por defecto: administración y superadministración tienen acceso',
            CompanyDocument::canAccess($adminA, $teamA->id) && CompanyDocument::canAccess($superA, $teamA->id));
        $this->check('por defecto: el resto del personal no tiene acceso', ! CompanyDocument::canAccess($staffA, $teamA->id) && ! CompanyDocument::canAccess($directorA, $teamA->id));
        $this->check('administración de otra empresa: sin acceso', ! CompanyDocument::canAccess($adminB, $teamA->id));
        $this->check('sin sesión: sin acceso', ! CompanyDocument::canAccess(null, $teamA->id));
        $this->check('personal sin permiso: no puede crear documentos', $this->throws(fn () => CompanyDocument::saveFor($staffA, $teamA->id, $this->data([$this->pdf($disk, $staffA, 'x.pdf')]))));

        // 2. Upload: PDF + PNG checked on the bytes, moved next to the document, private disk only.
        $title = 'Escritura de constitución ficticia '.Str::random(6);
        $pdf = $this->pdf($disk, $adminA, 'escritura.pdf');
        $png = $this->upload($disk, $adminA, 'dni-socio.png', (string) base64_decode(self::PNG));
        $doc = CompanyDocument::saveFor($adminA, $teamA->id, $this->data([$pdf, $png], [
            'title' => $title, 'category' => 'escrituras', 'subject' => 'Socio Ficticio Uno', 'expires_at' => today()->addDays(50)->toDateString(),
        ], [$pdf => 'escritura.pdf', $png => 'dni-socio.png']));
        $files = (array) $doc->files;
        $this->check('alta con 2 archivos: PDF e imagen detectados por contenido, nombres originales',
            count($files) === 2 && $files[0]['mime'] === 'application/pdf' && $files[1]['mime'] === 'image/png' && $files[0]['name'] === 'escritura.pdf' && $files[1]['name'] === 'dni-socio.png');
        $this->check('archivos en el disco privado, junto al documento; nada en el disco público ni en public/',
            collect($files)->every(fn (array $f): bool => str_starts_with($f['path'], "gestor-documental/{$teamA->id}/{$doc->id}/") && $disk->exists($f['path'])
                && ! Storage::disk('public')->exists($f['path']) && ! file_exists(public_path('storage/'.$f['path'])))
            && ! $disk->exists($pdf) && ! $disk->exists($png));

        $fake = $this->upload($disk, $adminA, 'no-es-pdf.pdf', "MZ\x90\x00 esto es un ejecutable, no un PDF");
        $count = CompanyDocument::query()->count();
        $this->check('tipo no permitido (binario con extensión .pdf): rechazado y borrado',
            $this->throws(fn () => CompanyDocument::saveFor($adminA, $teamA->id, $this->data([$fake]))) && ! $disk->exists($fake) && CompanyDocument::query()->count() === $count);
        $foreign = $this->pdf($disk, $superA, 'ajeno.pdf');
        $this->check('archivo subido por otra persona: rechazado', $this->throws(fn () => CompanyDocument::saveFor($adminA, $teamA->id, $this->data([$foreign]))));
        $this->check('categoría inexistente: rechazada', $this->throws(fn () => CompanyDocument::saveFor($adminA, $teamA->id, $this->data([$this->pdf($disk, $adminA, 'y.pdf')], ['category' => 'nominas']))));
        $this->check('sin ningún archivo: rechazado', $this->throws(fn () => CompanyDocument::saveFor($adminA, $teamA->id, $this->data([]))));

        // 3. Expiry marks.
        $expired = CompanyDocument::saveFor($adminA, $teamA->id, $this->data([$this->pdf($disk, $adminA, 'poder.pdf')], ['title' => 'Poder caducado ficticio', 'category' => 'poderes', 'expires_at' => today()->subDay()->toDateString()]));
        $far = CompanyDocument::saveFor($adminA, $teamA->id, $this->data([$this->pdf($disk, $adminA, 'contrato.pdf')], ['title' => 'Contrato ficticio', 'category' => 'contratos_colaboracion', 'expires_at' => today()->addDays(200)->toDateString()]));
        $this->check('caducidad: caducado, caduca en menos de 60 días, lejos y sin fecha',
            $expired->expiryStatus() === 'caducado' && $doc->expiryStatus() === 'proximo' && $far->expiryStatus() === null
            && str_contains(CompanyDocumentResource::expiryText($expired), 'Caducado') && str_contains(CompanyDocumentResource::expiryText($doc), 'caduca en 50 días'));

        // 4. Notices: one per document entering the window, to everyone with access, never twice.
        $before = fn (User $u): int => DB::table('notifications')->where('notifiable_id', $u->id)->count();
        [$nAdmin, $nStaff] = [$before($adminA), $before($staffA)];
        $notified = CompanyDocument::notifyExpiring();
        $this->check('aviso de caducidad en la campana a quien tiene acceso (no al resto del personal)',
            $notified >= 2 && $before($adminA) >= $nAdmin + 2 && $before($staffA) === $nStaff && $doc->fresh()?->expiry_notified_at !== null && $far->fresh()?->expiry_notified_at === null);
        $this->check('el aviso no se repite al día siguiente', CompanyDocument::notifyExpiring() === 0);
        $this->check('el aviso diario está programado', collect(app(Schedule::class)->events())->contains(fn ($e): bool => $e->description === 'clarto-documentacion-caducidad'));

        // 5. Edit: replace a file (upload the new one, remove the old one); a new expiry date gets a new notice.
        $old = $files[0]['path'];
        $new = $this->pdf($disk, $adminA, 'escritura-v2.pdf');
        $doc = CompanyDocument::saveFor($adminA, $teamA->id, $this->data([$new], ['title' => $title, 'category' => 'escrituras', 'subject' => 'Socio Ficticio Uno', 'expires_at' => today()->addDays(40)->toDateString(), 'remove_files' => [0]], [$new => 'escritura-v2.pdf']), $doc);
        $this->check('sustituir archivo: el antiguo se borra del disco, el nuevo queda; nueva caducidad, nuevo aviso pendiente',
            collect((array) $doc->files)->pluck('name')->all() === ['dni-socio.png', 'escritura-v2.pdf'] && ! $disk->exists($old) && $doc->expiry_notified_at === null);

        // 6. File route: management team 200 + log; everyone else refused, without revealing the document.
        $url = route('clarto.documentacion.archivo', ['companyDocument' => $doc->id, 'index' => 1], false);
        $logs = fn (): int => $doc->logs()->count();
        $view = $this->get($url, $adminA);
        $this->check('administración: ver el archivo (inline, tipo correcto, nosniff, sin caché)',
            $view->getStatusCode() === 200 && $view->headers->get('Content-Type') === 'application/pdf' && str_contains((string) $view->headers->get('Content-Disposition'), 'inline')
            && $view->headers->get('X-Content-Type-Options') === 'nosniff' && str_contains((string) $view->headers->get('Cache-Control'), 'no-store'));
        $download = $this->get($url.'?descargar=1', $superA);
        $this->check('superadministración: descargar (attachment)', $download->getStatusCode() === 200 && str_contains((string) $download->headers->get('Content-Disposition'), 'attachment'));
        $last = $doc->logs()->with('user')->limit(2)->get();
        $this->check('registro de accesos: quién, qué archivo, ver/descargar y cuándo',
            $logs() === 2 && $last[0]->user?->is($superA) && $last[0]->action === 'descargar' && $last[1]->user?->is($adminA) && $last[1]->action === 'ver'
            && $last[0]->file_name === 'escritura-v2.pdf' && $last[0]->created_at !== null);
        $this->check('personal sin permiso: 404 y sin registro', $this->get($url, $staffA)->getStatusCode() === 404 && $logs() === 2);
        $this->check('administración de otra empresa: 404', $this->get($url, $adminB)->getStatusCode() === 404 && $logs() === 2);
        $this->check('sin sesión: no se sirve', $this->get($url, null)->getStatusCode() !== 200 && $logs() === 2);
        $this->check('índice de archivo inexistente: 404', $this->get(route('clarto.documentacion.archivo', ['companyDocument' => $doc->id, 'index' => 9], false), $adminA)->getStatusCode() === 404);
        $path = (string) ((array) $doc->files)[1]['path'];
        $direct = $this->get('/storage/'.$path, $adminA);
        $this->check('descarga directa por la ruta del disco (sin controlador ni política): falla', $direct->getStatusCode() !== 200 && ! str_contains((string) $direct->getContent(), '%PDF'));
        $route = Route::getRoutes()->getByName('clarto.documentacion.archivo');
        $this->check('la ruta exige sesión (auth) y no lleva el nombre del archivo', $route !== null && in_array('auth', $route->gatherMiddleware(), true) && ! str_contains($url, 'escritura'));

        // 7. «Gestionar acceso»: an administrator gives the permission; staff cannot; revoking takes it away.
        $this->check('personal sin permiso: no puede conceder acceso', $this->throws(fn () => CompanyDocument::grantDirectAccess($staffA, $teamA->id, [$staffA->id])));
        CompanyDocument::grantDirectAccess($adminA, $teamA->id, [$directorA->id, $adminB->id]);
        $this->check('administración concede acceso a una persona de su empresa (y no a alguien de otra)',
            CompanyDocument::canAccess($directorA, $teamA->id) && ! CompanyDocument::canAccess($staffA, $teamA->id) && ! CompanyDocument::canAccess($adminB, $teamA->id)
            && CompanyDocument::holders($teamA->id)->all() === [(string) $directorA->id] && CompanyDocument::holders($teamB->id)->isEmpty());
        $this->check('con el permiso: ve el archivo (y queda registrado)', $this->get($url, $directorA)->getStatusCode() === 200 && $logs() === 3);
        $this->as($directorA, $teamA);
        $this->check('con el permiso: no puede borrar documentos (solo administración)', ! Gate::allows('delete', $doc) && Gate::allows('update', $doc));
        CompanyDocument::grantDirectAccess($adminA, $teamA->id, []);
        $this->check('retirar el acceso: vuelve a 404', ! CompanyDocument::canAccess($directorA, $teamA->id) && $this->get($url, $directorA)->getStatusCode() === 404);

        // 8. Pages: list (search/filter/marks) and record (files + log) for administration; staff gets 403 and no menu.
        $list = CompanyDocumentResource::getUrl('index', panel: 'admin', tenant: $teamA);
        $this->page('lista: documento, categoría, persona y marca de caducado', $list, $adminA, [$title, 'Escrituras', 'Socio Ficticio Uno', 'Poder caducado ficticio', 'Caducado']);
        $this->page('ficha: archivos con Ver/Descargar y registro de accesos', CompanyDocumentResource::getUrl('view', ['record' => $doc], panel: 'admin', tenant: $teamA), $adminA,
            ['escritura-v2.pdf', 'Registro de accesos', 'ha descargado', 'Root Prueba', 'documentacion/'.$doc->id.'/archivos/1']);
        $this->check('personal sin permiso: la lista da 403', $this->get($list, $staffA)->getStatusCode() === 403);
        $this->check('personal sin permiso: la ficha da 403', $this->get(CompanyDocumentResource::getUrl('view', ['record' => $doc], panel: 'admin', tenant: $teamA), $staffA)->getStatusCode() === 403);
        $this->as($staffA, $teamA);
        Filament::setCurrentPanel('admin');
        Filament::setTenant($teamA, isQuiet: true);
        $this->check('personal sin permiso: no ve el menú', ! CompanyDocumentResource::canViewAny() && ! CompanyDocumentResource::canAccess());
        $this->as($adminA, $teamA);
        $this->check('administración: ve el menú «Organización → Documentación de la empresa»',
            CompanyDocumentResource::canViewAny() && CompanyDocumentResource::getNavigationLabel() === 'Documentación de la empresa');

        // 9. Isolation: admin panel only, no global search, no API, not indexed by the assistant, referenced nowhere else.
        $panels = collect(Filament::getPanels())->filter(fn ($p): bool => in_array(CompanyDocumentResource::class, $p->getResources(), true))->keys()->values()->all();
        $this->check('solo en el panel /admin (no en /app ni en el de colaboradores)', $panels === ['admin'], json_encode($panels));
        $this->check('fuera de la búsqueda global', ! CompanyDocumentResource::canGloballySearch());
        $api = collect(Route::getRoutes()->getRoutes())->filter(fn ($r): bool => str_starts_with($r->uri(), 'api') && str_contains((string) $r->getActionName(), 'CompanyDocument'));
        $this->check('ninguna ruta de la API sirve estos documentos', $api->isEmpty());
        if (Schema::hasTable(ProcessIndex::TABLE)) {
            ProcessIndex::rebuild($teamA->id);
        }
        $this->check('el asistente IA no los indexa', ! Schema::hasTable(ProcessIndex::TABLE) || ! DB::table(ProcessIndex::TABLE)->where('body', 'like', '%'.$title.'%')->orWhere('heading', 'like', '%'.$title.'%')->exists());
        $allowed = ['Documents/CompanyDocument', 'Filament/Resources/CompanyDocumentResource', 'Policies/CompanyDocumentPolicy.php', 'Http/Controllers/CompanyDocumentFileController.php',
            'Providers/ClartoCompanyDocumentsServiceProvider.php', 'Console/Commands/TestCompanyDocuments.php'];
        $users = collect(File::allFiles(app_path()))->filter(fn ($f): bool => $f->getExtension() === 'php' && preg_match('/\bCompanyDocument|clarto_company_document/', (string) file_get_contents($f->getPathname())) === 1)
            ->map(fn ($f): string => str_replace('\\', '/', $f->getRelativePathname()))
            ->reject(fn (string $p): bool => collect($allowed)->contains(fn (string $a): bool => str_starts_with($p, $a)))->values();
        $this->check('ningún otro código (asistente, Procesos, GHL, WhatsApp, web, API) usa estos documentos', $users->isEmpty(), $users->implode(', '));

        // 10. Delete: administration only; files go, the access log stays.
        $this->as($adminA, $teamA);
        $paths = collect((array) $doc->files)->pluck('path');
        $doc->delete();
        $this->check('borrar (administración): se borran los archivos y se conserva el registro de accesos',
            $paths->every(fn (string $p): bool => ! $disk->exists($p)) && DB::table('clarto_company_document_logs')->where('company_document_id', $doc->id)->count() === 3);
    }

    /**
     * @param  list<string>  $paths
     * @param  array<string, mixed>  $extra
     * @param  array<string, string>  $names
     * @return array<string, mixed>
     */
    private function data(array $paths, array $extra = [], array $names = []): array
    {
        return $extra + [
            'title' => 'Documento ficticio', 'category' => 'otra', 'subject' => null, 'document_date' => today()->subYear()->toDateString(),
            'expires_at' => null, 'notes' => 'Datos ficticios de prueba.', 'new_files' => $paths, 'new_file_names' => $names,
        ];
    }

    private function pdf(Filesystem $disk, User $user, string $name): string
    {
        return $this->upload($disk, $user, $name, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
    }

    private function upload(Filesystem $disk, User $user, string $name, string $content): string
    {
        $path = CompanyDocument::uploadDirectory($user->id).'/'.Str::random(8).'-'.$name;
        $disk->put($path, $content);

        return $path;
    }

    /** @param list<string> $expected */
    private function page(string $label, string $url, User $user, array $expected): void
    {
        $response = $this->get($url, $user);
        $html = (string) $response->getContent();
        $missing = array_values(array_filter($expected, fn (string $s): bool => ! str_contains($html, $s) && ! str_contains($html, e($s))));
        $this->check($label, $response->getStatusCode() === 200 && $missing === [],
            $response->getStatusCode() !== 200 ? 'HTTP '.$response->getStatusCode().' '.substr(trim((string) preg_replace('/\s+/', ' ', strip_tags($html))), 0, 300) : 'falta: '.implode(' | ', $missing));
    }

    private function as(User $user, Team $team): void
    {
        Auth::forgetGuards();
        Auth::guard('web')->setUser($user);
        setPermissionsTeamId($team->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function throws(callable $fn): bool
    {
        try {
            $fn();
        } catch (ValidationException) {
            return true;
        }

        return false;
    }

    private function team(string $name): Team
    {
        $owner = $this->newUser("Titular {$name}");
        $team = Team::withoutEvents(fn () => Team::query()->forceCreate(['user_id' => $owner->id, 'name' => $name, 'personal_team' => false]));
        $owner->forceFill(['current_team_id' => $team->id])->saveQuietly();

        return $team;
    }

    private function user(string $name, Team $team, string $role): User
    {
        $user = $this->newUser($name);
        $user->forceFill(['current_team_id' => $team->id])->saveQuietly();
        DB::table('team_user')->insert(['team_id' => $team->id, 'user_id' => $user->id, 'role' => 'editor', 'created_at' => now(), 'updated_at' => now()]);
        $roleId = DB::table('roles')->where(['team_id' => $team->id, 'name' => $role, 'guard_name' => 'web'])->value('id')
            ?? DB::table('roles')->insertGetId(['team_id' => $team->id, 'name' => $role, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('model_has_roles')->insert(['role_id' => $roleId, 'model_type' => $user->getMorphClass(), 'model_id' => $user->id, 'team_id' => $team->id]);

        return $user;
    }

    private function newUser(string $name): User
    {
        return User::withoutEvents(fn () => User::query()->forceCreate([
            'name' => $name, 'email' => 'prueba-gestor-documental-'.bin2hex(random_bytes(5)).'@example.com', 'password' => bcrypt(bin2hex(random_bytes(16))),
            'locale' => 'es', 'email_verified_at' => now(),
        ]));
    }

    private function get(string $uri, ?User $user): Response
    {
        app('session')->driver()->flush();   // the in-memory store keeps the previous request's attributes (AuthenticateSession hash)
        Auth::forgetGuards();
        if ($user !== null) {
            Auth::guard('web')->setUser($user);
        }

        return app(Kernel::class)->handle(Request::create($uri, 'GET'));
    }

    private function check(string $label, bool $ok, ?string $detail = null): void
    {
        $ok ? $this->pass++ : $this->fail++;
        $this->line(($ok ? 'PASS  ' : 'FAIL  ').$label.(! $ok && $detail ? '  → '.$detail : ''));
    }
}
