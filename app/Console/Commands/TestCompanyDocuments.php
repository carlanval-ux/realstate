<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Documents\CompanyDocument;
use App\Documents\CompanyDocumentAnnotation;
use App\Documents\CompanyDocumentFolder;
use App\Documents\CompanyDocumentNotice;
use App\Filament\Resources\CompanyDocumentFolderResource;
use App\Filament\Resources\CompanyDocumentResource;
use App\Filament\Resources\CompanyDocumentResource\Pages\ManageCompanyDocuments;
use App\Filament\Resources\CompanyDocumentResource\Pages\ViewCompanyDocument;
use App\Models\User;
use App\Processes\Assistant\ProcessIndex;
use Filament\Facades\Filament;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * «Documentación de la empresa», end to end with fictitious companies, users and files: access (administrators and
 * owner only), folders (starting set, subfolders, rename, move, no cycles, no deleting with content), uploads checked
 * on the bytes, replacing files, expiry marks and notices, approval (notice to every other responsable by bell and
 * e-mail, votes against the threshold, notice to the manager when reached, closing as approved / signed), the file
 * route (200 + log for responsables; staff, another company, no session and the disk's own route refused), the pages,
 * isolation (admin panel only, no global search, no API, not in the assistant's index, referenced nowhere else), the
 * expedientes (described files, 20-file limit, annotation thread with attachments served and logged like the files,
 * deleting only by the author within 15 minutes), the migration of the old notes into annotations and the approval
 * decided at upload («Disponible» by default, «¿Requiere aprobación?» in the new expediente form, the null → disponible
 * migration).
 * Database in a rolled-back transaction, private disk faked, notifications faked: nothing is kept or sent.
 *
 *   php artisan clarto:gestor-documental-prueba
 */
final class TestCompanyDocuments extends Command
{
    protected $signature = 'clarto:gestor-documental-prueba';

    protected $description = 'Test the company documents manager (access, folders, expedientes, uploads, annotations, approvals, approval at upload, notices, logged downloads, isolation, migrations); nothing is kept';

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

        $this->expedientes($disk, $teamA, $teamB, $folder('Escrituras'), $adminA, $admin2A, $ownerA, $staffA, $adminB);
        $this->notesMigration($disk, $teamA, $folder('Poderes'), $adminA);
        $this->approvalAtUpload($disk, $teamA, $folder('Documentación fiscal'), $adminA, $admin2A, $superA, $ownerA, $staffA);
    }

    /**
     * 14. Approval decided at upload: «Disponible» by default and without notices; «¿Requiere aprobación?» puts it
     * pending with whoever uploads it as manager; the number checked (form and server); nobody else to vote; the edit
     * form without the question; «Pedir aprobación» from «Disponible»; list, filter and colours; the null → disponible migration.
     */
    private function approvalAtUpload(Filesystem $disk, Team $teamA, CompanyDocumentFolder $folder, User $adminA, User $admin2A, User $superA, User $ownerA, User $staffA): void
    {
        $everyone = [$adminA, $admin2A, $superA, $ownerA, $staffA];
        $sent = fn (): array => array_map(fn (User $u): int => $this->notices($u, ['pending'])->count(), $everyone);
        $form = fn (string $title, array $extra, ?string $upload = null): array => CompanyDocumentResource::fromForm($this->data($folder, [], $extra + ['title' => $title, 'items' => [
            ['index' => null, 'upload' => $upload ?? $this->pdf($disk, $adminA, 'fiscal.pdf'), 'upload_name' => 'fiscal.pdf', 'description' => null],
        ]]));

        // Without approval: «Disponible», no manager, no threshold, no notice.
        $before = $sent();
        $plain = CompanyDocument::saveFor($adminA, $teamA->id, $form('Certificado fiscal ficticio a disposición', ['requires_approval' => false, 'approvals_required' => 2]));
        $bare = CompanyDocument::saveFor($adminA, $teamA->id, $this->data($folder, [$this->pdf($disk, $adminA, 'modelo.pdf')], ['title' => 'Modelo fiscal ficticio sin pregunta']));
        $this->check('alta sin aprobación: queda «Disponible» (también sin la pregunta), sin gestor ni umbral y sin ningún aviso',
            $plain->fresh()?->status === CompanyDocument::AVAILABLE && $bare->fresh()?->status === CompanyDocument::AVAILABLE
            && $plain->review_requested_by === null && $plain->approvals_required === null && $plain->approvalProgress() === null && $sent() === $before);

        // With approval: pending, the uploader manages it, the other responsables get bell + e-mail.
        $before = $sent();
        $asked = CompanyDocument::saveFor($adminA, $teamA->id, $form('Declaración fiscal ficticia para aprobar', ['requires_approval' => true, 'approvals_required' => '2']))->fresh();
        $after = $sent();
        $notice = $this->notices($admin2A, ['pending'])->last();
        $this->check('alta con aprobación: «Pendiente», gestiona quien lo sube, umbral guardado',
            $asked?->status === CompanyDocument::PENDING && (string) $asked->review_requested_by === (string) $adminA->id && (int) $asked->approvals_required === 2
            && $asked->review_requested_at !== null && $asked->approvalProgress() === '0 de 2 aprobaciones necesarias (3 responsables)');
        $this->check('alta con aprobación: aviso por campana y correo a los otros 3 responsables; ni a quien lo sube ni al personal',
            $after[1] === $before[1] + 1 && $after[2] === $before[2] + 1 && $after[3] === $before[3] + 1 && $after[0] === $before[0] && $after[4] === $before[4]
            && $notice['channels'] === ['database', 'mail'] && $notice['notification']->url === $asked?->url()
            && $notice['notification']->params['title'] === 'Declaración fiscal ficticia para aprobar', json_encode([$before, $after]));

        // The number of approvals: between 1 and the other responsables, checked before anything is saved.
        $count = CompanyDocument::query()->count();
        $before = $sent();
        $upload = $this->pdf($disk, $adminA, 'fiscal-reintento.pdf');
        $this->check('alta con aprobación: 0 o más que los demás responsables (4 de 3) se rechaza; no se guarda nada, no se avisa y la subida sigue para reintentar',
            $this->throws(fn () => CompanyDocument::saveFor($adminA, $teamA->id, $form('Fuera de rango', ['requires_approval' => true, 'approvals_required' => 0], $upload)))
            && $this->throws(fn () => CompanyDocument::saveFor($adminA, $teamA->id, $form('Fuera de rango', ['requires_approval' => true, 'approvals_required' => 4], $upload)))
            && $this->throws(fn () => CompanyDocument::saveFor($adminA, $teamA->id, $form('Sin número', ['requires_approval' => true], $upload)))
            && CompanyDocument::query()->count() === $count && $sent() === $before && $disk->exists($upload));
        $edited = CompanyDocument::saveFor($adminA, $teamA->id, $this->data($folder, [], ['title' => $plain->title, 'requires_approval' => true, 'approvals_required' => 1]), $plain);
        $this->check('al editar, la pregunta no cuenta: sigue «Disponible» y no se avisa', $edited->fresh()?->status === CompanyDocument::AVAILABLE && $sent() === $before);

        // Nobody else to vote: the server refuses it and the form explains it (switch disabled).
        $teamC = $this->team('Inmobiliaria Ficticia C');
        $soleOwner = User::query()->findOrFail($teamC->getAttribute('user_id'));
        $this->role($soleOwner, $teamC, 'staff');
        Filament::setTenant($teamC, isQuiet: true);   // the panel's tenant scope, left on by the page requests above
        $folderC = CompanyDocumentFolder::query()->create(['team_id' => $teamC->id, 'name' => 'Fiscal C']);
        $sole = fn (array $extra): CompanyDocument => CompanyDocument::saveFor($soleOwner, $teamC->id, $this->data($folderC, [$this->pdf($disk, $soleOwner, 'unico.pdf')], $extra + ['title' => 'Documento ficticio de responsable único']));
        $this->check('un solo responsable: no se puede pedir aprobación en el alta; sin pedirla queda «Disponible»',
            CompanyDocument::otherResponsables($teamC->id, $soleOwner->id)->isEmpty()
            && $this->throws(fn () => $sole(['requires_approval' => true, 'approvals_required' => 1]))
            && $sole([])->status === CompanyDocument::AVAILABLE);

        // The forms (Livewire): the question in «Nuevo expediente» only, with its number field and validation.
        Filament::setCurrentPanel('admin');
        $this->as($adminA, $teamA);
        Filament::setTenant($teamA, isQuiet: true);
        // The modal is not part of the page's HTML: its fields are read from the mounted action's schema.
        $fields = fn ($test): array => $test->instance()->getSchema((string) $test->instance()->getMountedActionSchemaName())?->getFlatFields() ?? [];
        $help = fn ($field): string => collect($field?->getChildSchema($field::BELOW_CONTENT_SCHEMA_KEY)?->getComponents() ?? [])
            ->map(fn ($text): string => method_exists($text, 'getContent') ? (string) $text->getContent() : '')->implode(' ');
        $create = Livewire::test(ManageCompanyDocuments::class)->call('mountAction', 'create');
        $closed = $fields($create);
        $default = $create->get('mountedActions.0.data.requires_approval');
        $create->set('mountedActions.0.data.requires_approval', true);
        $open = $fields($create);
        $create->set('mountedActions.0.data.approvals_required', 4)->call('callMountedAction');
        $tooMany = $create->errors()->has('mountedActions.0.data.approvals_required');
        $create->set('mountedActions.0.data.approvals_required', 3)->call('callMountedAction');
        $this->check('formulario de alta: «¿Requiere aprobación?» (No por defecto) con su ayuda; con «Sí» aparece «Aprobaciones necesarias» (de 1 a 3)',
            isset($closed['requires_approval']) && $closed['requires_approval']->getLabel() === '¿Requiere aprobación?' && $default === false
            && ! $closed['requires_approval']->isDisabled() && $help($closed['requires_approval']) === __('documentacion.upload_approval.help', ['voters' => 3])
            && ! isset($closed['approvals_required']) && isset($open['approvals_required']) && $open['approvals_required']->getLabel() === 'Aprobaciones necesarias'
            && $tooMany && ! $create->errors()->has('mountedActions.0.data.approvals_required'),
            json_encode(['fields' => array_keys($closed), 'default' => $default, 'help' => $help($closed['requires_approval'] ?? null), 'errors' => $create->errors()->toArray()]));
        $made = CompanyDocumentFolder::createFor($adminA, $teamA->id, ' Seguros ');
        $sub = CompanyDocumentFolder::createFor($adminA, $teamA->id, '2026', $made->id);
        $denied = fn (callable $f): bool => rescue(function () use ($f): bool {
            $f();

            return false;
        }, true, false);
        $this->check('«Nueva carpeta» desde el expediente: el desplegable la ofrece y crea carpetas y subcarpetas; solo responsables y en su empresa',
            isset($closed['folder_id']) && $closed['folder_id']->hasCreateOptionActionFormSchema()
            && $made->team_id == $teamA->id && $made->name === 'Seguros' && $sub->parent_id == $made->id
            && (CompanyDocumentFolder::options($teamA->id)[$sub->id] ?? null) === 'Seguros / 2026'
            && $denied(fn () => CompanyDocumentFolder::createFor($staffA, $teamA->id, 'No'))
            && $denied(fn () => CompanyDocumentFolder::createFor($adminB, $teamA->id, 'No'))
            && $denied(fn () => CompanyDocumentFolder::createFor($adminB, $teamB->id, 'Ajena', $made->id)),
            json_encode([array_keys($closed), $made->only('team_id', 'name'), $sub->parent_id]));
        $edit = $fields(Livewire::test(ViewCompanyDocument::class, ['record' => $plain->id])->call('mountAction', 'edit'));
        $this->check('formulario de edición: sin la pregunta (la aprobación se pide desde la ficha)',
            isset($edit['title']) && ! isset($edit['requires_approval']) && ! isset($edit['approvals_required']), json_encode(array_keys($edit)));
        $this->as($soleOwner, $teamC);
        Filament::setTenant($teamC, isQuiet: true);
        $soleForm = $fields(Livewire::test(ManageCompanyDocuments::class)->call('mountAction', 'create'));
        $this->check('formulario de alta con un solo responsable: la opción está desactivada y se explica por qué',
            isset($soleForm['requires_approval']) && $soleForm['requires_approval']->isDisabled()
            && $help($soleForm['requires_approval']) === __('documentacion.upload_approval.no_voters'), json_encode(array_keys($soleForm)));

        // The record of a «Disponible» one, and «Pedir aprobación» from there.
        $recordUrl = CompanyDocumentResource::getUrl('view', ['record' => $plain], panel: 'admin', tenant: $teamA);
        $html = (string) $this->get($recordUrl, $adminA)->getContent();
        $this->check('ficha «Disponible»: estado y explicación, botón «Pedir aprobación»; sin «Responder» ni «Cerrar aprobación»',
            str_contains($html, 'Disponible') && str_contains($html, e(__('documentacion.available_help'))) && str_contains($html, 'Pedir aprobación')
            && ! str_contains($html, 'Responder') && ! str_contains($html, 'Cerrar aprobación'));
        $this->check('un «Disponible» no se cierra como aprobado ni firmado sin pedir antes la aprobación', $this->throws(fn () => $plain->changeStatus($adminA, CompanyDocument::APPROVED)));
        $before = $sent();
        $plain->requestApproval($admin2A, 1);
        $plain->refresh();
        $after = $sent();
        $this->check('«Pedir aprobación» desde «Disponible»: pendiente, gestiona quien la pide y se avisa a los demás',
            $plain->status === CompanyDocument::PENDING && (string) $plain->review_requested_by === (string) $admin2A->id && (int) $plain->approvals_required === 1
            && $after[0] === $before[0] + 1 && $after[2] === $before[2] + 1 && $after[3] === $before[3] + 1 && $after[1] === $before[1] && $after[4] === $before[4]);

        // List and status filter: four statuses, four colours.
        $labels = array_map(CompanyDocument::statusLabel(...), CompanyDocument::STATUSES);
        $this->check('estados: Disponible, Pendiente de aprobación, Aprobado y Firmado, con colores de etiqueta distintos',
            $labels === ['Disponible', 'Pendiente de aprobación', 'Aprobado', 'Firmado']
            && count(array_unique(array_map(CompanyDocumentResource::statusColor(...), CompanyDocument::STATUSES))) === 4);
        $this->as($adminA, $teamA);
        Filament::setTenant($teamA, isQuiet: true);
        $filtered = function (string $status): array {
            $page = Livewire::test(ManageCompanyDocuments::class)->set('tableFilters.status.value', $status)->instance();

            return $page instanceof ManageCompanyDocuments ? $page->getFilteredTableQuery()?->pluck('id')->map(fn ($id): int => (int) $id)->all() ?? [] : [];
        };
        [$available, $pending] = [$filtered(CompanyDocument::AVAILABLE), $filtered(CompanyDocument::PENDING)];
        $this->check('filtro de estado: «Disponible» y «Pendiente de aprobación» separan los expedientes',
            in_array($bare->id, $available, true) && ! in_array($asked?->id, $available, true) && in_array($asked?->id, $pending, true) && ! in_array($bare->id, $pending, true),
            json_encode(compact('available', 'pending')));
        $list = (string) Livewire::test(ManageCompanyDocuments::class)->set('tableFilters.status.value', CompanyDocument::AVAILABLE)->html();
        $this->check('lista: la etiqueta «Disponible» en la columna de estado', str_contains($list, e($bare->title)) && preg_match('/class="fi-badge[^"]*">\s*Disponible\s*</', $list) === 1);

        // Migration: a null status becomes «disponible»; the others keep theirs (data only, any engine).
        DB::table('clarto_company_documents')->where('id', $bare->id)->update(['status' => null]);
        $migration = require database_path('migrations/2026_12_12_000001_set_available_status_on_clarto_company_documents.php');
        $migration->up();
        $this->check('migración: un expediente con estado nulo pasa a «disponible»; pendiente y firmado no cambian',
            $bare->fresh()?->status === CompanyDocument::AVAILABLE && $asked?->fresh()?->status === CompanyDocument::PENDING
            && DB::table('clarto_company_documents')->whereNull('status')->doesntExist());
        $migration->down();
        $this->check('migración (vuelta atrás): «disponible» vuelve a nulo y el resto no cambia',
            $bare->fresh()?->status === null && $asked?->fresh()?->status === CompanyDocument::PENDING);
        $migration->up();
    }

    /** 12. Expedientes: described files through the form rows, the 20-file limit, the annotation thread and its attachments. */
    private function expedientes(Filesystem $disk, Team $teamA, Team $teamB, CompanyDocumentFolder $deeds, User $adminA, User $admin2A, User $ownerA, User $staffA, User $adminB): void
    {
        // Described files, entered as the form's rows (CompanyDocumentResource::fromForm).
        [$deed, $annex] = [$this->pdf($disk, $adminA, 'escritura-exp.pdf'), $this->pdf($disk, $adminA, 'anexo-exp.pdf')];
        $exp = CompanyDocument::saveFor($adminA, $teamA->id, CompanyDocumentResource::fromForm($this->data($deeds, [], ['title' => 'Expediente ficticio de constitución', 'items' => [
            ['index' => null, 'upload' => $deed, 'upload_name' => 'escritura-exp.pdf', 'description' => '  Escritura de constitución  '],
            ['index' => null, 'upload' => $annex, 'upload_name' => 'anexo-exp.pdf', 'description' => ''],
        ]])));
        $files = (array) $exp->files;
        $this->check('expediente: cada archivo con su descripción (opcional); la ficha muestra la descripción o, si no hay, el nombre',
            count($files) === 2 && $files[0]['description'] === 'Escritura de constitución' && $files[1]['description'] === null
            && CompanyDocument::fileLabel($files[0]) === 'Escritura de constitución' && CompanyDocument::fileLabel($files[1]) === 'anexo-exp.pdf'
            && CompanyDocument::fileLabel(['name' => 'subido-antes.pdf', 'path' => 'x', 'mime' => 'application/pdf', 'size' => 1]) === 'subido-antes.pdf', json_encode($files));
        $front = $this->upload($disk, $adminA, 'dni-anverso.png', (string) base64_decode(self::PNG));
        $exp = CompanyDocument::saveFor($adminA, $teamA->id, CompanyDocumentResource::fromForm($this->data($deeds, [], ['title' => $exp->title, 'items' => [
            ...CompanyDocumentResource::fileItems($exp),
            ['index' => null, 'upload' => $front, 'upload_name' => 'dni-anverso.png', 'description' => 'DNI anverso'],
        ]]), $exp), $exp);
        $items = CompanyDocumentResource::fileItems($exp);
        $items[1]['description'] = 'Anexo I';
        unset($items[0]);   // the row of the deed is deleted: the file goes
        $removed = (array) $exp->files;
        $exp = CompanyDocument::saveFor($adminA, $teamA->id, CompanyDocumentResource::fromForm($this->data($deeds, [], ['title' => $exp->title, 'items' => $items]), $exp), $exp);
        $this->check('editar: añadir un archivo con descripción, describir uno existente y quitar otro borrando su fila',
            array_map(CompanyDocument::fileLabel(...), (array) $exp->files) === ['Anexo I', 'DNI anverso'] && ! $disk->exists($removed[0]['path'])
            && ((array) $exp->files)[0]['name'] === 'anexo-exp.pdf', json_encode($exp->files));
        $view = $this->get(CompanyDocumentResource::getUrl('view', ['record' => $exp], panel: 'admin', tenant: $teamA), $adminA);
        $html = (string) $view->getContent();
        $this->check('ficha: muestra «Anexo I» y «DNI anverso» en lugar de los nombres de archivo',
            $view->getStatusCode() === 200 && str_contains($html, 'Anexo I') && str_contains($html, 'DNI anverso') && ! str_contains($html, 'anexo-exp.pdf') && ! str_contains($html, 'dni-anverso.png'));

        // 20 files per expediente.
        $twenty = array_map(fn (int $i): string => $this->pdf($disk, $adminA, "parte-{$i}.pdf"), range(1, 20));
        $full = CompanyDocument::saveFor($adminA, $teamA->id, $this->data($deeds, $twenty, ['title' => 'Expediente ficticio con veinte archivos']));
        $this->check('límite: un expediente admite 20 archivos', count((array) $full->files) === 20);
        $this->check('límite: el archivo 21 se rechaza (al editar y en el alta)',
            $this->throws(fn () => CompanyDocument::saveFor($adminA, $teamA->id, $this->data($deeds, [$this->pdf($disk, $adminA, 'parte-21.pdf')], ['title' => $full->title]), $full))
            && count((array) $full->fresh()?->files) === 20
            && $this->throws(fn () => CompanyDocument::saveFor($adminA, $teamA->id, $this->data($deeds, array_map(fn (int $i): string => $this->pdf($disk, $adminA, "otra-{$i}.pdf"), range(1, 21))))));
        $this->check('límite: la copia firmada tampoco pasa de 20', $this->throws(function () use ($full, $adminA, $disk): void {
            $full->forceFill(['status' => CompanyDocument::APPROVED])->save();
            $full->changeStatus($adminA, CompanyDocument::SIGNED, [$this->pdf($disk, $adminA, 'firmado-21.pdf')]);
        }));

        // Annotations: any responsable, text required, at most one attachment checked like the files.
        $first = $exp->annotate($adminA, "Pedida la copia simple al notario.\nPendiente de recibir.");
        $attachment = $this->pdf($disk, $admin2A, 'justificante.pdf');
        $second = $exp->annotate($admin2A, 'Recibida la copia simple.', $attachment, 'justificante.pdf');
        $third = $exp->annotate($ownerA, 'Revisado por la titular.');
        $thread = $exp->annotations()->get();
        $this->check('anotaciones: hilo cronológico con fecha, autor y texto; cualquier responsable (también la titular) anota',
            $thread->pluck('id')->all() === [$first->id, $second->id, $third->id] && $thread[0]->user_id === $adminA->id && $thread[2]->user_id === $ownerA->id
            && $thread[0]->body === "Pedida la copia simple al notario.\nPendiente de recibir." && $thread[0]->created_at !== null && $thread[0]->file === null);
        $this->check('anotación con adjunto: PDF por contenido, en el disco privado junto al expediente; la subida se mueve',
            $second->file !== null && $second->file['mime'] === 'application/pdf' && $second->file['name'] === 'justificante.pdf'
            && str_starts_with($second->file['path'], "gestor-documental/{$teamA->id}/{$exp->id}/anotaciones/") && $disk->exists($second->file['path']) && ! $disk->exists($attachment)
            && ! Storage::disk('public')->exists($second->file['path']));
        $fake = $this->upload($disk, $adminA, 'no-es-pdf.pdf', "MZ\x90\x00 ejecutable");
        $this->check('adjunto no permitido (binario con extensión .pdf): rechazado, borrado y sin anotación',
            $this->throws(fn () => $exp->annotate($adminA, 'Con adjunto falso', $fake, 'no-es-pdf.pdf')) && ! $disk->exists($fake) && $exp->annotations()->count() === 3);
        $this->check('adjunto subido por otra persona: rechazado', $this->throws(fn () => $exp->annotate($adminA, 'Ajeno', $this->pdf($disk, $admin2A, 'ajeno.pdf'))));
        $this->check('anotación vacía: rechazada', $this->throws(fn () => $exp->annotate($adminA, "  \n ")));
        $this->check('personal sin acceso y otra empresa: no pueden anotar',
            $this->throws(fn () => $exp->annotate($staffA, 'Intento')) && $this->throws(fn () => $exp->annotate($adminB, 'Intento')) && $exp->annotations()->count() === 3);

        // The attachment: same controller and policy, 404 without access, every access logged.
        $url = route('clarto.documentacion.anotacion', ['companyDocument' => $exp->id, 'annotation' => $second->id], false);
        $logs = fn (): int => $exp->logs()->count();
        $before = $logs();
        $seen = $this->get($url, $ownerA);
        $got = $this->get($url.'?descargar=1', $adminA);
        $log = $exp->logs()->with('user')->limit(2)->get();
        $this->check('adjunto: los responsables lo ven (inline, nosniff, sin caché) y lo descargan',
            $seen->getStatusCode() === 200 && $seen->headers->get('Content-Type') === 'application/pdf' && str_contains((string) $seen->headers->get('Content-Disposition'), 'inline')
            && $seen->headers->get('X-Content-Type-Options') === 'nosniff' && str_contains((string) $seen->headers->get('Cache-Control'), 'no-store')
            && $got->getStatusCode() === 200 && str_contains((string) $got->headers->get('Content-Disposition'), 'attachment'));
        $this->check('adjunto: cada acceso queda en el registro del expediente (quién, ver/descargar, archivo)',
            $logs() === $before + 2 && $log[0]->user?->is($adminA) && $log[0]->action === 'descargar' && $log[1]->user?->is($ownerA) && $log[1]->action === 'ver'
            && $log[0]->file_name === 'justificante.pdf');
        $this->check('adjunto: personal sin acceso 404, otra empresa 404, sin sesión no se sirve; nada se registra',
            $this->get($url, $staffA)->getStatusCode() === 404 && $this->get($url, $adminB)->getStatusCode() === 404 && $this->get($url, null)->getStatusCode() !== 200
            && $logs() === $before + 2);
        $this->check('adjunto: por la URL de otro expediente o de una anotación sin adjunto, 404',
            $this->get(route('clarto.documentacion.anotacion', ['companyDocument' => $full->id, 'annotation' => $second->id], false), $adminA)->getStatusCode() === 404
            && $this->get(route('clarto.documentacion.anotacion', ['companyDocument' => $exp->id, 'annotation' => $first->id], false), $adminA)->getStatusCode() === 404);
        $route = Route::getRoutes()->getByName('clarto.documentacion.anotacion');
        $this->check('la ruta del adjunto exige sesión (auth) y no lleva el nombre del archivo',
            $route !== null && in_array('auth', $route->gatherMiddleware(), true) && ! str_contains($url, 'justificante'));

        // The thread on the record; «Borrar» only for the author's recent annotations.
        $recordUrl = CompanyDocumentResource::getUrl('view', ['record' => $exp], panel: 'admin', tenant: $teamA);
        $this->page('ficha: hilo de anotaciones con autor, texto y adjunto', $recordUrl, $adminA,
            ['Anotaciones', 'Pedida la copia simple al notario.', 'Luis Admin Prueba', 'Recibida la copia simple.', 'justificante.pdf', 'Añadir anotación']);
        $button = fn (CompanyDocumentAnnotation $a): string => "mountAction('deleteAnnotation', { annotation: {$a->id} })";
        $asAdmin = (string) $this->get($recordUrl, $adminA)->getContent();
        $this->check('ficha: «Borrar» solo en las anotaciones propias y recientes',
            str_contains($asAdmin, $button($first)) && ! str_contains($asAdmin, $button($second)) && ! str_contains($asAdmin, $button($third)));

        // Deleting: author only, first 15 minutes; never edited.
        $this->check('borrar anotación: otra persona no puede', $this->throws(fn () => $first->deleteBy($admin2A)) && $first->fresh() !== null);
        $old = $third->forceFill(['created_at' => now()->subMinutes(CompanyDocumentAnnotation::DELETE_MINUTES + 1)]);
        $old->save();
        $this->check('borrar anotación: pasados 15 minutos, ni su autora', $this->throws(fn () => $old->deleteBy($ownerA)) && $old->fresh() !== null);
        $path = (string) $second->file['path'];
        $second->deleteBy($admin2A);
        $this->check('borrar anotación: su autor, dentro de los 15 minutos; el adjunto se borra del disco',
            $second->fresh() === null && ! $disk->exists($path) && $this->get($url, $adminA)->getStatusCode() === 404);
        $this->check('las anotaciones no tienen fecha de modificación (no se editan)', ! Schema::hasColumn('clarto_company_document_annotations', 'updated_at'));

        // Deleting the expediente takes its annotations and their attachments; the log stays.
        $last = $exp->annotate($adminA, 'Última anotación', $this->pdf($disk, $adminA, 'ultimo.pdf'), 'ultimo.pdf');
        $logged = $logs();
        $exp->delete();
        $this->check('borrar el expediente: se van sus anotaciones y sus adjuntos; el registro se conserva',
            $exp->annotations()->doesntExist() && ! $disk->exists((string) $last->file['path']) && DB::table('clarto_company_document_logs')->where('company_document_id', $exp->id)->count() === $logged);
    }

    /**
     * 13. The migration that turns each document's `notes` into its first annotation (author: its creator; date: its
     * created_at) and drops the column, run for real (down + up) inside the rolled-back transaction. SQLite only:
     * there DDL is transactional; elsewhere it would be committed, so it is skipped.
     */
    private function notesMigration(Filesystem $disk, Team $teamA, CompanyDocumentFolder $folder, User $adminA): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->line('SKIP  migración de notas: solo se prueba en SQLite (en este motor la DDL no se deshace)');

            return;
        }
        $legacy = CompanyDocument::saveFor($adminA, $teamA->id, $this->data($folder, [$this->pdf($disk, $adminA, 'poder-antiguo.pdf')], ['title' => 'Poder ficticio con notas']));
        $blank = CompanyDocument::saveFor($adminA, $teamA->id, $this->data($folder, [$this->pdf($disk, $adminA, 'poder-sin-notas.pdf')], ['title' => 'Poder ficticio sin notas']));
        $created = now()->subYear()->startOfMinute();
        DB::table('clarto_company_documents')->where('id', $legacy->id)->update(['created_at' => $created]);
        $migration = require database_path('migrations/2026_12_11_000001_create_clarto_company_document_annotations_table.php');
        $migration->down();
        $this->check('migración (vuelta atrás): la columna «notes» vuelve', Schema::hasColumn('clarto_company_documents', 'notes'));
        DB::table('clarto_company_documents')->where('id', $legacy->id)->update(['notes' => "Nota antigua ficticia.\nSegunda línea."]);
        DB::table('clarto_company_documents')->where('id', $blank->id)->update(['notes' => "  \n "]);
        $migration->up();
        $notes = DB::table('clarto_company_document_annotations')->where('company_document_id', $legacy->id)->get();
        $this->check('migración: las notas pasan a ser la primera anotación (autor: quien creó el documento; fecha: su alta)',
            $notes->count() === 1 && $notes[0]->body === "Nota antigua ficticia.\nSegunda línea." && (int) $notes[0]->user_id === $adminA->id
            && $notes[0]->file === null && Carbon::parse($notes[0]->created_at)->equalTo($created), json_encode($notes));
        $this->check('migración: unas notas en blanco no crean anotación',
            DB::table('clarto_company_document_annotations')->where('company_document_id', $blank->id)->doesntExist());
        $this->check('migración: la columna «notes» desaparece y el formulario ya no la tiene',
            ! Schema::hasColumn('clarto_company_documents', 'notes') && ! str_contains((string) file_get_contents(app_path('Filament/Resources/CompanyDocumentResource.php')), "'notes'"));
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
            'expires_at' => null, 'new_files' => $paths, 'new_file_names' => $names,
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
