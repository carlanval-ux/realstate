<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Documents\CompanyDocument;
use App\Documents\CompanyDocumentAnnotation;
use App\Documents\CompanyDocumentFolder;
use App\Documents\CompanyDocumentNotice;
use App\Documents\CompanyDocumentPreview;
use App\Documents\CompanyDocumentVote;
use App\Filament\Resources\CompanyDocumentFolderResource;
use App\Filament\Resources\CompanyDocumentResource;
use App\Filament\Resources\CompanyDocumentResource\Pages\ManageCompanyDocuments;
use App\Filament\Resources\CompanyDocumentResource\Pages\ViewCompanyDocument;
use App\Models\User;
use App\Processes\Assistant\ProcessIndex;
use Filament\Facades\Filament;
use Filament\Support\Colors\Color;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
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
use Symfony\Component\HttpFoundation\StreamedResponse;
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
 * migration), Word / Excel / OpenDocument (real files accepted, fakes and macros refused, always downloaded) and the
 * expediente's «Explicación» (saved, on the record, searched, in both forms, its migration) and the approval rule
 * «Mayoría absoluta» (5 and 4 voters → 3, saved and shown, threshold notice, fixed number still in range, its migration)
 * and the three answers (a favor, con condiciones, en contra; the count; «Aprobado con condiciones»; the automatic
 * rejection with its single notice and a new round; the migration of the old votes) and the list's two tabs («Carpetas»:
 * path, folder contents, ?carpeta=, recursive search, new / rename folder, new expediente in the open folder;
 * «Aprobaciones»: only what asked for approval; no access for staff, another company or no session) and the web viewer
 * (PDF / image frames served inline and framable by the panel only, Word / Excel converted by a simulated LibreOffice and
 * cached, «Vista previa no disponible», previous / next, the same refusals).
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
        $this->check('el gestor no vota; el personal sin acceso tampoco', $this->throws(fn () => $contract->vote($adminA, CompanyDocumentVote::FAVOR)) && $this->throws(fn () => $contract->vote($staffA, CompanyDocumentVote::FAVOR)));
        $contract->vote($admin2A, CompanyDocumentVote::FAVOR);
        $this->check('recuento contra el umbral: «1 a favor · 0 con condiciones · 0 en contra · 2 sin votar (necesarias: 2)»; sin aviso al gestor todavía',
            $contract->approvalProgress() === '1 a favor · 0 con condiciones · 0 en contra · 2 sin votar (necesarias: 2)' && $this->notices($adminA, ['threshold'])->isEmpty(), (string) $contract->approvalProgress());
        $contract->vote($superA, CompanyDocumentVote::AGAINST, 'Falta la cláusula de confidencialidad.');
        $this->check('un «En contra» no suma (y uno solo no rechaza: 1 no es más que 3 − 2)', $contract->approvalCount() === 1 && $contract->fresh()?->status === CompanyDocument::PENDING);
        $contract->vote($ownerA, CompanyDocumentVote::FAVOR);
        $threshold = $this->notices($adminA, ['threshold']);
        $this->check('umbral alcanzado: aviso al gestor (campana y correo) y el documento NO avanza solo',
            $threshold->count() === 1 && $threshold->first()['channels'] === ['database', 'mail'] && $contract->fresh()?->status === CompanyDocument::PENDING
            && $this->notices($admin2A, ['threshold'])->isEmpty());
        $contract->vote($ownerA, CompanyDocumentVote::CONDITIONS, 'Revisar la cláusula de duración.');
        $contract->vote($ownerA, CompanyDocumentVote::FAVOR);
        $this->check('cambiar el voto y volver: el aviso de umbral no se repite', $this->notices($adminA, ['threshold', 'threshold_conditions'])->count() === 1 && $contract->approvalCount() === 2);
        $this->check('cerrar como «Firmado» sin copia firmada: rechazado', $this->throws(fn () => $contract->changeStatus($adminA, CompanyDocument::SIGNED)));
        $signed = $this->pdf($disk, $adminA, 'contrato-firmado.pdf');
        $contract->changeStatus($adminA, CompanyDocument::SIGNED, [$signed], [$signed => 'contrato-firmado.pdf']);
        $contract->refresh();
        $this->check('cerrar como «Firmado» con la copia: estado, archivo añadido y votos conservados',
            $contract->status === CompanyDocument::SIGNED && collect((array) $contract->files)->pluck('name')->last() === 'contrato-firmado.pdf' && $contract->votes()->count() === 3);
        $this->check('un documento firmado no se vuelve a cerrar', $this->throws(fn () => $contract->changeStatus($adminA, CompanyDocument::APPROVED)));
        $other = CompanyDocument::saveFor($adminA, $teamA->id, $this->data($folder('Contratos con proveedores'), [$this->pdf($disk, $adminA, 'proveedor.pdf')], ['title' => 'Contrato con proveedor ficticio']));
        $other->requestApproval($superA, 1);
        $other->vote($adminA, CompanyDocumentVote::FAVOR);
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
        $this->page('lista, pestaña «Aprobaciones»: carpeta con ruta, estados con recuento', $list.'?tab=aprobaciones', $adminA,
            ['Colaboradores / Año 2026', 'Firmado', 'Pendiente de aprobación · 0 a favor · 0 con condiciones · 0 en contra · 3 sin votar (necesarias: 3)']);
        $this->page('lista, pestaña «Carpetas» en «Escrituras»: ruta, título y caducidad', $list.'?tab=carpetas&carpeta='.$deeds->id, $adminA,
            ['Inicio', 'Escrituras', $title, 'caduca en 40 días']);
        $this->page('lista, pestaña «Carpetas» en «Poderes»: marca de caducado', $list.'?carpeta='.$folder('Poderes')->id, $adminA, ['Poder caducado ficticio', 'Caducado']);
        $this->page('ficha: archivos, aprobación con quién ha respondido qué y registro de accesos',
            CompanyDocumentResource::getUrl('view', ['record' => $contract], panel: 'admin', tenant: $teamA), $adminA,
            ['contrato-firmado.pdf', 'Firmado', 'Luis Admin Prueba · A favor', 'Root Prueba · En contra · «Falta la cláusula de confidencialidad.»', 'Registro de accesos']);
        $this->page('ficha pendiente: recuento y botón «Responder» para otro responsable',
            CompanyDocumentResource::getUrl('view', ['record' => $pendingDoc], panel: 'admin', tenant: $teamA), $adminA, ['0 a favor · 0 con condiciones · 0 en contra · 3 sin votar (necesarias: 3)', 'Responder']);
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
        $this->approvalAtUpload($disk, $teamA, $teamB, $folder('Documentación fiscal'), $adminA, $admin2A, $superA, $ownerA, $staffA, $adminB);
        $this->officeAndExplanation($disk, $teamA, $folder('Contratos con proveedores'), $adminA, $ownerA, $staffA);
        $this->majorityRule($disk);
        $this->votesConditionsAndRejection($disk);
        $this->folderNavigation($disk);
        $this->webViewer($disk);
    }

    /**
     * 19. The web viewer: «Ver» on every file opens …/visor/archivo-N (or anotacion-ID) inside the panel with
     * «Anterior» / «Siguiente» / «Descargar»; PDF in a frame and images as they are, from a route served inline and
     * framable by the panel only (X-Frame-Options SAMEORIGIN, frame-ancestors 'self'), logged as «ha visto»; Word /
     * Excel converted to PDF on the server (a simulated LibreOffice here; the real one when it is installed), cached on
     * the private disk and removed with the file; what cannot be converted says «Vista previa no disponible» with the
     * download. Staff, another company and no session get nothing. A company of its own: owner + admin + staff.
     */
    private function webViewer(Filesystem $disk): void
    {
        $team = $this->team('Inmobiliaria Ficticia Visor');
        $other = $this->team('Inmobiliaria Ficticia Visor Ajena');
        $owner = User::query()->findOrFail($team->getAttribute('user_id'));
        $this->role($owner, $team, 'staff');
        $admin = $this->user('Clara Admin Ficticia', $team, 'admin');
        $staff = $this->user('Pepe Staff Ficticio', $team, 'staff');
        $otherAdmin = $this->user('Admin Visor Ajena Ficticia', $other, 'admin');
        Filament::setTenant($team, isQuiet: true);
        $folder = CompanyDocumentFolder::query()->create(['team_id' => $team->id, 'name' => 'Escrituras']);
        $pdf = $this->pdf($disk, $admin, 'escritura-ficticia.pdf');
        $png = $this->upload($disk, $admin, 'dni-ficticio.png', (string) base64_decode(self::PNG));
        $docx = $this->office($disk, $admin, 'poder-ficticio.docx', ['word/document.xml' => '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Poder ficticio</w:t></w:r></w:p></w:body></w:document>']);
        $xlsx = $this->office($disk, $admin, 'cuentas-ficticias.xlsx', ['xl/workbook.xml' => '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheets><sheet name="Hoja1" sheetId="1"/></sheets></workbook>']);
        $doc = CompanyDocument::saveFor($admin, $team->id, $this->data($folder, [$pdf, $png, $docx, $xlsx], ['title' => 'Escritura ficticia con visor'],
            [$pdf => 'escritura-ficticia.pdf', $png => 'dni-ficticio.png', $docx => 'poder-ficticio.docx', $xlsx => 'cuentas-ficticias.xlsx']));
        $note = $doc->annotate($admin, 'Adjunto la copia ficticia.', $this->pdf($disk, $admin, 'copia-ficticia.pdf'), 'copia-ficticia.pdf');

        // A simulated LibreOffice: writes a small PDF for Word, fails for Excel; records what it was given.
        $calls = [];
        app()->instance(CompanyDocumentPreview::class, new CompanyDocumentPreview(function (string $input, string $outDir) use (&$calls): ?string {
            $calls[] = basename($input);
            if (str_ends_with($input, '.xlsx')) {
                return null;
            }
            file_put_contents("{$outDir}/documento.pdf", "%PDF-1.4\n% vista ficticia\n%%EOF\n");

            return "{$outDir}/documento.pdf";
        }));

        $this->check('tipos del visor: PDF, imagen, Word/Excel/OpenDocument (convertidos) y nada más',
            CompanyDocumentPreview::kind('application/pdf') === 'pdf' && CompanyDocumentPreview::kind('image/png') === 'image'
            && CompanyDocumentPreview::kind(CompanyDocument::OFFICE_TYPES['docx']) === 'office' && CompanyDocumentPreview::kind(CompanyDocument::OFFICE_TYPES['ods']) === 'office'
            && CompanyDocumentPreview::kind('application/zip') === null);
        $items = CompanyDocumentPreview::items($doc);
        $this->check('orden del visor: los 4 archivos del expediente y después el adjunto de la anotación',
            array_keys($items) === ['archivo-0', 'archivo-1', 'archivo-2', 'archivo-3', "anotacion-{$note->id}"], json_encode(array_keys($items)));

        $page = fn (string $key, ?User $as) => $this->get(CompanyDocumentResource::viewerUrl($doc, $key), $as);
        $path = fn (string $route, array $params): string => route($route, ['companyDocument' => $doc->id] + $params, false);
        $first = $page('archivo-0', $admin);
        $html = (string) $first->getContent();
        $this->check('visor del PDF: marco con la vista, «Siguiente» (no «Anterior»), «Descargar», «Volver al expediente» y «archivo 1 de 5»',
            $first->getStatusCode() === 200 && str_contains($html, '<iframe src="') && str_contains($html, $path('clarto.documentacion.archivo.vista', ['index' => 0]).'"')
            && str_contains($html, 'Siguiente') && ! str_contains($html, 'Anterior') && str_contains($html, $path('clarto.documentacion.archivo', ['index' => 0]).'?descargar=1')
            && str_contains($html, 'Volver al expediente') && str_contains($html, 'Escritura ficticia con visor · archivo 1 de 5'), 'HTTP '.$first->getStatusCode());
        $image = (string) $page('archivo-1', $admin)->getContent();
        $this->check('visor de la imagen: la imagen tal cual, con «Anterior» y «Siguiente»',
            str_contains($image, '<img src="') && str_contains($image, $path('clarto.documentacion.archivo.vista', ['index' => 1]).'"')
            && str_contains($image, 'Anterior') && str_contains($image, 'Siguiente') && str_contains($image, 'archivo 2 de 5'));

        // The frame's route: inline, the right type, framable by the panel only, logged.
        $logs = fn (): int => $doc->logs()->count();
        $before = $logs();
        $inline = $this->get($path('clarto.documentacion.archivo.vista', ['index' => 0]), $admin);
        $this->check('ruta de la vista (PDF): inline, application/pdf, X-Frame-Options SAMEORIGIN, frame-ancestors \'self\', nosniff y sin caché; registrado como visto',
            $inline->getStatusCode() === 200 && str_starts_with((string) $inline->headers->get('Content-Disposition'), 'inline')
            && $inline->headers->get('Content-Type') === 'application/pdf' && $inline->headers->get('X-Frame-Options') === 'SAMEORIGIN'
            && str_contains((string) $inline->headers->get('Content-Security-Policy'), "frame-ancestors 'self'") && $inline->headers->get('X-Content-Type-Options') === 'nosniff'
            && str_contains((string) $inline->headers->get('Cache-Control'), 'no-store') && $logs() === $before + 1 && $doc->logs()->first()?->action === 'ver',
            json_encode($inline->headers->all()));
        $img = $this->get($path('clarto.documentacion.archivo.vista', ['index' => 1]), $admin);
        $this->check('ruta de la vista (imagen): inline e image/png', $img->getStatusCode() === 200 && $img->headers->get('Content-Type') === 'image/png'
            && str_starts_with((string) $img->headers->get('Content-Disposition'), 'inline'));
        $this->check('el resto del panel sigue con X-Frame-Options DENY (la ficha)',
            $this->get(CompanyDocumentResource::getUrl('view', ['record' => $doc], panel: 'admin', tenant: $team), $admin)->headers->get('X-Frame-Options') === 'DENY');

        // Word: converted once on the server, cached on the private disk, served as PDF; the original never inline.
        $word = (string) $page('archivo-2', $admin)->getContent();
        $cached = CompanyDocumentPreview::cachePath($doc, $doc->files[2]['path']);
        $this->check('visor del Word: convertido a PDF en el servidor (sin el nombre original), guardado en el disco privado junto al expediente y mostrado en el marco',
            $calls === ['documento.docx'] && $disk->exists($cached) && str_starts_with($cached, "gestor-documental/{$team->id}/{$doc->id}/vistas/")
            && str_contains($word, '<iframe src="') && str_contains($word, $path('clarto.documentacion.archivo.vista', ['index' => 2]).'"')
            && str_contains($word, 'convertida en el propio servidor'), json_encode($calls));
        $converted = $this->get($path('clarto.documentacion.archivo.vista', ['index' => 2]), $admin);
        $this->check('ruta de la vista (Word): el PDF convertido, inline y «poder-ficticio.pdf», sin volver a convertir; registrado con el nombre original',
            $converted->getStatusCode() === 200 && $converted->headers->get('Content-Type') === 'application/pdf'
            && str_contains((string) $converted->headers->get('Content-Disposition'), 'poder-ficticio.pdf') && str_starts_with((string) $this->body($converted), '%PDF-')
            && count($calls) === 1 && $doc->logs()->first()?->file_name === 'poder-ficticio.docx', json_encode([$calls, $converted->headers->get('Content-Disposition')]));
        $original = $this->get($path('clarto.documentacion.archivo', ['index' => 2]), $admin);
        $this->check('el Word original sigue sirviéndose solo como descarga', str_starts_with((string) $original->headers->get('Content-Disposition'), 'attachment'));

        // Excel whose conversion fails: «Vista previa no disponible» and the download; the frame route 404.
        $sheet = (string) $page('archivo-3', $admin)->getContent();
        $this->check('lo que no se puede convertir: «Vista previa no disponible», el nombre y el botón de descarga; sin marco',
            str_contains($sheet, 'Vista previa no disponible') && str_contains($sheet, 'cuentas-ficticias.xlsx') && ! str_contains($sheet, '<iframe')
            && str_contains($sheet, $path('clarto.documentacion.archivo', ['index' => 3]).'?descargar=1'));
        $this->check('…y su ruta de vista da 404', $this->get($path('clarto.documentacion.archivo.vista', ['index' => 3]), $admin)->getStatusCode() === 404);

        // The annotation's attachment: last in the viewer, «Anterior» goes to the last file.
        $att = (string) $page("anotacion-{$note->id}", $admin)->getContent();
        $annotationFrame = $this->get($path('clarto.documentacion.anotacion.vista', ['annotation' => $note->id]), $admin);
        $this->check('adjunto de la anotación: en el visor (archivo 5 de 5, «Anterior» al Excel, sin «Siguiente») y su vista inline',
            str_contains($att, 'archivo 5 de 5') && str_contains($att, (string) parse_url(CompanyDocumentResource::viewerUrl($doc, 'archivo-3'), PHP_URL_PATH))
            && ! str_contains($att, 'Siguiente') && $annotationFrame->getStatusCode() === 200
            && str_starts_with((string) $annotationFrame->headers->get('Content-Disposition'), 'inline') && $annotationFrame->headers->get('X-Frame-Options') === 'SAMEORIGIN');
        $this->check('un archivo que no existe en el expediente: 404', $page('archivo-9', $admin)->getStatusCode() === 404 && $page('anotacion-999999', $admin)->getStatusCode() === 404);

        // «Ver» on the record for every file, the annotation's attachment included.
        $record = (string) $this->get(CompanyDocumentResource::getUrl('view', ['record' => $doc], panel: 'admin', tenant: $team), $admin)->getContent();
        $this->check('ficha: «Ver» lleva al visor en cada archivo y en el adjunto de la anotación',
            collect(array_keys($items))->every(fn (string $key): bool => str_contains($record, (string) parse_url(CompanyDocumentResource::viewerUrl($doc, $key), PHP_URL_PATH).'"')));

        // No access: staff, another company, no session — viewer and frame route, nothing logged.
        $before = $logs();
        $this->check('personal sin acceso: el visor da 403 y la vista 404', $page('archivo-0', $staff)->getStatusCode() === 403
            && $this->get($path('clarto.documentacion.archivo.vista', ['index' => 0]), $staff)->getStatusCode() === 404);
        $this->check('administración de otra empresa: ni el visor ni la vista', $page('archivo-0', $otherAdmin)->getStatusCode() !== 200
            && $this->get($path('clarto.documentacion.archivo.vista', ['index' => 2]), $otherAdmin)->getStatusCode() === 404);
        $this->check('sin sesión: ni el visor ni la vista', $page('archivo-0', null)->getStatusCode() !== 200
            && $this->get($path('clarto.documentacion.archivo.vista', ['index' => 0]), null)->getStatusCode() !== 200 && $logs() === $before);

        // The converted copy goes with its file and with the expediente.
        Filament::setTenant($team, isQuiet: true);
        $doc = CompanyDocument::saveFor($admin, $team->id, $this->data($folder, [], ['title' => $doc->title, 'remove_files' => [2]]), $doc);
        $this->check('quitar el Word del expediente borra su copia convertida', ! $disk->exists($cached) && count((array) $doc->files) === 3);
        $second = $this->office($disk, $admin, 'otro-poder-ficticio.docx', ['word/document.xml' => '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Otro</w:t></w:r></w:p></w:body></w:document>']);
        $doc = CompanyDocument::saveFor($admin, $team->id, $this->data($folder, [$second], ['title' => $doc->title], [$second => 'otro-poder-ficticio.docx']), $doc);
        $page('archivo-3', $admin);
        $dir = "gestor-documental/{$team->id}/{$doc->id}/vistas";
        $hadCopy = count($disk->files($dir)) === 1;
        $doc->delete();
        $this->check('borrar el expediente borra sus copias convertidas', $hadCopy && $disk->allFiles($dir) === []);

        // The real LibreOffice, when the image has it: a fictitious .docx converted for real.
        app()->forgetInstance(CompanyDocumentPreview::class);
        $real = new CompanyDocumentPreview();
        if ($real->canConvert()) {
            Filament::setTenant($team, isQuiet: true);
            // A complete .docx (content types and the relationship to the main part): LibreOffice cannot open the bare one.
            $live = $this->office($disk, $admin, 'real-ficticio.docx', [
                '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>',
                '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>',
                'word/document.xml' => '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Documento ficticio para LibreOffice</w:t></w:r></w:p></w:body></w:document>',
            ]);
            $realDoc = CompanyDocument::saveFor($admin, $team->id, $this->data($folder, [$live], ['title' => 'Conversión real ficticia'], [$live => 'real-ficticio.docx']));
            $out = $real->pdfFor($realDoc, ((array) $realDoc->files)[0]);
            $this->check('LibreOffice instalado: un .docx ficticio se convierte de verdad a PDF', $out !== null && str_starts_with((string) $disk->get($out), '%PDF-'));
            $realDoc->delete();
        } else {
            $this->line('SKIP  LibreOffice no está instalado en esta imagen: sin conversión real (Word / Excel dirán «Vista previa no disponible»)');
            $this->check('sin LibreOffice: Word sin vista (pdfFor nulo) y sin error', $real->pdfFor($doc, ['path' => 'no-existe.docx', 'name' => 'x.docx', 'mime' => CompanyDocument::OFFICE_TYPES['docx']]) === null);
        }
    }

    private function body(Response $response): string
    {
        if ($response instanceof StreamedResponse) {
            ob_start();
            $response->sendContent();

            return (string) ob_get_clean();
        }

        return (string) $response->getContent();
    }

    /**
     * 18. The list in two tabs. «Carpetas» (the default): the path Inicio › Contratos › Colaboración with each step a
     * link, the open folder's subfolders and then only its own expedientes, the folder in the URL (?carpeta=, one of
     * another company → Inicio), searching and filtering in the folder and every subfolder (folders found by name too),
     * «Nueva carpeta» inside the open one, «Renombrar carpeta», «Nuevo expediente» with the folder filled in.
     * «Aprobaciones»: only what asked for approval, with its count and a badge of the pending ones. Staff, another
     * company and no session get nothing. A company of its own: owner + 1 administrator + 1 staff.
     */
    private function folderNavigation(Filesystem $disk): void
    {
        $team = $this->team('Inmobiliaria Ficticia Carpetas');
        $other = $this->team('Inmobiliaria Ficticia Ajena');
        $owner = User::query()->findOrFail($team->getAttribute('user_id'));
        $this->role($owner, $team, 'staff');
        $admin = $this->user('Irene Admin Ficticia', $team, 'admin');
        $staff = $this->user('Óscar Staff Ficticio', $team, 'staff');
        $otherAdmin = $this->user('Admin Ajena Ficticia', $other, 'admin');
        // Filament gives a new model the current tenant's company: each folder is created with its own company current.
        $mk = function (string $name, ?CompanyDocumentFolder $parent = null, ?Team $in = null) use ($team): CompanyDocumentFolder {
            Filament::setTenant($in ?? $team, isQuiet: true);

            return CompanyDocumentFolder::query()->create(['team_id' => ($in ?? $team)->id, 'name' => $name, 'parent_id' => $parent?->id]);
        };
        $foreign = $mk('Carpeta ajena ficticia', null, $other);
        $contracts = $mk('Contratos');
        $collab = $mk('Colaboración', $contracts);
        $year = $mk('2026', $collab);
        $deeds = $mk('Escrituras');
        $new = fn (CompanyDocumentFolder $folder, string $title, ?string $explanation = null): CompanyDocument => CompanyDocument::saveFor($admin, $team->id,
            $this->data($folder, [$this->pdf($disk, $admin, 'ficticio.pdf')], ['title' => $title, 'explanation' => $explanation]));
        $frame = $new($contracts, 'Contrato marco ficticio');
        $alpha = $new($collab, 'Convenio de colaboración ficticio Alfa');
        $beta = $new($year, 'Adenda ficticia Beta', 'Revisar la cláusula zafiro antes de firmar.');
        $gamma = $new($deeds, 'Escritura ficticia Gamma', 'Mención a zafiro en otra carpeta.');
        $beta->requestApproval($admin, 1);
        $this->check('la carpeta ajena es de la otra empresa', (int) $foreign->fresh()?->team_id === (int) $other->id);

        $this->check('ruta de una carpeta: de arriba abajo («Contratos › Colaboración › 2026»)',
            array_map(fn (CompanyDocumentFolder $f): string => $f->name, $year->trail()) === ['Contratos', 'Colaboración', '2026']);

        // The page, as a responsable opens it from a link: tab, path, subfolders, expedientes. Folders open with plain
        // links (…?tab=carpetas&carpeta=ID; Inicio …?tab=carpetas), so the address and «atrás» follow them.
        $link = fn (?CompanyDocumentFolder $f): string => $f === null ? '?tab=carpetas"' : '?tab=carpetas&amp;carpeta='.$f->id.'"';
        $list = CompanyDocumentResource::getUrl('index', panel: 'admin', tenant: $team);
        $page = $this->get($list.'?tab=carpetas&carpeta='.$collab->id, $admin);
        $html = (string) $page->getContent();
        $this->check('enlace a «Colaboración» (?carpeta=): pestañas «Carpetas» y «Aprobaciones», ruta «Inicio › Contratos › Colaboración» con «Inicio» y «Contratos» clicables',
            $page->getStatusCode() === 200 && str_contains($html, 'Aprobaciones') && str_contains($html, $link(null))
            && str_contains($html, $link($contracts))
            && preg_match('/aria-current="page"[^>]*>Colaboración</u', $html) === 1, 'HTTP '.$page->getStatusCode());
        $this->check('dentro de «Colaboración»: la subcarpeta «2026» (con su recuento) y solo su expediente (no el de «2026» ni el de «Contratos»)',
            str_contains($html, $link($year)) && str_contains($html, '1 expediente') && str_contains($html, e($alpha->title))
            && ! str_contains($html, e($beta->title)) && ! str_contains($html, e($frame->title)));
        $root = (string) $this->get($list, $admin)->getContent();
        $this->check('sin carpeta en el enlace: «Carpetas» por defecto, en Inicio, con las carpetas de primer nivel y sin expedientes; nada de otra empresa',
            str_contains($root, $link($contracts)) && str_contains($root, $link($deeds)) && ! str_contains($root, $link($collab))
            && str_contains($root, 'Abra una carpeta para ver sus expedientes') && ! str_contains($root, e($frame->title)) && ! str_contains($root, 'Carpeta ajena ficticia'));
        $foreignPage = (string) $this->get($list.'?carpeta='.$foreign->id, $admin)->getContent();
        $this->check('carpeta de otra empresa en el enlace: se queda en Inicio y no la muestra',
            ! str_contains($foreignPage, 'Carpeta ajena ficticia') && str_contains($foreignPage, $link($contracts)));

        // Livewire: what the table holds in each place, searching and filtering.
        Filament::setCurrentPanel('admin');
        $this->as($admin, $team);
        Filament::setTenant($team, isQuiet: true);
        $ids = function (?CompanyDocumentFolder $folder, string $search = '', array $set = []): array {
            $test = Livewire::test(ManageCompanyDocuments::class)->set('folderId', $folder === null ? null : (string) $folder->id)->set('tableSearch', $search);
            foreach ($set as $key => $value) {
                $test->set($key, $value);
            }
            $page = $test->instance();

            return $page instanceof ManageCompanyDocuments ? $page->getFilteredTableQuery()?->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all() ?? [] : [];
        };
        $sorted = fn (CompanyDocument ...$docs): array => collect($docs)->map(fn (CompanyDocument $d): int => (int) $d->id)->sort()->values()->all();
        $this->check('contenido de cada carpeta: Inicio nada; «Contratos» solo el suyo; «Colaboración» el suyo; «2026» el suyo',
            $ids(null) === [] && $ids($contracts) === $sorted($frame) && $ids($collab) === $sorted($alpha) && $ids($year) === $sorted($beta),
            json_encode([$ids(null), $ids($contracts), $ids($collab), $ids($year)]));
        $this->check('buscador recursivo: «zafiro» en «Contratos» encuentra el de «2026» (dos niveles más abajo) y no el de «Escrituras»; desde Inicio, los dos',
            $ids($contracts, 'zafiro') === $sorted($beta) && $ids(null, 'zafiro') === $sorted($beta, $gamma) && $ids($deeds, 'zafiro') === $sorted($gamma)
            && $ids($collab, 'Alfa') === $sorted($alpha) && $ids($year, 'Alfa') === [],
            json_encode([$ids($contracts, 'zafiro'), $ids(null, 'zafiro')]));
        $this->check('un filtro también mira en las subcarpetas: «Disponible» en «Contratos» da sus dos disponibles; «Pendiente», el de «2026»',
            $ids($contracts, '', ['tableFilters.status.value' => CompanyDocument::AVAILABLE]) === $sorted($frame, $alpha)
            && $ids($contracts, '', ['tableFilters.status.value' => CompanyDocument::PENDING]) === $sorted($beta));
        $searching = (string) Livewire::test(ManageCompanyDocuments::class)->set('folderId', (string) $contracts->id)->set('tableSearch', '2026')->html();
        $this->check('al buscar: «Resultados en «Inicio › Contratos» y en todas sus subcarpetas» y las carpetas que se llaman así, con su ruta',
            str_contains($searching, e('Resultados en «Inicio › Contratos» y en todas sus subcarpetas')) && str_contains($searching, 'Contratos / Colaboración / 2026')
            && str_contains($searching, $link($year)));

        // The links leave the search behind; a folder id of another company changed by hand shows nothing of it.
        $this->check('los enlaces a carpetas no arrastran la búsqueda', ! str_contains($searching, 'search=2026') && ! str_contains($searching, 'search=2026&'));
        $forced = Livewire::test(ManageCompanyDocuments::class)->set('folderId', (string) $foreign->id);
        $forcedPage = $forced->instance();
        $this->check('carpeta de otra empresa puesta a mano: no se abre (Inicio) y no lista nada suyo',
            $forcedPage instanceof ManageCompanyDocuments && $forcedPage->currentFolder() === null && ! str_contains((string) $forced->html(), 'Carpeta ajena ficticia'));

        // Header actions: «Nueva carpeta» in the open folder, «Renombrar carpeta», «Nuevo expediente» filled in.
        $inCollab = fn () => Livewire::test(ManageCompanyDocuments::class)->set('folderId', (string) $collab->id);
        $inCollab()->call('mountAction', 'newFolder')->set('mountedActions.0.data.name', '  Proveedores ficticios  ')->call('callMountedAction');
        $made = CompanyDocumentFolder::query()->where('team_id', $team->id)->where('name', 'Proveedores ficticios')->first();
        $this->check('«Nueva carpeta» dentro de «Colaboración»: creada ahí, sin espacios sobrantes', $made !== null && (int) $made->parent_id === $collab->id);
        $inCollab()->call('mountAction', 'renameFolder')->set('mountedActions.0.data.name', 'Colaboración comercial')->call('callMountedAction');
        $this->check('«Renombrar carpeta»: cambia el nombre y la ruta de sus subcarpetas',
            $collab->fresh()?->name === 'Colaboración comercial' && $year->fresh()?->path() === 'Contratos / Colaboración comercial / 2026', (string) $year->fresh()?->path());
        $create = $inCollab()->call('mountAction', 'create');
        $this->check('«Nuevo expediente» desde «Colaboración»: la carpeta ya rellenada', (string) $create->get('mountedActions.0.data.folder_id') === (string) $collab->id,
            json_encode($create->get('mountedActions.0.data.folder_id')));
        $mounted = fn ($test): int => count((array) $test->get('mountedActions'));
        $inApprovals = fn (string $action) => Livewire::test(ManageCompanyDocuments::class)->set('activeTab', ManageCompanyDocuments::TAB_APPROVALS)
            ->set('folderId', (string) $collab->id)->call('mountAction', $action);
        $this->check('en Inicio no hay «Renombrar carpeta»; en «Aprobaciones» ni «Nueva carpeta» ni «Renombrar», y «Nuevo expediente» sale sin carpeta',
            $mounted(Livewire::test(ManageCompanyDocuments::class)->call('mountAction', 'renameFolder')) === 0
            && $mounted($inApprovals('newFolder')) === 0 && $mounted($inApprovals('renameFolder')) === 0
            && $mounted($inApprovals('create')) === 1 && blank($inApprovals('create')->get('mountedActions.0.data.folder_id')));

        // «Aprobaciones»: what asked for approval, with its count and the pending badge.
        $tab = Livewire::test(ManageCompanyDocuments::class)->set('activeTab', ManageCompanyDocuments::TAB_APPROVALS);
        $tabPage = $tab->instance();
        $approvalIds = $tabPage instanceof ManageCompanyDocuments ? $tabPage->getFilteredTableQuery()?->pluck('id')->map(fn ($id): int => (int) $id)->all() : null;
        $badge = $tabPage instanceof ManageCompanyDocuments ? $tabPage->getCachedTabs()[ManageCompanyDocuments::TAB_APPROVALS]->getBadge() : null;
        $this->check('pestaña «Aprobaciones»: solo el que pidió aprobación (de cualquier carpeta), con su recuento; insignia con 1 pendiente',
            $approvalIds === [$beta->id] && (string) $badge === '1' && str_contains((string) $tab->html(), '0 a favor · 0 con condiciones · 0 en contra · 1 sin votar (necesarias: 1)'),
            json_encode([$approvalIds, $badge]));

        // No access: staff, another company, no session — page, links and folder changes.
        $this->check('personal sin acceso: la pestaña «Carpetas» con ?carpeta= da 403', $this->get($list.'?tab=carpetas&carpeta='.$collab->id, $staff)->getStatusCode() === 403);
        $this->check('administración de otra empresa: la lista de esta empresa no se sirve', $this->get($list.'?carpeta='.$collab->id, $otherAdmin)->getStatusCode() !== 200);
        $this->check('sin sesión: la lista no se sirve', $this->get($list.'?carpeta='.$collab->id, null)->getStatusCode() !== 200);
        Filament::setTenant($team, isQuiet: true);
        $this->check('personal y otra empresa: no pueden crear carpetas ni renombrarlas (y el nombre no cambia)',
            $this->throws(fn () => CompanyDocumentFolder::createFor($staff, $team->id, 'Intrusa', $collab->id))
            && $this->throws(fn () => $collab->fresh()?->rename($staff, 'Intrusa')) && $this->throws(fn () => $collab->fresh()?->rename($otherAdmin, 'Intrusa'))
            && $collab->fresh()?->name === 'Colaboración comercial' && CompanyDocumentFolder::query()->where('name', 'Intrusa')->doesntExist());
    }

    /**
     * 17. Three answers (a favor, con condiciones — conditions required, ≤ 2000 —, en contra), the count «x a favor · y
     * con condiciones · z en contra · w sin votar (necesarias: n)», the threshold notice that says there are conditions,
     * the conditions together on the record, closing as «Aprobado con condiciones» (only when there are any), the
     * automatic rejection (fixed number and majority: 5 voters, 3 needed → the 3rd vote against), its single notice to the
     * manager and every voter in their language, a new round after it, the real vote form, and the migration of the old
     * conforme / no conforme votes. A company of its own: owner (manager) + 5 administrators (one reads English).
     */
    private function votesConditionsAndRejection(Filesystem $disk): void
    {
        $team = $this->team('Inmobiliaria Ficticia Votos');
        $owner = User::query()->findOrFail($team->getAttribute('user_id'));
        $this->role($owner, $team, 'staff');
        [$elena, $pablo, $sara, $hugo, $nuria] = collect(['Elena', 'Pablo', 'Sara', 'Hugo', 'Nuria'])
            ->map(fn (string $name): User => $this->user("{$name} Votante Ficticia", $team, 'admin'))->all();
        $hugo->forceFill(['locale' => 'en'])->saveQuietly();
        Filament::setTenant($team, isQuiet: true);
        $folder = CompanyDocumentFolder::query()->create(['team_id' => $team->id, 'name' => 'Convenios']);
        $new = fn (string $title, int $required, string $rule = CompanyDocument::RULE_NUMBER): CompanyDocument => CompanyDocument::saveFor($owner, $team->id,
            $this->data($folder, [$this->pdf($disk, $owner, 'convenio.pdf')], ['title' => $title, 'requires_approval' => true, 'approval_rule' => $rule, 'approvals_required' => $required]))->fresh() ?? throw new \RuntimeException('sin expediente');
        $about = fn (User $u, array $kinds, CompanyDocument $d): Collection => $this->notices($u, $kinds)->filter(fn (array $n): bool => $n['notification']->params['title'] === $d->title)->values();
        $view = fn (CompanyDocument $d): string => CompanyDocumentResource::getUrl('view', ['record' => $d], panel: 'admin', tenant: $team);
        $fields = fn ($test): array => $test->instance()->getSchema((string) $test->instance()->getMountedActionSchemaName())?->getFlatFields() ?? [];

        // Three answers; the conditions are required and, like the comment, at most 2000 characters.
        $doc = $new('Convenio ficticio con condiciones', 3);
        $this->check('respuestas: una desconocida, «con condiciones» sin condiciones (o solo espacios) y más de 2000 caracteres se rechazan; no se guarda nada',
            $this->throws(fn () => $doc->vote($elena, 'conforme')) && $this->throws(fn () => $doc->vote($elena, CompanyDocumentVote::CONDITIONS))
            && $this->throws(fn () => $doc->vote($elena, CompanyDocumentVote::CONDITIONS, '   '))
            && $this->throws(fn () => $doc->vote($elena, CompanyDocumentVote::CONDITIONS, str_repeat('a', 2001)))
            && $this->throws(fn () => $doc->vote($elena, CompanyDocumentVote::AGAINST, str_repeat('a', 2001))) && $doc->votes()->count() === 0);
        $doc->vote($elena, CompanyDocumentVote::FAVOR, 'De acuerdo.');
        $before = $doc->closingStatuses();
        $doc->vote($pablo, CompanyDocumentVote::CONDITIONS, '  Firmar antes del 31/12.  ');
        $doc->vote($sara, CompanyDocumentVote::AGAINST);
        $stored = $doc->votes()->get()->mapWithKeys(fn (CompanyDocumentVote $v): array => [$v->user_id => [$v->decision, $v->comment]])->all();
        $this->check('tres respuestas guardadas en «decision» (las condiciones sin espacios sobrantes; «En contra» sin comentario)',
            ($stored[$elena->id] ?? null) === [CompanyDocumentVote::FAVOR, 'De acuerdo.'] && ($stored[$pablo->id] ?? null) === [CompanyDocumentVote::CONDITIONS, 'Firmar antes del 31/12.']
            && ($stored[$sara->id] ?? null) === [CompanyDocumentVote::AGAINST, null] && ! Schema::hasColumn('clarto_company_document_votes', 'approved'), json_encode($stored));
        $this->check('recuento: «1 a favor · 1 con condiciones · 1 en contra · 2 sin votar (necesarias: 3)»; las condiciones cuentan como aprobación; sin aviso al gestor',
            $doc->approvalProgress() === '1 a favor · 1 con condiciones · 1 en contra · 2 sin votar (necesarias: 3)' && $doc->approvalCount() === 2
            && $about($owner, ['threshold', 'threshold_conditions'], $doc)->isEmpty() && $doc->fresh()?->status === CompanyDocument::PENDING, (string) $doc->approvalProgress());
        $doc->vote($hugo, CompanyDocumentVote::CONDITIONS, 'Añadir el anexo de precios.');
        $threshold = $about($owner, ['threshold', 'threshold_conditions'], $doc);
        $mail = $threshold->first()['notification']->toMail($owner);
        $text = implode(' ', [$mail->subject, ...$mail->introLines]);
        $this->check('umbral con condiciones (1 a favor + 2 con condiciones = 3): un aviso al gestor que lo dice, por campana y correo, sin copiar las condiciones; sigue pendiente',
            $threshold->count() === 1 && $threshold->first()['notification']->kind === 'threshold_conditions' && $threshold->first()['channels'] === ['database', 'mail']
            && str_contains($text, '2 de ellas con condiciones') && ! str_contains($text, 'anexo de precios') && $mail->attachments === []
            && $doc->fresh()?->status === CompanyDocument::PENDING, $text);
        $this->check('«Cerrar aprobación» ofrece «Aprobado con condiciones» solo cuando hay votos con condiciones',
            $before === [CompanyDocument::APPROVED, CompanyDocument::SIGNED]
            && $doc->fresh()?->closingStatuses() === [CompanyDocument::APPROVED, CompanyDocument::APPROVED_CONDITIONS, CompanyDocument::SIGNED], json_encode($before));
        $this->page('ficha: recuento, las condiciones juntas con su autor y cada respuesta', $view($doc), $owner, [
            '1 a favor · 2 con condiciones · 1 en contra · 1 sin votar (necesarias: 3)', 'Condiciones',
            'Pablo Votante Ficticia: «Firmar antes del 31/12.»', 'Hugo Votante Ficticia: «Añadir el anexo de precios.»',
            'Elena Votante Ficticia · A favor · «De acuerdo.»', 'Pablo Votante Ficticia · A favor con condiciones · «Firmar antes del 31/12.»', 'Sara Votante Ficticia · En contra',
        ]);
        Filament::setCurrentPanel('admin');
        $this->as($owner, $team);
        Filament::setTenant($team, isQuiet: true);
        $close = Livewire::test(ViewCompanyDocument::class, ['record' => $doc->id])->call('mountAction', 'close');
        $options = ($fields($close)['status'] ?? null)?->getOptions();
        $this->check('«Cerrar aprobación» (formulario real): Aprobado, Aprobado con condiciones y Firmado',
            $options === [CompanyDocument::APPROVED => 'Aprobado', CompanyDocument::APPROVED_CONDITIONS => 'Aprobado con condiciones', CompanyDocument::SIGNED => 'Firmado'], json_encode($options));
        $close->set('mountedActions.0.data.status', CompanyDocument::APPROVED_CONDITIONS)->call('callMountedAction');
        $doc->refresh();
        $this->check('cerrado a mano como «Aprobado con condiciones»; después solo se puede firmar y no admite votos',
            $doc->status === CompanyDocument::APPROVED_CONDITIONS && $doc->closingStatuses() === [CompanyDocument::SIGNED]
            && $this->throws(fn () => $doc->changeStatus($owner, CompanyDocument::APPROVED)) && $this->throws(fn () => $doc->vote($nuria, CompanyDocumentVote::FAVOR)),
            json_encode([$doc->status, $close->errors()->toArray()]));
        $html = (string) $this->get($view($doc), $owner)->getContent();
        $badge = preg_match('/<span[^>]*class="fi-badge[^"]*"[^>]*style="([^"]*)"[^>]*>\s*Aprobado con condiciones\s*</', $html, $m) === 1 ? $m[1] : '';
        $this->check('ficha: etiqueta «Aprobado con condiciones» con su color propio (índigo, distinto del ámbar de «Pendiente» y del azul de «Aprobado»)',
            str_contains($badge, '--color-600: '.Color::Indigo[600]), $badge);
        $plain = $new('Convenio ficticio sin condiciones', 1);
        $plain->vote($elena, CompanyDocumentVote::FAVOR);
        $this->check('sin votos con condiciones: «Aprobado con condiciones» no se ofrece y el servidor lo rechaza; el aviso de umbral es el normal',
            ! in_array(CompanyDocument::APPROVED_CONDITIONS, $plain->closingStatuses(), true) && $this->throws(fn () => $plain->changeStatus($owner, CompanyDocument::APPROVED_CONDITIONS))
            && $about($owner, ['threshold'], $plain)->count() === 1 && $about($owner, ['threshold_conditions'], $plain)->isEmpty());

        // The real vote form: three options; the conditions field is required for «con condiciones».
        $form = $new('Convenio ficticio del formulario', 5);
        $this->as($nuria, $team);
        Filament::setTenant($team, isQuiet: true);
        $vote = Livewire::test(ViewCompanyDocument::class, ['record' => $form->id])->call('mountAction', 'vote');
        $radio = $fields($vote)['decision'] ?? null;
        $vote->set('mountedActions.0.data.decision', CompanyDocumentVote::CONDITIONS)->call('callMountedAction');
        $missing = $vote->errors()->has('mountedActions.0.data.comment') && $form->votes()->count() === 0;
        $label = ($fields($vote)['comment'] ?? null)?->getLabel();
        $vote->set('mountedActions.0.data.comment', 'Revisar la fianza.')->call('callMountedAction');
        $again = Livewire::test(ViewCompanyDocument::class, ['record' => $form->id])->call('mountAction', 'vote');
        $this->check('formulario «Responder»: A favor / A favor con condiciones / En contra; «Condiciones (obligatorias)» se exige; se guarda y se vuelve a cargar al cambiar el voto',
            $radio?->getOptions() === [CompanyDocumentVote::FAVOR => 'A favor', CompanyDocumentVote::CONDITIONS => 'A favor con condiciones', CompanyDocumentVote::AGAINST => 'En contra']
            && $missing && $label === 'Condiciones (obligatorias)'
            && $form->votes()->where('user_id', $nuria->id)->first()?->decision === CompanyDocumentVote::CONDITIONS
            && $again->get('mountedActions.0.data.decision') === CompanyDocumentVote::CONDITIONS && $again->get('mountedActions.0.data.comment') === 'Revisar la fianza.',
            json_encode([$radio?->getOptions(), $missing, $label, $vote->errors()->toArray()]));

        // Automatic rejection, fixed number: 4 of 5 needed → the 2nd vote against (2 > 5 − 4).
        $fixed = $new('Convenio ficticio rechazado por número', 4);
        $fixed->vote($elena, CompanyDocumentVote::AGAINST, 'No.');
        $this->check('número fijo 4 de 5: un voto en contra todavía no rechaza (1 no es más que 5 − 4)', $fixed->fresh()?->status === CompanyDocument::PENDING);
        $stale = CompanyDocument::query()->findOrFail($fixed->id);   // read before the rejection, as a second vote at the same moment would
        $fixed->vote($pablo, CompanyDocumentVote::AGAINST);
        $fixed->refresh();
        $rejected = fn (CompanyDocument $d): array => collect([$owner, $elena, $pablo, $sara, $hugo, $nuria])->map(fn (User $u): int => $about($u, ['rejected'], $d)->count())->all();
        $this->check('número fijo: el 2.º voto en contra lo pasa solo a «Rechazado»; aviso al gestor y a los 5 que votan, una vez cada uno, por campana y correo',
            $fixed->status === CompanyDocument::REJECTED && $fixed->status_changed_at !== null && $rejected($fixed) === [1, 1, 1, 1, 1, 1]
            && $about($owner, ['rejected'], $fixed)->first()['channels'] === ['database', 'mail'], json_encode([$fixed->status, $rejected($fixed)]));
        $stale->vote($sara, CompanyDocumentVote::AGAINST);
        $this->check('aviso único: un voto que llega a la vez (copia leída antes del rechazo) no repite el rechazo ni los avisos; después no se vota',
            $rejected($fixed) === [1, 1, 1, 1, 1, 1] && $stale->status === CompanyDocument::REJECTED && $this->throws(fn () => $fixed->vote($hugo, CompanyDocumentVote::FAVOR)),
            json_encode($rejected($fixed)));
        $es = $about($elena, ['rejected'], $fixed)->first()['notification'];
        $en = $about($hugo, ['rejected'], $fixed)->first()['notification'];
        $esMail = $es->toMail($elena);
        app()->setLocale($hugo->preferredLocale() ?? 'es');
        $enMail = $en->toMail($hugo);
        $enDb = $en->toDatabase($hugo);
        app()->setLocale('es');
        $this->check('aviso de rechazo en el idioma de cada destinatario (castellano / inglés), con votos en contra y necesarias, sin adjuntos',
            str_starts_with($esMail->subject, 'Expediente rechazado: Convenio ficticio rechazado por número') && str_contains(implode(' ', $esMail->introLines), 'con 2 votos en contra de 5 responsables')
            && str_starts_with($enMail->subject, 'Case file rejected: ') && str_contains(implode(' ', $enMail->introLines), 'with 2 votes against out of 5 responsables')
            && ($enDb['color'] ?? null) === 'danger' && $esMail->attachments === [] && $enMail->attachments === [] && $enMail->rawAttachments === [],
            json_encode([$esMail->subject, $enMail->subject, $enMail->introLines]));
        $this->check('un «Rechazado» no se cierra (ni aprobado ni firmado)', $fixed->closingStatuses() === [] && $this->throws(fn () => $fixed->changeStatus($owner, CompanyDocument::APPROVED)));
        $this->page('lista: «Rechazado» con su recuento', CompanyDocumentResource::getUrl('index', panel: 'admin', tenant: $team).'?tab=aprobaciones', $owner,
            ['Rechazado · 0 a favor · 0 con condiciones · 3 en contra · 2 sin votar (necesarias: 4)']);
        $html = (string) $this->get($view($fixed), $owner)->getContent();
        $this->check('ficha de un rechazado: etiqueta roja, explicación y «Pedir aprobación», sin «Responder» ni «Cerrar aprobación»',
            preg_match('/fi-color-danger[^>]*>(?:\s|<[^>]+>)*Rechazado\b/', $html) === 1 && str_contains($html, e('ya no se puede llegar a las aprobaciones necesarias'))
            && str_contains($html, 'Pedir aprobación') && ! str_contains($html, '>Responder') && ! str_contains($html, 'Cerrar aprobación'));

        // A new round after the rejection: votes cleared, pending again, everyone notified, voting works.
        $pendingBefore = $about($sara, ['pending'], $fixed)->count();
        $fixed->requestApproval($owner, 2);
        $fixed->refresh();
        $fixed->vote($sara, CompanyDocumentVote::FAVOR);
        $this->check('volver a pedir aprobación tras el rechazo: pendiente, votos anteriores borrados, aviso nuevo y se vuelve a votar',
            $fixed->status === CompanyDocument::PENDING && $fixed->votes()->count() === 1 && $fixed->threshold_notified_at === null
            && $about($sara, ['pending'], $fixed)->count() === $pendingBefore + 1 && $fixed->approvalProgress() === '1 a favor · 0 con condiciones · 0 en contra · 4 sin votar (necesarias: 2)',
            (string) $fixed->approvalProgress());

        // Automatic rejection, majority: 5 voters → 3 needed → the 3rd vote against (3 > 5 − 3).
        $majority = $new('Convenio ficticio rechazado por mayoría', 1, CompanyDocument::RULE_MAJORITY);
        $majority->vote($elena, CompanyDocumentVote::FAVOR);
        $majority->vote($pablo, CompanyDocumentVote::AGAINST);
        $majority->vote($sara, CompanyDocumentVote::AGAINST);
        $this->check('mayoría (3 de 5): con 2 en contra sigue pendiente; «1 a favor · 0 con condiciones · 2 en contra · 2 sin votar (necesarias: 3, mayoría absoluta de 5)»',
            $majority->fresh()?->status === CompanyDocument::PENDING && (int) $majority->approvals_required === 3
            && $majority->approvalProgress() === '1 a favor · 0 con condiciones · 2 en contra · 2 sin votar (necesarias: 3, mayoría absoluta de 5)', (string) $majority->approvalProgress());
        $majority->vote($hugo, CompanyDocumentVote::AGAINST);
        $this->check('mayoría: el 3.er voto en contra lo rechaza solo; aviso único al gestor y a los 5',
            $majority->fresh()?->status === CompanyDocument::REJECTED && $rejected($majority) === [1, 1, 1, 1, 1, 1], json_encode($rejected($majority)));
        $this->check('el filtro de estado incluye «Rechazado» y «Aprobado con condiciones»', (function () use ($team, $owner, $majority, $doc): bool {
            $this->as($owner, $team);
            Filament::setTenant($team, isQuiet: true);
            $ids = function (string $status): array {
                $page = Livewire::test(ManageCompanyDocuments::class)->set('tableFilters.status.value', $status)->instance();

                return $page instanceof ManageCompanyDocuments ? $page->getFilteredTableQuery()?->pluck('id')->map(fn ($id): int => (int) $id)->all() ?? [] : [];
            };

            return in_array($majority->id, $ids(CompanyDocument::REJECTED), true) && ! in_array($doc->id, $ids(CompanyDocument::REJECTED), true)
                && $ids(CompanyDocument::APPROVED_CONDITIONS) === [$doc->id];
        })());

        // Migration: down brings «approved» back (favor / condiciones → true), up converts true → favor, false → contra.
        if (DB::connection()->getDriverName() === 'sqlite') {
            $votes = fn (CompanyDocument $d): array => DB::table('clarto_company_document_votes')->where('company_document_id', $d->id)->orderBy('user_id')->pluck('decision', 'user_id')->all();
            $migration = require database_path('migrations/2026_12_15_000001_add_decision_to_clarto_company_document_votes.php');
            $migration->down();
            $old = DB::table('clarto_company_document_votes')->where('company_document_id', $doc->id)->orderBy('user_id')->pluck('approved', 'user_id')->map(fn ($v): bool => (bool) $v)->all();
            $gone = ! Schema::hasColumn('clarto_company_document_votes', 'decision');
            DB::table('clarto_company_document_votes')->insert(['company_document_id' => $plain->id, 'user_id' => $nuria->id, 'approved' => false, 'comment' => 'Voto antiguo', 'created_at' => now(), 'updated_at' => now()]);
            $migration->up();
            $this->check('migración de votos antiguos: «Conforme» → a favor, «No conforme» → en contra; «approved» desaparece (y la vuelta atrás la repone)',
                $gone && $old === [$elena->id => true, $pablo->id => true, $sara->id => false, $hugo->id => true]
                && $votes($plain) === [$elena->id => CompanyDocumentVote::FAVOR, $nuria->id => CompanyDocumentVote::AGAINST]
                && $votes($doc) === [$elena->id => 'favor', $pablo->id => 'favor', $sara->id => 'contra', $hugo->id => 'favor']
                && ! Schema::hasColumn('clarto_company_document_votes', 'approved'), json_encode([$old, $votes($plain), $votes($doc)]));
        }
    }

    /**
     * 16. Approval rule «Mayoría absoluta»: more than half of the voters (5 → 3, 4 → 3), worked out when asked and kept
     * in approvals_required; the rule saved and shown (list, record, both forms with the number it gives); the manager
     * notified once when it is reached and the expediente not moving by itself; the fixed number still checked against
     * its range; the migration (existing expedientes keep «numero»). A company of its own: owner + 5 administrators.
     */
    private function majorityRule(Filesystem $disk): void
    {
        $team = $this->team('Inmobiliaria Ficticia Mayoría');
        $owner = User::query()->findOrFail($team->getAttribute('user_id'));
        $this->role($owner, $team, 'staff');
        $admins = collect(['Elena', 'Pablo', 'Sara', 'Hugo', 'Nuria'])->map(fn (string $name): User => $this->user("{$name} Admin Ficticia", $team, 'admin'))->all();
        Filament::setTenant($team, isQuiet: true);   // the panel's tenant scope, left on by the page requests above
        $folder = CompanyDocumentFolder::query()->create(['team_id' => $team->id, 'name' => 'Acuerdos']);
        $new = fn (string $title, array $extra = []): CompanyDocument => CompanyDocument::saveFor($owner, $team->id,
            $this->data($folder, [$this->pdf($disk, $owner, 'acuerdo.pdf')], $extra + ['title' => $title]));

        $this->check('mayoría absoluta = más de la mitad de los que votan: 1→1, 2→2, 3→2, 4→3, 5→3, 6→4',
            array_map(CompanyDocument::majority(...), [1, 2, 3, 4, 5, 6]) === [1, 2, 2, 3, 3, 4]);

        // 5 voters (the owner asks; the 5 administrators vote): majority 3, whatever number came with it.
        $doc = $new('Acuerdo de socios ficticio por mayoría', ['requires_approval' => true, 'approval_rule' => CompanyDocument::RULE_MAJORITY, 'approvals_required' => 1])->fresh();
        $pending = collect($admins)->map(fn (User $u): int => $this->notices($u, ['pending'])->count())->all();
        $this->check('alta con «Mayoría absoluta» y 5 que votan: regla guardada y 3 aprobaciones necesarias (el número que llegue no cuenta)',
            $doc?->status === CompanyDocument::PENDING && $doc->approval_rule === CompanyDocument::RULE_MAJORITY && (int) $doc->approvals_required === 3
            && $doc->approvalProgress() === '0 a favor · 0 con condiciones · 0 en contra · 5 sin votar (necesarias: 3, mayoría absoluta de 5)', json_encode([$doc?->approval_rule, $doc?->approvals_required, $doc?->approvalProgress()]));
        $this->check('mayoría: aviso «pendiente» a los 5 administradores, no al titular que la pide',
            $pending === [1, 1, 1, 1, 1] && $this->notices($owner, ['pending'])->isEmpty(), json_encode($pending));

        $doc->vote($admins[0], CompanyDocumentVote::FAVOR);
        $doc->vote($admins[1], CompanyDocumentVote::FAVOR);
        $doc->vote($admins[2], CompanyDocumentVote::AGAINST);
        $this->check('mayoría: con 2 «A favor» y 1 «En contra» todavía no hay aviso al gestor; «2 a favor · 0 con condiciones · 1 en contra · 2 sin votar (necesarias: 3, mayoría absoluta de 5)»',
            $doc->approvalProgress() === '2 a favor · 0 con condiciones · 1 en contra · 2 sin votar (necesarias: 3, mayoría absoluta de 5)' && $this->notices($owner, ['threshold'])->isEmpty(), (string) $doc->approvalProgress());
        $doc->vote($admins[3], CompanyDocumentVote::FAVOR);
        $doc->vote($admins[4], CompanyDocumentVote::FAVOR);
        $threshold = $this->notices($owner, ['threshold']);
        $this->check('mayoría alcanzada (3 de 5): aviso al gestor una sola vez, por campana y correo, con «3»; el expediente sigue pendiente',
            $threshold->count() === 1 && $threshold->first()['channels'] === ['database', 'mail'] && $threshold->first()['notification']->params['required'] === 3
            && $doc->fresh()?->status === CompanyDocument::PENDING, json_encode($threshold->map(fn (array $n): array => $n['notification']->params)->all()));

        // Shown in the list and on the record.
        $this->page('lista: el progreso indica la mayoría («4 a favor · … (necesarias: 3, mayoría absoluta de 5)»)',
            CompanyDocumentResource::getUrl('index', panel: 'admin', tenant: $team).'?tab=aprobaciones', $owner, ['4 a favor · 0 con condiciones · 1 en contra · 0 sin votar (necesarias: 3, mayoría absoluta de 5)']);
        $this->page('ficha: progreso con la mayoría y estado pendiente',
            CompanyDocumentResource::getUrl('view', ['record' => $doc], panel: 'admin', tenant: $team), $owner, ['4 a favor · 0 con condiciones · 1 en contra · 0 sin votar (necesarias: 3, mayoría absoluta de 5)', 'Pendiente de aprobación']);
        $doc->changeStatus($owner, CompanyDocument::APPROVED);
        $this->check('mayoría: se cierra a mano como «Aprobado», con la regla guardada', $doc->fresh()?->status === CompanyDocument::APPROVED && $doc->fresh()->approval_rule === CompanyDocument::RULE_MAJORITY);

        // 4 voters (one administrator loses access): majority 3, from «Pedir aprobación» (the real form).
        $admins[4]->forceFill(['disabled_at' => now()])->saveQuietly();
        $plain = $new('Acta ficticia para votar');
        Filament::setCurrentPanel('admin');
        $this->as($owner, $team);
        Filament::setTenant($team, isQuiet: true);
        $fields = fn ($test): array => $test->instance()->getSchema((string) $test->instance()->getMountedActionSchemaName())?->getFlatFields() ?? [];
        $request = Livewire::test(ViewCompanyDocument::class, ['record' => $plain->id])->call('mountAction', 'request');
        $byNumber = $fields($request);
        $request->set('mountedActions.0.data.approval_rule', CompanyDocument::RULE_NUMBER)->set('mountedActions.0.data.approvals_required', 5)->call('callMountedAction');
        $outOfRange = $request->errors()->has('mountedActions.0.data.approvals_required') && $plain->fresh()?->status === CompanyDocument::AVAILABLE;
        $request->set('mountedActions.0.data.approval_rule', CompanyDocument::RULE_MAJORITY);
        $byMajority = $fields($request);
        $request->call('callMountedAction');
        $plain->refresh();
        $this->check('«Pedir aprobación»: selector «Regla» (número por defecto) con «Mayoría absoluta: 3 de 4»; el número sigue limitado a 1-4',
            isset($byNumber['approval_rule'], $byNumber['approvals_required']) && $byNumber['approval_rule']->getLabel() === 'Regla'
            && $byNumber['approval_rule']->getOptions() === [CompanyDocument::RULE_NUMBER => 'Número de aprobaciones', CompanyDocument::RULE_MAJORITY => 'Mayoría absoluta']
            && str_starts_with((string) ($byNumber['approval_rule']->getDescriptions()[CompanyDocument::RULE_MAJORITY] ?? ''), 'Mayoría absoluta: 3 de 4 ')
            && $outOfRange, json_encode(['fields' => array_keys($byNumber), 'descriptions' => isset($byNumber['approval_rule']) ? $byNumber['approval_rule']->getDescriptions() : null, 'outOfRange' => $outOfRange]));
        $this->check('«Pedir aprobación» con «Mayoría absoluta» y 4 que votan: sin campo de número; queda pendiente con 3 y la regla guardada',
            ! isset($byMajority['approvals_required']) && isset($byMajority['approval_rule']) && $plain->status === CompanyDocument::PENDING
            && $plain->approval_rule === CompanyDocument::RULE_MAJORITY && (int) $plain->approvals_required === 3
            && $plain->approvalProgress() === '0 a favor · 0 con condiciones · 0 en contra · 4 sin votar (necesarias: 3, mayoría absoluta de 4)', json_encode(['fields' => array_keys($byMajority), 'errors' => $request->errors()->toArray(), 'progress' => $plain->approvalProgress()]));

        // The new expediente form: the rule appears with «¿Requiere aprobación?» and the number only for «Número».
        $create = Livewire::test(ManageCompanyDocuments::class)->call('mountAction', 'create')->set('mountedActions.0.data.requires_approval', true);
        $createNumber = $fields($create);
        $create->set('mountedActions.0.data.approval_rule', CompanyDocument::RULE_MAJORITY);
        $createMajority = $fields($create);
        $this->check('formulario de alta: con «Sí», «Regla» y «Aprobaciones necesarias»; con «Mayoría absoluta» desaparece el número y se ve «3 de 4»',
            isset($createNumber['approval_rule'], $createNumber['approvals_required']) && isset($createMajority['approval_rule']) && ! isset($createMajority['approvals_required'])
            && str_starts_with((string) ($createMajority['approval_rule']->getDescriptions()[CompanyDocument::RULE_MAJORITY] ?? ''), 'Mayoría absoluta: 3 de 4 '),
            json_encode([array_keys($createNumber), array_keys($createMajority)]));

        // The fixed number: same range as before (server), and a rule that does not exist is refused.
        $fixed = $new('Contrato ficticio por número');
        $this->check('número fijo: 0 y 5 con 4 que votan se rechazan; una regla desconocida también; 4 se acepta («… 4 sin votar (necesarias: 4)»)',
            $this->throws(fn () => $fixed->requestApproval($owner, 0)) && $this->throws(fn () => $fixed->requestApproval($owner, 5, CompanyDocument::RULE_NUMBER))
            && $this->throws(fn () => $fixed->requestApproval($owner, 2, 'unanimidad'))
            && $this->throws(fn () => $new('Regla inventada', ['requires_approval' => true, 'approval_rule' => 'unanimidad', 'approvals_required' => 2]))
            && $fixed->fresh()?->status === CompanyDocument::AVAILABLE
            && rescue(function () use ($fixed, $owner): bool {
                $fixed->requestApproval($owner, 4);

                return true;
            }, false, false)
            && $fixed->fresh()?->approval_rule === CompanyDocument::RULE_NUMBER && $fixed->fresh()->approvalProgress() === '0 a favor · 0 con condiciones · 0 en contra · 4 sin votar (necesarias: 4)');
        $this->check('sin regla (como los formularios antiguos): número fijo', $new('Sin regla ficticia', ['requires_approval' => true, 'approvals_required' => 2])->fresh()?->approval_rule === CompanyDocument::RULE_NUMBER);
        $admins[4]->forceFill(['disabled_at' => null])->saveQuietly();

        // Migration: the column, down + up for real inside the rolled-back transaction (SQLite only): every existing expediente is «numero».
        if (DB::connection()->getDriverName() === 'sqlite') {
            $migration = require database_path('migrations/2026_12_14_000001_add_approval_rule_to_clarto_company_documents.php');
            $migration->down();
            $gone = ! Schema::hasColumn('clarto_company_documents', 'approval_rule');
            $migration->up();
            $this->check('migración: «approval_rule» se quita y se vuelve a poner; los expedientes existentes quedan con «numero»',
                $gone && DB::table('clarto_company_documents')->where('approval_rule', '!=', CompanyDocument::RULE_NUMBER)->doesntExist()
                && DB::table('clarto_company_documents')->where('approval_rule', CompanyDocument::RULE_NUMBER)->exists());
        }
    }

    /**
     * 15. Word / Excel / OpenDocument (real files built here with ZipArchive; a fake one and one with macros refused),
     * always served as a download (PDF still inline), and the expediente's «Explicación» (saved, on top of the record
     * with its line breaks, found by the list's search, in both forms, its migration).
     */
    private function officeAndExplanation(Filesystem $disk, Team $teamA, CompanyDocumentFolder $folder, User $adminA, User $ownerA, User $staffA): void
    {
        $docxType = CompanyDocument::OFFICE_TYPES['docx'];
        $xlsxType = CompanyDocument::OFFICE_TYPES['xlsx'];
        $docx = $this->office($disk, $adminA, 'contrato-ficticio.docx', ['word/document.xml' => '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Contrato ficticio</w:t></w:r></w:p></w:body></w:document>']);
        $xlsx = $this->office($disk, $adminA, 'cuentas-ficticias.xlsx', ['xl/workbook.xml' => '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheets><sheet name="Hoja1" sheetId="1"/></sheets></workbook>']);
        $odt = $this->office($disk, $adminA, 'acta-ficticia.odt', ['content.xml' => '<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"/>'], CompanyDocument::OFFICE_TYPES['odt']);
        $explanation = "  Contrato de prestación de servicios con un proveedor ficticio.\nSe sube para que lo revise la gerencia antes de renovarlo.  ";
        $pdf = $this->pdf($disk, $adminA, 'anexo-ficticio.pdf');
        $doc = CompanyDocument::saveFor($adminA, $teamA->id, CompanyDocumentResource::fromForm($this->data($folder, [], [
            'title' => 'Contrato ficticio de proveedor en Word', 'explanation' => $explanation,
            'new_files' => ['u1' => $docx, 'u2' => $xlsx, 'u3' => $odt, 'u4' => $pdf],
            'new_file_names' => [$docx => 'contrato-ficticio.docx', $xlsx => 'cuentas-ficticias.xlsx', $odt => 'acta-ficticia.odt', $pdf => 'anexo-ficticio.pdf'],
        ])));
        $files = (array) $doc->files;
        $this->check('Word (.docx), Excel (.xlsx) y OpenDocument (.odt) reales: aceptados, tipo por contenido, junto al expediente en el disco privado',
            array_column($files, 'mime') === [$docxType, $xlsxType, CompanyDocument::OFFICE_TYPES['odt'], 'application/pdf']
            && array_column($files, 'name') === ['contrato-ficticio.docx', 'cuentas-ficticias.xlsx', 'acta-ficticia.odt', 'anexo-ficticio.pdf']
            && collect($files)->every(fn (array $f): bool => str_starts_with($f['path'], "gestor-documental/{$teamA->id}/{$doc->id}/") && $disk->exists($f['path']))
            && ! $disk->exists($docx) && ! $disk->exists($xlsx), json_encode($files));
        $magic = (string) (new \finfo(FILEINFO_MIME_TYPE))->file(Storage::disk(CompanyDocument::DISK)->path($files[0]['path']));
        $this->check('el .docx se acepta aunque libmagic lo vea como zip (se mira el contenido del zip)', in_array($magic, ['application/zip', $docxType], true), $magic);

        $count = CompanyDocument::query()->count();
        $fakes = [
            'texto plano con extensión .docx' => $this->upload($disk, $adminA, 'falso.docx', "Esto no es un documento de Word, solo texto.\n"),
            'zip cualquiera con extensión .docx' => $this->office($disk, $adminA, 'zip.docx', ['notas.txt' => 'nada'], null, false),
            '.docx con macros (vbaProject.bin)' => $this->office($disk, $adminA, 'macros.docx', ['word/document.xml' => '<w:document/>', 'word/vbaProject.bin' => 'VBA']),
            '.xlsx sin [Content_Types].xml' => $this->office($disk, $adminA, 'roto.xlsx', ['xl/workbook.xml' => '<workbook/>'], null, false),
        ];
        foreach ($fakes as $label => $path) {
            $this->check("rechazado y borrado: {$label}",
                $this->throws(fn () => CompanyDocument::saveFor($adminA, $teamA->id, $this->data($folder, [$path]))) && ! $disk->exists($path) && CompanyDocument::query()->count() === $count);
        }
        $note = $doc->annotate($ownerA, 'Adjunto la versión revisada.', $this->office($disk, $ownerA, 'revision.docx', ['word/document.xml' => '<w:document/>']), 'revision.docx');
        $this->check('anotación: adjunto Word aceptado; uno falso rechazado',
            $note->file !== null && $note->file['mime'] === $docxType
            && $this->throws(fn () => $doc->annotate($ownerA, 'Falso', $this->upload($disk, $ownerA, 'falso2.docx', 'texto'), 'falso2.docx')));

        // Serving: Word / Excel always as a download (even through «Ver»), PDF still inline; every access logged.
        $url = fn (int $i): string => route('clarto.documentacion.archivo', ['companyDocument' => $doc->id, 'index' => $i], false);
        $logs = $doc->logs()->count();
        $word = $this->get($url(0), $adminA);
        $sheet = $this->get($url(1).'?descargar=1', $ownerA);
        $inline = $this->get($url(3), $adminA);
        $attached = $this->get(route('clarto.documentacion.anotacion', ['companyDocument' => $doc->id, 'annotation' => $note->id], false), $adminA);
        $disposition = fn (Response $r): string => (string) $r->headers->get('Content-Disposition');
        $this->check('Word servido siempre como descarga (attachment), con su tipo, nosniff y sin caché, aunque se pida «Ver»',
            $word->getStatusCode() === 200 && str_starts_with($disposition($word), 'attachment') && str_contains($disposition($word), 'contrato-ficticio.docx')
            && $word->headers->get('Content-Type') === $docxType && $word->headers->get('X-Content-Type-Options') === 'nosniff'
            && str_contains((string) $word->headers->get('Cache-Control'), 'no-store'), $disposition($word));
        $this->check('Excel descargado (attachment) y adjunto Word de la anotación también como descarga',
            $sheet->getStatusCode() === 200 && str_starts_with($disposition($sheet), 'attachment') && str_starts_with($disposition($attached), 'attachment'));
        $this->check('el PDF se sigue viendo en el navegador (inline)', $inline->getStatusCode() === 200 && str_starts_with($disposition($inline), 'inline'));
        $log = $doc->logs()->limit(4)->pluck('action', 'file_name')->all();
        $this->check('registro de accesos: los Word y Excel cuentan como «descargado», el PDF como «visto»',
            $doc->logs()->count() === $logs + 4 && ($log['contrato-ficticio.docx'] ?? null) === 'descargar' && ($log['cuentas-ficticias.xlsx'] ?? null) === 'descargar'
            && ($log['revision.docx'] ?? null) === 'descargar' && ($log['anexo-ficticio.pdf'] ?? null) === 'ver', json_encode($log));
        $this->check('personal sin acceso: el Word da 404 y no se registra', $this->get($url(0), $staffA)->getStatusCode() === 404 && $doc->logs()->count() === $logs + 4);

        // Explanation: saved trimmed with its line breaks; too long refused; on top of the record; found by the search.
        $this->check('explicación guardada (sin espacios sobrantes, con su salto de línea)',
            $doc->fresh()?->explanation === "Contrato de prestación de servicios con un proveedor ficticio.\nSe sube para que lo revise la gerencia antes de renovarlo.");
        $this->check('explicación de más de 10.000 caracteres: rechazada y no se guarda nada',
            $this->throws(fn () => CompanyDocument::saveFor($adminA, $teamA->id, $this->data($folder, [$this->pdf($disk, $adminA, 'largo.pdf')], ['explanation' => str_repeat('a', CompanyDocument::MAX_EXPLANATION + 1)])))
            && CompanyDocument::query()->count() === $count);
        $html = (string) $this->get(CompanyDocumentResource::getUrl('view', ['record' => $doc], panel: 'admin', tenant: $teamA), $adminA)->getContent();
        $box = '<div style="white-space:pre-line">'.e((string) $doc->fresh()?->explanation).'</div>';
        $at = fn (string $s): int => strpos($html, $s) === false ? PHP_INT_MAX : (int) strpos($html, $s);
        $this->check('ficha: «Explicación» arriba (antes de los archivos y de las anotaciones), con los saltos de línea',
            str_contains($html, $box) && str_contains($html, 'Explicación') && $at($box) < $at($url(3)) && $at($box) < $at('Anotaciones'));
        $viewer = fn (int $i): string => (string) parse_url(CompanyDocumentResource::viewerUrl($doc, "archivo-{$i}"), PHP_URL_PATH);
        $this->check('ficha: el Word y el PDF tienen «Ver» (el visor) y «Descargar»; el Word nunca se enlaza para abrirse tal cual',
            str_contains($html, $url(0).'?descargar') && ! str_contains($html, $url(0).'"')   // the sanitiser writes «=» as «&#61;»
            && str_contains($html, $viewer(0).'"') && str_contains($html, $viewer(3).'"') && str_contains($html, $url(3).'?descargar'),
            preg_match_all('/href="[^"]*documentacion[^"]*"/', $html, $m) ? implode(' ', $m[0]) : 'sin enlaces');
        $plain = CompanyDocument::saveFor($adminA, $teamA->id, $this->data($folder, [$this->pdf($disk, $adminA, 'sin.pdf')], ['title' => 'Expediente ficticio sin explicación']));
        $this->check('sin explicación: la ficha no muestra el apartado',
            $plain->explanation === null && ! str_contains((string) $this->get(CompanyDocumentResource::getUrl('view', ['record' => $plain], panel: 'admin', tenant: $teamA), $adminA)->getContent(), 'Explicación'));
        Filament::setCurrentPanel('admin');
        $this->as($adminA, $teamA);
        Filament::setTenant($teamA, isQuiet: true);
        $found = function (string $search): array {
            $page = Livewire::test(ManageCompanyDocuments::class)->set('tableSearch', $search)->instance();

            return $page instanceof ManageCompanyDocuments ? $page->getFilteredTableQuery()?->pluck('id')->map(fn ($id): int => (int) $id)->all() ?? [] : [];
        };
        $this->check('buscador de la lista: encuentra el expediente por una palabra de la explicación (y no el que no la tiene)',
            $found('gerencia antes de renovarlo') === [$doc->id] && in_array($plain->id, $found('sin explicación'), true), json_encode($found('gerencia antes de renovarlo')));

        // The forms: «Explicación» in «Nuevo expediente» and in Editar (filled), max 10 000; editing it keeps the files.
        $fields = fn ($test): array => $test->instance()->getSchema((string) $test->instance()->getMountedActionSchemaName())?->getFlatFields() ?? [];
        $create = $fields(Livewire::test(ManageCompanyDocuments::class)->call('mountAction', 'create'));
        $edit = Livewire::test(ViewCompanyDocument::class, ['record' => $doc->id])->call('mountAction', 'edit');
        $this->check('formularios de alta y edición: campo «Explicación» (hasta 10.000 caracteres); en edición, con el texto guardado',
            isset($create['explanation'], $fields($edit)['explanation']) && $create['explanation']->getLabel() === 'Explicación'
            && $create['explanation']->getMaxLength() === CompanyDocument::MAX_EXPLANATION && $edit->get('mountedActions.0.data.explanation') === $doc->fresh()?->explanation,
            json_encode(array_keys($create)));
        $edit->set('mountedActions.0.data.explanation', "Renovado por un año.\nSin cambios de precio.")->call('callMountedAction');
        $edited = $doc->fresh();
        $this->check('editar la explicación desde la ficha: se guarda y los cuatro archivos siguen',
            $edited?->explanation === "Renovado por un año.\nSin cambios de precio." && count((array) $edited->files) === 4, json_encode($edit->errors()->toArray()));

        // The real form (Livewire): one multiple upload when creating; existing files as rows + the upload when editing.
        $this->check('formulario de alta: un solo campo de subida múltiple «Archivos» (obligatorio), sin filas ni descripción al subir',
            isset($create['new_files']) && $create['new_files']->isMultiple() && $create['new_files']->isRequired() && $create['new_files']->getLabel() === 'Archivos'
            && ! isset($create['items']) && ! isset($create['description']), json_encode(array_keys($create)));
        $editFields = $fields(Livewire::test(ViewCompanyDocument::class, ['record' => $doc->id])->call('mountAction', 'edit'));
        $this->check('formulario de edición: la lista de archivos existentes (descripción opcional) y debajo «Añadir archivos» múltiple, no obligatorio',
            isset($editFields['items'], $editFields['new_files']) && $editFields['new_files']->isMultiple() && ! $editFields['new_files']->isRequired()
            && $editFields['new_files']->getLabel() === 'Añadir archivos', json_encode(array_keys($editFields)));
        $pdfBytes = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
        $new = Livewire::test(ManageCompanyDocuments::class)->call('mountAction', 'create')
            ->set('mountedActions.0.data.title', 'Expediente ficticio subido de golpe')
            ->set('mountedActions.0.data.folder_id', $folder->id)
            ->set('mountedActions.0.data.document_date', today()->toDateString())
            ->set('mountedActions.0.data.new_files', [
                UploadedFile::fake()->createWithContent('Contrato marco ficticio.pdf', $pdfBytes),
                UploadedFile::fake()->createWithContent('Anexo económico (ficticio).pdf', $pdfBytes),
                UploadedFile::fake()->createWithContent('Plano ficticio.png', (string) base64_decode(self::PNG)),
            ])
            ->call('callMountedAction');
        $batch = CompanyDocument::query()->where('title', 'Expediente ficticio subido de golpe')->first();
        $this->check('formulario real: alta con 3 archivos de golpe, guardados con su nombre original y comprobados por contenido',
            $batch !== null && collect((array) $batch->files)->pluck('name')->sort()->values()->all() === ['Anexo económico (ficticio).pdf', 'Contrato marco ficticio.pdf', 'Plano ficticio.png']
            && collect((array) $batch->files)->pluck('mime')->sort()->values()->all() === ['application/pdf', 'application/pdf', 'image/png'],
            json_encode([$batch?->files, $new->errors()->toArray()]));
        if ($batch !== null) {
            $editing = Livewire::test(ViewCompanyDocument::class, ['record' => $batch->id])->call('mountAction', 'edit');
            $rows = (array) $editing->get('mountedActions.0.data.items');
            $gone = array_shift($rows);
            $goneFile = collect((array) $batch->files)->firstWhere('name', $gone['name'] ?? null);
            $editing->set('mountedActions.0.data.items', $rows)->call('callMountedAction');
            $this->check('formulario real: al editar, quitar un archivo borrando su fila (se borra del disco; los otros dos siguen)',
                count((array) $batch->fresh()?->files) === 2 && $goneFile !== null && ! $disk->exists($goneFile['path'])
                && ! in_array($gone['name'] ?? null, collect((array) $batch->fresh()?->files)->pluck('name')->all(), true), json_encode([$rows, $editing->errors()->toArray()]));
        }

        // The upload field: types and extensions for the browser, extension → type map for FilePond.
        $field = CompanyDocumentResource::uploadField('x');
        $this->check('campo de subida: admite los tipos y extensiones de Word, Excel y OpenDocument (y el mapa de extensiones de FilePond)',
            collect([$docxType, $xlsxType, 'application/msword', 'application/vnd.ms-excel', '.docx', '.xlsx', '.doc', '.xls', '.odt', '.ods', 'application/pdf'])
                ->every(fn (string $t): bool => in_array($t, (array) $field->getAcceptedFileTypes(), true))
            && $field->getMimeTypeMap() === CompanyDocument::OFFICE_TYPES && str_contains(CompanyDocumentResource::uploadHelp(), 'Word (.doc, .docx)'));

        // Migration: the column, down + up for real inside the rolled-back transaction (SQLite only, like the notes one).
        if (DB::connection()->getDriverName() === 'sqlite') {
            $migration = require database_path('migrations/2026_12_13_000001_add_explanation_to_clarto_company_documents.php');
            $migration->down();
            $gone = ! Schema::hasColumn('clarto_company_documents', 'explanation');
            $migration->up();
            $this->check('migración: «explanation» (texto, opcional) se quita y se vuelve a poner',
                $gone && in_array('explanation', Schema::getColumnListing('clarto_company_documents'), true));
        }
    }

    /**
     * A real Office / OpenDocument file built with ZipArchive in the user's upload directory: OOXML gets its
     * «[Content_Types].xml» first (unless $ooxml is false), OpenDocument its stored «mimetype» entry.
     *
     * @param  array<string, string>  $entries
     */
    private function office(Filesystem $disk, User $user, string $name, array $entries, ?string $odfType = null, bool $ooxml = true): string
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'clarto-office-');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        if ($odfType !== null) {
            $zip->addFromString('mimetype', $odfType);
            $zip->setCompressionName('mimetype', \ZipArchive::CM_STORE);
        } elseif ($ooxml) {
            $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
            $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>');
        }
        foreach ($entries as $entry => $content) {
            $zip->addFromString($entry, $content);
        }
        $zip->close();
        $path = $this->upload($disk, $user, $name, (string) file_get_contents($tmp));
        @unlink($tmp);

        return $path;
    }

    /**
     * 14. Approval decided at upload: «Disponible» by default and without notices; «¿Requiere aprobación?» puts it
     * pending with whoever uploads it as manager; the number checked (form and server); nobody else to vote; the edit
     * form without the question; «Pedir aprobación» from «Disponible»; list, filter and colours; the null → disponible migration.
     */
    private function approvalAtUpload(Filesystem $disk, Team $teamA, Team $teamB, CompanyDocumentFolder $folder, User $adminA, User $admin2A, User $superA, User $ownerA, User $staffA, User $adminB): void
    {
        $everyone = [$adminA, $admin2A, $superA, $ownerA, $staffA];
        $sent = fn (): array => array_map(fn (User $u): int => $this->notices($u, ['pending'])->count(), $everyone);
        $form = function (string $title, array $extra, ?string $upload = null) use ($folder, $disk, $adminA): array {
            $upload ??= $this->pdf($disk, $adminA, 'fiscal.pdf');

            return CompanyDocumentResource::fromForm($this->data($folder, [], $extra + ['title' => $title, 'new_files' => ['u1' => $upload], 'new_file_names' => [$upload => 'fiscal.pdf']]));
        };

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
            && $asked->review_requested_at !== null && $asked->approvalProgress() === '0 a favor · 0 con condiciones · 0 en contra · 3 sin votar (necesarias: 2)');
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

        // List and status filter: six statuses, six colours.
        $labels = array_map(CompanyDocument::statusLabel(...), CompanyDocument::STATUSES);
        $this->check('estados: Disponible, Pendiente de aprobación, Aprobado, Aprobado con condiciones, Firmado y Rechazado, con colores de etiqueta distintos',
            $labels === ['Disponible', 'Pendiente de aprobación', 'Aprobado', 'Aprobado con condiciones', 'Firmado', 'Rechazado']
            && count(array_unique(array_map(fn (string $s): string => serialize(CompanyDocumentResource::statusColor($s)), CompanyDocument::STATUSES))) === 6, json_encode($labels));
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

    /** 12. Expedientes: several files at once under their original names, editing the rows, the 20-file limit, the annotation thread and its attachments. */
    private function expedientes(Filesystem $disk, Team $teamA, Team $teamB, CompanyDocumentFolder $deeds, User $adminA, User $admin2A, User $ownerA, User $staffA, User $adminB): void
    {
        // Several files at once (the form's multiple upload: new_files + new_file_names), kept under their original names.
        [$deed, $annex, $front] = [$this->pdf($disk, $adminA, 'x1.pdf'), $this->pdf($disk, $adminA, 'x2.pdf'), $this->upload($disk, $adminA, 'x3.png', (string) base64_decode(self::PNG))];
        $exp = CompanyDocument::saveFor($adminA, $teamA->id, CompanyDocumentResource::fromForm($this->data($deeds, [], ['title' => 'Expediente ficticio de constitución',
            'new_files' => ['u1' => $deed, 'u2' => $annex, 'u3' => $front],
            'new_file_names' => [$deed => 'Escritura de constitución ficticia.pdf', $annex => 'anexo-exp.pdf', $front => 'dni-anverso.png']])));
        $files = (array) $exp->files;
        $this->check('alta con 3 archivos de golpe: los tres, con su nombre original y sin descripción; la ficha muestra el nombre',
            array_column($files, 'name') === ['Escritura de constitución ficticia.pdf', 'anexo-exp.pdf', 'dni-anverso.png']
            && array_column($files, 'description') === [null, null, null]
            && array_map(CompanyDocument::fileLabel(...), $files) === ['Escritura de constitución ficticia.pdf', 'anexo-exp.pdf', 'dni-anverso.png'], json_encode($files));
        // Editing: each existing file is a row; its description is optional, deleting the row removes the file.
        $items = CompanyDocumentResource::fileItems($exp);
        $items[1]['description'] = '  Anexo I  ';
        $items[2]['description'] = 'DNI anverso';
        unset($items[0]);   // the row of the deed is deleted: the file goes
        $removed = (array) $exp->files;
        $exp = CompanyDocument::saveFor($adminA, $teamA->id, CompanyDocumentResource::fromForm($this->data($deeds, [], ['title' => $exp->title, 'items' => $items]), $exp), $exp);
        $this->check('editar: quitar un archivo borrando su fila (se borra del disco) y describir los que quedan (opcional)',
            array_map(CompanyDocument::fileLabel(...), (array) $exp->files) === ['Anexo I', 'DNI anverso'] && ! $disk->exists($removed[0]['path'])
            && ((array) $exp->files)[0]['name'] === 'anexo-exp.pdf'
            && CompanyDocument::fileLabel(['name' => 'subido-antes.pdf', 'path' => 'x', 'mime' => 'application/pdf', 'size' => 1]) === 'subido-antes.pdf', json_encode($exp->files));
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
        $eighteen = CompanyDocument::saveFor($adminA, $teamA->id, $this->data($deeds, array_map(fn (int $i): string => $this->pdf($disk, $adminA, "dieciocho-{$i}.pdf"), range(1, 18)), ['title' => 'Expediente ficticio con dieciocho']));
        $more = function (int $n) use ($disk, $adminA, $deeds, $eighteen): array {
            $paths = array_map(fn (int $i): string => $this->pdf($disk, $adminA, "mas-{$n}-{$i}.pdf"), range(1, $n));

            return CompanyDocumentResource::fromForm($this->data($deeds, [], ['title' => $eighteen->title, 'items' => CompanyDocumentResource::fileItems($eighteen),
                'new_files' => $paths, 'new_file_names' => array_combine($paths, array_map(basename(...), $paths))]), $eighteen);
        };
        $this->check('límite al editar: cuenta los que ya tiene más los nuevos (18 + 3 se rechaza; 18 + 2 = 20 sí)',
            $this->throws(fn () => CompanyDocument::saveFor($adminA, $teamA->id, $more(3), $eighteen)) && count((array) $eighteen->fresh()?->files) === 18
            && count((array) CompanyDocument::saveFor($adminA, $teamA->id, $more(2), $eighteen)->files) === 20);
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
