<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Documents\CompanyDocument;
use App\Documents\CompanyDocumentFolder;
use App\Documents\CompanyDocumentNotice;
use App\Filament\Resources\CompanyDocumentFolderResource;
use App\Filament\Resources\CompanyDocumentResource;
use App\Models\User;
use App\Processes\Assistant\ProcessIndex;
use Filament\Facades\Filament;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
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
 * «Documentación de la empresa», end to end with fictitious companies, users and files: access (administrators and
 * owner only), folders (starting set, subfolders, rename, move, no cycles, no deleting with content), uploads checked
 * on the bytes, replacing files, expiry marks and notices, approval (notice to every other responsable by bell and
 * e-mail, votes against the threshold, notice to the manager when reached, closing as approved / signed), the file
 * route (200 + log for responsables; staff, another company, no session and the disk's own route refused), the pages,
 * and isolation (admin panel only, no global search, no API, not in the assistant's index, referenced nowhere else).
 * Database in a rolled-back transaction, private disk faked, notifications faked: nothing is kept or sent.
 *
 *   php artisan clarto:gestor-documental-prueba
 */
final class TestCompanyDocuments extends Command
{
    protected $signature = 'clarto:gestor-documental-prueba';

    protected $description = 'Test the company documents manager (access, folders, uploads, approvals, notices, logged downloads, isolation); nothing is kept';

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private int $pass = 0;

    private int $fail = 0;

    public function handle(): int
    {
        $root = sys_get_temp_dir().'/clarto-gestor-documental-prueba-'.bin2hex(random_bytes(4));
        Storage::fake(CompanyDocument::DISK, ['root' => $root]);
        Notification::fake();
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

        $this->line(sprintf('RESULT: %d passed, %d failed (database rolled back, private disk faked, notifications faked)', $this->pass, $this->fail));

        return $this->fail === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function scenarios(): void
    {
        $disk = Storage::disk(CompanyDocument::DISK);
        [$teamA, $teamB] = [$this->team('Inmobiliaria Ficticia A'), $this->team('Inmobiliaria Ficticia B')];
        $ownerA = User::query()->findOrFail($teamA->getAttribute('user_id'));
        $this->role($ownerA, $teamA, 'staff');   // the owner works in the panel like the rest of the staff
        $adminA = $this->user('Marta Admin Prueba', $teamA, 'admin');
        $admin2A = $this->user('Luis Admin Prueba', $teamA, 'admin');
        $superA = $this->user('Root Prueba', $teamA, (string) config('filament-shield.super_admin.name', 'super_admin'));
        $staffA = $this->user('Ana Staff Prueba', $teamA, 'staff');
        $adminB = $this->user('Otra Empresa Prueba', $teamB, 'admin');

        // 1. Access: administrators, super administrators and the owner of the company. No assignable permission.
        $this->check('acceso: administración, superadministración y titular de la empresa',
            CompanyDocument::canAccess($adminA, $teamA->id) && CompanyDocument::canAccess($superA, $teamA->id) && CompanyDocument::canAccess($ownerA, $teamA->id));
        $this->check('sin acceso: personal (staff), otra empresa y sin sesión',
            ! CompanyDocument::canAccess($staffA, $teamA->id) && ! CompanyDocument::canAccess($adminB, $teamA->id) && ! CompanyDocument::canAccess(null, $teamA->id));
        $this->check('no existe un permiso asignable para entrar (solo roles admin / super_admin y titular)',
            ! DB::table(config('permission.table_names.permissions', 'permissions'))->where('name', 'like', 'documentacion%')->exists());
        $this->check('responsables de la empresa: los 4 con acceso', CompanyDocument::responsables($teamA->id)->pluck('id')->sort()->values()->all()
            === collect([$ownerA->id, $adminA->id, $admin2A->id, $superA->id])->sort()->values()->all());

        // 2. Folders: starting set once, subfolders, rename, no cycles, same company only.
        CompanyDocumentFolder::ensureDefaults($teamA->id);
        CompanyDocumentFolder::ensureDefaults($teamA->id);
        $this->check('carpetas iniciales (7) creadas una sola vez', CompanyDocumentFolder::query()->where('team_id', $teamA->id)->pluck('name')->sort()->values()->all()
            === collect(CompanyDocumentFolder::DEFAULTS)->sort()->values()->all());
        $folder = fn (string $name): CompanyDocumentFolder => CompanyDocumentFolder::query()->where('team_id', $teamA->id)->where('name', $name)->firstOrFail();
        [$collab, $deeds] = [$folder('Contratos de colaboración'), $folder('Escrituras')];
        $year = CompanyDocumentFolder::query()->create(['team_id' => $teamA->id, 'parent_id' => $collab->id, 'name' => '2026']);
        $year->update(['name' => 'Año 2026']);
        $collab->update(['name' => 'Colaboradores']);
        $this->check('subcarpeta y renombrado: ruta «Colaboradores / Año 2026»', (CompanyDocumentFolder::options($teamA->id)[$year->id] ?? null) === 'Colaboradores / Año 2026');
        $this->check('una carpeta no puede ir dentro de sí misma ni de una subcarpeta suya', $this->throws(fn () => $collab->update(['parent_id' => $year->id])));
        $this->check('una carpeta no puede ir dentro de una carpeta de otra empresa',
            $this->throws(fn () => CompanyDocumentFolder::query()->create(['team_id' => $teamB->id, 'parent_id' => $collab->id, 'name' => 'Intrusa'])));

        // 3. Upload: PDF + PNG checked on the bytes, moved next to the document, private disk only.
        $this->check('personal sin acceso: no puede crear documentos', $this->throws(fn () => CompanyDocument::saveFor($staffA, $teamA->id, $this->data($deeds, [$this->pdf($disk, $staffA, 'x.pdf')]))));
        $title = 'Escritura de constitución ficticia '.Str::random(6);
        $pdf = $this->pdf($disk, $adminA, 'escritura.pdf');
        $png = $this->upload($disk, $adminA, 'dni-socio.png', (string) base64_decode(self::PNG));
        $doc = CompanyDocument::saveFor($adminA, $teamA->id, $this->data($deeds, [$pdf, $png], [
            'title' => $title, 'subject' => 'Socio Ficticio Uno', 'expires_at' => today()->addDays(50)->toDateString(),
        ], [$pdf => 'escritura.pdf', $png => 'dni-socio.png']));
        $files = (array) $doc->files;
        $this->check('alta con 2 archivos en «Escrituras»: PDF e imagen detectados por contenido, nombres originales',
            (int) $doc->folder_id === $deeds->id && count($files) === 2 && $files[0]['mime'] === 'application/pdf' && $files[1]['mime'] === 'image/png'
            && $files[0]['name'] === 'escritura.pdf' && $files[1]['name'] === 'dni-socio.png');
        $this->check('archivos en el disco privado, junto al documento; nada en el disco público ni en public/',
            collect($files)->every(fn (array $f): bool => str_starts_with($f['path'], "gestor-documental/{$teamA->id}/{$doc->id}/") && $disk->exists($f['path'])
                && ! Storage::disk('public')->exists($f['path']) && ! file_exists(public_path('storage/'.$f['path'])))
            && ! $disk->exists($pdf) && ! $disk->exists($png));
        $fake = $this->upload($disk, $adminA, 'no-es-pdf.pdf', "MZ\x90\x00 esto es un ejecutable, no un PDF");
        $count = CompanyDocument::query()->count();
        $this->check('tipo no permitido (binario con extensión .pdf): rechazado y borrado',
            $this->throws(fn () => CompanyDocument::saveFor($adminA, $teamA->id, $this->data($deeds, [$fake]))) && ! $disk->exists($fake) && CompanyDocument::query()->count() === $count);
        $this->check('archivo subido por otra persona: rechazado', $this->throws(fn () => CompanyDocument::saveFor($adminA, $teamA->id, $this->data($deeds, [$this->pdf($disk, $superA, 'ajeno.pdf')]))));
        $folderB = CompanyDocumentFolder::query()->create(['team_id' => $teamB->id, 'name' => 'Carpeta de otra empresa']);
        $this->check('carpeta de otra empresa: rechazada', $this->throws(fn () => CompanyDocument::saveFor($adminA, $teamA->id, $this->data($folderB, [$this->pdf($disk, $adminA, 'y.pdf')]))));
        $this->check('sin ningún archivo: rechazado', $this->throws(fn () => CompanyDocument::saveFor($adminA, $teamA->id, $this->data($deeds, []))));

        // 4. Folders and documents: move, filter with subfolders, no deleting a folder with content.
        $contract = CompanyDocument::saveFor($adminA, $teamA->id, $this->data($collab, [$this->pdf($disk, $adminA, 'contrato.pdf')], [
            'title' => 'Contrato de colaboración ficticio', 'expires_at' => today()->addDays(200)->toDateString(),
        ]));
        $contract = CompanyDocument::saveFor($adminA, $teamA->id, $this->data($year, [], ['title' => 'Contrato de colaboración ficticio', 'expires_at' => today()->addDays(200)->toDateString()]), $contract);
        $this->check('mover un documento a otra carpeta (editar)', (int) $contract->fresh()?->folder_id === $year->id);
        $this->check('filtrar por carpeta incluye sus subcarpetas', CompanyDocument::query()->inFolder($collab)->pluck('id')->all() === [$contract->id]
            && CompanyDocument::query()->inFolder($deeds)->pluck('id')->all() === [$doc->id]);
        $this->check('carpeta con contenido: no se borra (ni la de documentos ni la de subcarpetas)',
            $year->delete() === false && $collab->delete() === false && CompanyDocumentFolder::query()->whereKey([$year->id, $collab->id])->count() === 2);
        $empty = CompanyDocumentFolder::query()->create(['team_id' => $teamA->id, 'parent_id' => $deeds->id, 'name' => 'Vacía']);
        $this->check('carpeta vacía: se borra', $empty->delete() === true && ! CompanyDocumentFolder::query()->whereKey($empty->id)->exists());

        // 5. Expiry marks and notices (bell + e-mail) to the responsables.
        $expired = CompanyDocument::saveFor($adminA, $teamA->id, $this->data($folder('Poderes'), [$this->pdf($disk, $adminA, 'poder.pdf')], ['title' => 'Poder caducado ficticio', 'expires_at' => today()->subDay()->toDateString()]));
        $this->check('caducidad: caducado, caduca en menos de 60 días, lejos',
            $expired->expiryStatus() === 'caducado' && $doc->expiryStatus() === 'proximo' && $contract->expiryStatus() === null
            && str_contains(CompanyDocumentResource::expiryText($expired), 'Caducado') && str_contains(CompanyDocumentResource::expiryText($doc), 'caduca en 50 días'));
        $notified = CompanyDocument::notifyExpiring();
        $expiry = fn (User $u): int => $this->notices($u, ['expiring', 'expired'])->count();
        $this->check('aviso de caducidad (campana y correo) a los responsables, no al personal ni a otra empresa',
            $notified >= 2 && $expiry($adminA) >= 2 && $expiry($ownerA) >= 2 && $expiry($staffA) === 0 && $expiry($adminB) === 0
            && $doc->fresh()?->expiry_notified_at !== null && $contract->fresh()?->expiry_notified_at === null);
        $this->check('el aviso de caducidad no se repite al día siguiente', CompanyDocument::notifyExpiring() === 0);
        $this->check('el aviso diario está programado', collect(app(Schedule::class)->events())->contains(fn ($e): bool => $e->description === 'clarto-documentacion-caducidad'));

        // 6. Edit: replace a file (upload the new one, remove the old one); a new expiry date gets a new notice.
        $old = $files[0]['path'];
        $new = $this->pdf($disk, $adminA, 'escritura-v2.pdf');
        $doc = CompanyDocument::saveFor($adminA, $teamA->id, $this->data($deeds, [$new], ['title' => $title, 'subject' => 'Socio Ficticio Uno', 'expires_at' => today()->addDays(40)->toDateString(), 'remove_files' => [0]], [$new => 'escritura-v2.pdf']), $doc);
        $this->check('sustituir archivo: el antiguo se borra del disco, el nuevo queda; nueva caducidad, nuevo aviso pendiente',
            collect((array) $doc->files)->pluck('name')->all() === ['dni-socio.png', 'escritura-v2.pdf'] && ! $disk->exists($old) && $doc->expiry_notified_at === null);

        // 7. Approval: notice to every other responsable, votes against the threshold, notice to the manager, closing.
        $this->check('pedir aprobación: más aprobaciones que responsables (3 sin el gestor) se rechaza', $this->throws(fn () => $contract->requestApproval($adminA, 4)));
        $this->check('personal sin acceso: no puede pedir aprobación', $this->throws(fn () => $contract->requestApproval($staffA, 1)));
        $contract->requestApproval($adminA, 2);
        $contract->refresh();
        $pending = fn (User $u) => $this->notices($u, ['pending']);
        $this->check('pendiente de aprobación: estado, gestor y umbral guardados',
            $contract->status === CompanyDocument::PENDING && (string) $contract->review_requested_by === (string) $adminA->id && (int) $contract->approvals_required === 2);
        $this->check('aviso «pendiente de aprobación» a todos los demás responsables, por campana y correo',
            collect([$admin2A, $superA, $ownerA])->every(fn (User $u): bool => $pending($u)->count() === 1 && $pending($u)->first()['channels'] === ['database', 'mail']));
        $this->check('no se avisa al gestor, ni al personal, ni a otra empresa', $pending($adminA)->isEmpty() && $pending($staffA)->isEmpty() && $pending($adminB)->isEmpty());
        $mail = $pending($admin2A)->first()['notification']->toMail($admin2A);
        $text = implode(' ', [$mail->subject, ...$mail->introLines, ...$mail->outroLines]);
        $this->check('correo: título, carpeta, quién lo pide, aprobaciones necesarias y botón a la ficha; sin adjuntos',
            str_contains($text, 'Contrato de colaboración ficticio') && str_contains($text, 'Colaboradores / Año 2026') && str_contains($text, 'Marta Admin Prueba')
            && str_contains($text, 'Aprobaciones necesarias: 2 de 3') && $mail->actionUrl === $contract->url() && str_contains($mail->actionUrl, '/admin/')
            && $mail->attachments === [] && $mail->rawAttachments === [] && ! str_contains($text, 'contrato.pdf'), $text);
        $this->check('el gestor no vota; el personal sin acceso tampoco', $this->throws(fn () => $contract->vote($adminA, true)) && $this->throws(fn () => $contract->vote($staffA, true)));
        $contract->vote($admin2A, true);
        $this->check('recuento contra el umbral: «1 de 2 aprobaciones necesarias (3 responsables)»; sin aviso al gestor todavía',
            $contract->approvalProgress() === '1 de 2 aprobaciones necesarias (3 responsables)' && $this->notices($adminA, ['threshold'])->isEmpty());
        $contract->vote($superA, false, 'Falta la cláusula de confidencialidad.');
        $this->check('un «No conforme» no suma', $contract->approvalCount() === 1);
        $contract->vote($ownerA, true);
        $threshold = $this->notices($adminA, ['threshold']);
        $this->check('umbral alcanzado: aviso al gestor (campana y correo) y el documento NO avanza solo',
            $threshold->count() === 1 && $threshold->first()['channels'] === ['database', 'mail'] && $contract->fresh()?->status === CompanyDocument::PENDING
            && $this->notices($admin2A, ['threshold'])->isEmpty());
        $contract->vote($ownerA, false);
        $contract->vote($ownerA, true);
        $this->check('cambiar el voto y volver: el aviso de umbral no se repite', $this->notices($adminA, ['threshold'])->count() === 1 && $contract->approvalCount() === 2);
        $this->check('cerrar como «Firmado» sin copia firmada: rechazado', $this->throws(fn () => $contract->changeStatus($adminA, CompanyDocument::SIGNED)));
        $signed = $this->pdf($disk, $adminA, 'contrato-firmado.pdf');
        $contract->changeStatus($adminA, CompanyDocument::SIGNED, [$signed], [$signed => 'contrato-firmado.pdf']);
        $contract->refresh();
        $this->check('cerrar como «Firmado» con la copia: estado, archivo añadido y votos conservados',
            $contract->status === CompanyDocument::SIGNED && collect((array) $contract->files)->pluck('name')->last() === 'contrato-firmado.pdf' && $contract->votes()->count() === 3);
        $this->check('un documento firmado no se vuelve a cerrar', $this->throws(fn () => $contract->changeStatus($adminA, CompanyDocument::APPROVED)));
        $other = CompanyDocument::saveFor($adminA, $teamA->id, $this->data($folder('Contratos con proveedores'), [$this->pdf($disk, $adminA, 'proveedor.pdf')], ['title' => 'Contrato con proveedor ficticio']));
        $other->requestApproval($superA, 1);
        $other->vote($adminA, true);
        $other->changeStatus($superA, CompanyDocument::APPROVED);
        $this->check('otro gestor, umbral 1: aprobado sin copia firmada', $other->fresh()?->status === CompanyDocument::APPROVED && $this->notices($superA, ['threshold'])->count() === 1);
        $pendingDoc = CompanyDocument::saveFor($adminA, $teamA->id, $this->data($collab, [$this->pdf($disk, $adminA, 'adenda.pdf')], ['title' => 'Adenda ficticia']));
        $pendingDoc->requestApproval($ownerA, 3);

        // 8. File route: responsables 200 + log; everyone else refused, without revealing the document.
        $url = route('clarto.documentacion.archivo', ['companyDocument' => $doc->id, 'index' => 1], false);
        $logs = fn (): int => $doc->logs()->count();
        $view = $this->get($url, $adminA);
        $this->check('administración: ver el archivo (inline, tipo correcto, nosniff, sin caché)',
            $view->getStatusCode() === 200 && $view->headers->get('Content-Type') === 'application/pdf' && str_contains((string) $view->headers->get('Content-Disposition'), 'inline')
            && $view->headers->get('X-Content-Type-Options') === 'nosniff' && str_contains((string) $view->headers->get('Cache-Control'), 'no-store'));
        $download = $this->get($url.'?descargar=1', $superA);
        $this->check('superadministración: descargar (attachment)', $download->getStatusCode() === 200 && str_contains((string) $download->headers->get('Content-Disposition'), 'attachment'));
        $this->check('titular de la empresa: ver el archivo', $this->get($url, $ownerA)->getStatusCode() === 200);
        $last = $doc->logs()->with('user')->limit(3)->get();
        $this->check('registro de accesos: quién, qué archivo, ver/descargar y cuándo',
            $logs() === 3 && $last[0]->user?->is($ownerA) && $last[1]->user?->is($superA) && $last[1]->action === 'descargar' && $last[2]->user?->is($adminA) && $last[2]->action === 'ver'
            && $last[1]->file_name === 'escritura-v2.pdf' && $last[1]->created_at !== null);
        $this->check('personal sin acceso: 404 y sin registro', $this->get($url, $staffA)->getStatusCode() === 404 && $logs() === 3);
        $this->check('administración de otra empresa: 404', $this->get($url, $adminB)->getStatusCode() === 404 && $logs() === 3);
        $this->check('sin sesión: no se sirve', $this->get($url, null)->getStatusCode() !== 200 && $logs() === 3);
        $this->check('índice de archivo inexistente: 404', $this->get(route('clarto.documentacion.archivo', ['companyDocument' => $doc->id, 'index' => 9], false), $adminA)->getStatusCode() === 404);
        $direct = $this->get('/storage/'.((array) $doc->files)[1]['path'], $adminA);
        $this->check('descarga directa por la ruta del disco (sin controlador ni política): falla', $direct->getStatusCode() !== 200 && ! str_contains((string) $direct->getContent(), '%PDF'));
        $route = Route::getRoutes()->getByName('clarto.documentacion.archivo');
        $this->check('la ruta exige sesión (auth) y no lleva el nombre del archivo', $route !== null && in_array('auth', $route->gatherMiddleware(), true) && ! str_contains($url, 'escritura'));

        // 9. Pages: list, record and folders for responsables; staff gets 403 and no menu.
        $list = CompanyDocumentResource::getUrl('index', panel: 'admin', tenant: $teamA);
        $this->page('lista: título, carpeta con ruta, estados con recuento y marca de caducado', $list, $adminA,
            [$title, 'Escrituras', 'Colaboradores / Año 2026', 'Firmado', 'Pendiente de aprobación · 0 de 3 aprobaciones necesarias (3 responsables)', 'Caducado']);
        $this->page('ficha: archivos, aprobación con quién ha respondido qué y registro de accesos',
            CompanyDocumentResource::getUrl('view', ['record' => $contract], panel: 'admin', tenant: $teamA), $adminA,
            ['contrato-firmado.pdf', 'Firmado', 'Luis Admin Prueba · Conforme', 'Root Prueba · No conforme · «Falta la cláusula de confidencialidad.»', 'Registro de accesos']);
        $this->page('ficha pendiente: recuento y botón «Responder» para otro responsable',
            CompanyDocumentResource::getUrl('view', ['record' => $pendingDoc], panel: 'admin', tenant: $teamA), $adminA, ['0 de 3 aprobaciones necesarias (3 responsables)', 'Responder']);
        $this->page('carpetas: lista con rutas', CompanyDocumentFolderResource::getUrl('index', panel: 'admin', tenant: $teamA), $adminA, ['Colaboradores / Año 2026', 'Documentos de identidad de socios']);
        $this->check('personal sin acceso: la lista da 403', $this->get($list, $staffA)->getStatusCode() === 403);
        $this->check('personal sin acceso: la ficha da 403', $this->get(CompanyDocumentResource::getUrl('view', ['record' => $doc], panel: 'admin', tenant: $teamA), $staffA)->getStatusCode() === 403);
        $this->check('personal sin acceso: las carpetas dan 403', $this->get(CompanyDocumentFolderResource::getUrl('index', panel: 'admin', tenant: $teamA), $staffA)->getStatusCode() === 403);
        $this->as($staffA, $teamA);
        Filament::setCurrentPanel('admin');
        Filament::setTenant($teamA, isQuiet: true);
        $this->check('personal sin acceso: no ve el menú', ! CompanyDocumentResource::canViewAny() && ! CompanyDocumentResource::canAccess());
        $this->as($adminA, $teamA);
        $this->check('administración: ve el menú «Organización → Documentación de la empresa»',
            CompanyDocumentResource::canViewAny() && CompanyDocumentResource::getNavigationLabel() === 'Documentación de la empresa');

        // 10. Isolation: admin panel only, no global search, no API, not indexed by the assistant, referenced nowhere else.
        $panels = collect(Filament::getPanels())->filter(fn ($p): bool => in_array(CompanyDocumentResource::class, $p->getResources(), true)
            || in_array(CompanyDocumentFolderResource::class, $p->getResources(), true))->keys()->values()->all();
        $this->check('solo en el panel /admin (no en /app ni en el de colaboradores)', $panels === ['admin'], json_encode($panels));
        $this->check('fuera de la búsqueda global', ! CompanyDocumentResource::canGloballySearch() && ! CompanyDocumentFolderResource::canGloballySearch());
        $api = collect(Route::getRoutes()->getRoutes())->filter(fn ($r): bool => str_starts_with($r->uri(), 'api') && str_contains((string) $r->getActionName(), 'CompanyDocument'));
        $this->check('ninguna ruta de la API sirve estos documentos', $api->isEmpty());
        if (Schema::hasTable(ProcessIndex::TABLE)) {
            ProcessIndex::rebuild($teamA->id);
        }
        $this->check('el asistente IA no los indexa', ! Schema::hasTable(ProcessIndex::TABLE) || ! DB::table(ProcessIndex::TABLE)->where('body', 'like', '%'.$title.'%')->orWhere('heading', 'like', '%'.$title.'%')->exists());
        $allowed = ['Documents/CompanyDocument', 'Filament/Resources/CompanyDocument', 'Policies/CompanyDocumentPolicy.php', 'Http/Controllers/CompanyDocumentFileController.php',
            'Providers/ClartoCompanyDocumentsServiceProvider.php', 'Console/Commands/TestCompanyDocuments.php'];
        $users = collect(File::allFiles(app_path()))->filter(fn ($f): bool => $f->getExtension() === 'php' && preg_match('/\bCompanyDocument|clarto_company_document/', (string) file_get_contents($f->getPathname())) === 1)
            ->map(fn ($f): string => str_replace('\\', '/', $f->getRelativePathname()))
            ->reject(fn (string $p): bool => collect($allowed)->contains(fn (string $a): bool => str_starts_with($p, $a)))->values();
        $this->check('ningún otro código (asistente, Procesos, GHL, WhatsApp, web, API) usa estos documentos', $users->isEmpty(), $users->implode(', '));
        $this->check('los avisos solo usan campana y correo del panel (nunca GHL ni WhatsApp)',
            collect([$adminA, $admin2A, $superA, $ownerA])->flatMap(fn (User $u) => $this->notices($u, ['pending', 'threshold', 'expiring', 'expired']))
                ->every(fn (array $sent): bool => $sent['channels'] === ['database', 'mail']));

        // 11. Delete: files and votes go, the access log stays.
        $paths = collect((array) $doc->files)->pluck('path');
        $doc->delete();
        $contract->delete();
        $this->check('borrar: se borran los archivos y los votos; se conserva el registro de accesos',
            $paths->every(fn (string $p): bool => ! $disk->exists($p)) && DB::table('clarto_company_document_logs')->where('company_document_id', $doc->id)->count() === 3
            && DB::table('clarto_company_document_votes')->where('company_document_id', $contract->id)->doesntExist());
    }

    /**
     * Notices of these kinds sent (faked) to $user, with their channels.
     *
     * @param  list<string>  $kinds
     * @return Collection<int, array{notification: CompanyDocumentNotice, channels: list<string>}>
     */
    private function notices(User $user, array $kinds): Collection
    {
        $found = collect();
        Notification::sent($user, CompanyDocumentNotice::class, function (CompanyDocumentNotice $n, array $channels) use ($kinds, $found): bool {
            if (in_array($n->kind, $kinds, true)) {
                $found->push(['notification' => $n, 'channels' => $channels]);
            }

            return true;
        });

        return $found;
    }

    /**
     * @param  list<string>  $paths
     * @param  array<string, mixed>  $extra
     * @param  array<string, string>  $names
     * @return array<string, mixed>
     */
    private function data(CompanyDocumentFolder $folder, array $paths, array $extra = [], array $names = []): array
    {
        return $extra + [
            'folder_id' => $folder->id, 'title' => 'Documento ficticio', 'subject' => null, 'document_date' => today()->subYear()->toDateString(),
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
        $this->role($user, $team, $role);

        return $user;
    }

    private function role(User $user, Team $team, string $role): void
    {
        $roleId = DB::table('roles')->where(['team_id' => $team->id, 'name' => $role, 'guard_name' => 'web'])->value('id')
            ?? DB::table('roles')->insertGetId(['team_id' => $team->id, 'name' => $role, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('model_has_roles')->insert(['role_id' => $roleId, 'model_type' => $user->getMorphClass(), 'model_id' => $user->id, 'team_id' => $team->id]);
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
