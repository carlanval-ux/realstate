<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Documents\ClientFiles;
use App\Documents\RecordDocument;
use App\Filament\Documents\RecordFiles;
use App\Filament\Owners\OwnerResource;
use App\Models\User;
use App\Modules\ClartoModules;
use App\Signing\SignatureDocument;
use App\Signing\SignatureRequest;
use App\Valuations\ValuationEngine;
use App\Valuations\ValuationRequest;
use App\Valuations\ValuationRun;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\RealEstate\MediaAndDocuments\Models\MediaDocument;
use Liberu\RealEstate\MediaAndDocumentsFilament\Resources\MediaDocumentResource;
use Liberu\RealEstate\Parties\Models\Party;
use Liberu\RealEstate\PartiesFilament\Resources\PartyResource;
use Liberu\RealEstate\Properties\Models\Property;
use Liberu\RealEstate\PropertiesFilament\Resources\PropertyResource;
use Throwable;

/**
 * Documents on the records, end to end with fictitious companies, users and files: upload to a client and to a
 * property (type checked on the bytes), restricted categories, aggregation of a signed document and a valuation
 * report, isolation between companies on the download route, the global list out of the menu, and the property,
 * owner and contact pages rendering. Database in a rolled-back transaction, private disk faked: nothing is kept.
 *
 *   php artisan clarto:documentos-prueba
 */
final class TestDocuments extends Command
{
    protected $signature = 'clarto:documentos-prueba';

    protected $description = 'Test documents on client and property records (uploads, permissions, aggregation, isolation); nothing is kept';

    private int $pass = 0;

    private int $fail = 0;

    public function handle(): int
    {
        $root = sys_get_temp_dir().'/clarto-documentos-prueba-'.bin2hex(random_bytes(4));
        Storage::fake(RecordDocument::DISK, ['root' => $root]);
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
        $disk = Storage::disk(RecordDocument::DISK);
        [$teamA, $teamB] = [$this->team('Inmobiliaria Ficticia A'), $this->team('Inmobiliaria Ficticia B')];
        $staffA = $this->user('Ana Prueba', $teamA, 'staff');
        $adminA = $this->user('Marta Admin Prueba', $teamA, 'admin');
        $superA = $this->user('Root Prueba', $teamA, (string) config('filament-shield.super_admin.name', 'super_admin'));
        $userB = $this->user('Otra Empresa Prueba', $teamB, 'admin');

        $sample = Property::query()->orderBy('id')->first();
        if ($sample === null) {
            $this->check('hay un inmueble en la base de datos para copiar', false);

            return;
        }
        $property = $sample->replicate();
        $property->forceFill(['team_id' => $teamA->id, 'created_by' => $adminA->id])->saveQuietly();
        $client = Party::withoutEvents(fn () => Party::query()->forceCreate([
            'team_id' => $teamA->id, 'created_by' => $adminA->id, 'type' => 'vendor', 'name' => 'Cliente Ficticio Prueba',
            'email' => 'cliente-'.bin2hex(random_bytes(4)).'@example.test', 'phone' => '+34 600 000 002',
        ]));
        $clientB = Party::withoutEvents(fn () => Party::query()->forceCreate([
            'team_id' => $teamB->id, 'created_by' => $userB->id, 'type' => 'vendor', 'name' => 'Cliente Otra Empresa', 'email' => 'b@example.test',
        ]));

        // 1. Upload to the client and to the property (the way the form leaves the file: on the private disk).
        $this->as($staffA, $teamA);
        $files = $this->component('party', $client->id);
        $contract = $files->store(['category' => 'contrato', 'file' => $this->pdf($disk, $teamA, 'contrato.pdf'), 'original_name' => 'contrato.pdf']);
        $this->check('subida al cliente: fila con party_id, PDF detectado por contenido, disco privado',
            $contract !== null && (int) $contract->party_id === $client->id && $contract->property_id === null && $contract->mime === 'application/pdf'
            && $contract->disk === 'local' && $disk->exists($contract->path) && ! Storage::disk('public')->exists($contract->path) && strlen((string) $contract->sha256) === 64);

        $fake = "documentos/{$teamA->id}/no-es-pdf.pdf";
        $disk->put($fake, "MZ\x90\x00 esto es un ejecutable, no un PDF");
        $count = RecordDocument::query()->count();
        $this->check('tipo no permitido (binario con extensión .pdf): rechazado y borrado',
            $files->store(['category' => 'contrato', 'file' => $fake]) === null && ! $disk->exists($fake) && RecordDocument::query()->count() === $count);

        $big = "documentos/{$teamA->id}/grande.pdf";
        $disk->put($big, "%PDF-1.4\n".str_repeat('0', RecordDocument::MAX_KB * 1024 + 1));
        $this->check('más de 20 MB: rechazado y borrado', $files->store(['category' => 'contrato', 'file' => $big]) === null && ! $disk->exists($big));

        $this->check('personal sin permiso de identidad: no puede archivar un DNI',
            ! array_key_exists('dni_nie', $files->categoryOptions())
            && $files->store(['category' => 'dni_nie', 'file' => $this->pdf($disk, $teamA, 'dni.pdf')]) === null);

        $this->as($adminA, $teamA);
        $dni = $this->component('party', $client->id)->store(['category' => 'dni_nie', 'file' => $this->pdf($disk, $teamA, 'dni.pdf'), 'original_name' => 'dni.pdf']);
        $this->check('administración: archiva el DNI del cliente', $dni !== null && $dni->category === 'dni_nie');

        $nota = $this->component('property', $property->id)->store(['category' => 'nota_simple', 'file' => $this->pdf($disk, $teamA, 'nota.pdf'), 'title' => 'Nota simple 2026']);
        $this->check('subida al inmueble: fila con property_id y título', $nota !== null && (int) $nota->property_id === $property->id && $nota->party_id === null && $nota->title === 'Nota simple 2026');

        ClartoModules::disable(ClartoModules::DOCUMENTOS);
        $refused = false;
        try {
            $this->component('party', $client->id)->store(['category' => 'contrato', 'file' => $this->pdf($disk, $teamA, 'x.pdf')]);
        } catch (\App\Modules\ModuleDisabled) {
            $refused = true;
        }
        ClartoModules::enable(ClartoModules::DOCUMENTOS);
        $this->check('módulo «Documentos y formularios» desactivado: no se archivan documentos nuevos', $refused);

        // 2. Restricted documents hidden without the permission.
        $listed = fn (User $u): array => RecordDocument::query()->where('party_id', $client->id)->visibleTo($u)->pluck('category')->sort()->values()->all();
        $this->as($staffA, $teamA);
        $this->check('personal sin permiso: la lista del cliente no muestra el DNI', $listed($staffA) === ['contrato']);
        $this->as($adminA, $teamA);
        $this->check('administración: ve el contrato y el DNI', $listed($adminA) === ['contrato', 'dni_nie']);
        $managerA = $this->user('Gestor Alquileres Prueba', $teamA, 'gestor-prueba');
        $roleId = DB::table('model_has_roles')->where('model_id', $managerA->id)->value('role_id');
        DB::table('role_has_permissions')->insert(['role_id' => $roleId, 'permission_id' => DB::table('permissions')->where('name', \App\Rentals\RentalAccess::IDENTITY)->value('id')]);
        $this->as($managerA, $teamA);
        $this->check('personal con el permiso «alquileres.identidad»: ve y descarga el DNI',
            $listed($managerA) === ['contrato', 'dni_nie'] && $this->ok($this->get($dni->downloadUrl(), $managerA), 'application/pdf'));

        // 3. Downloads: team + permission; another company gets a 404.
        $this->check('personal del equipo descarga el contrato (adjunto, nosniff, sin caché)', $this->ok($this->get($contract->downloadUrl(), $staffA), 'application/pdf'));
        $this->check('personal sin permiso: el DNI no se descarga (404)', $this->get($dni->downloadUrl(), $staffA)->getStatusCode() === 404);
        $this->check('administración descarga el DNI', $this->ok($this->get($dni->downloadUrl(), $adminA), 'application/pdf'));
        $this->check('otra empresa (aunque sea administradora): 404 en contrato, DNI y nota simple',
            collect([$contract, $dni, $nota])->every(fn (RecordDocument $d): bool => $this->get($d->downloadUrl(), $userB)->getStatusCode() === 404));
        $this->check('sin sesión: no se sirve', $this->get($contract->downloadUrl(), null)->getStatusCode() !== 200);
        $this->as($userB, $teamB);
        $this->check('otra empresa no puede abrir la lista de documentos de este cliente ni de este inmueble',
            $this->throws(fn () => $this->component('party', $client->id)) && $this->throws(fn () => $this->component('property', $property->id)));

        // 4. Aggregation: a signed document and a valuation report of this client.
        $this->as($superA, $teamA);   // the signatures screen is for super administrators (FormTemplateResource::staff)
        $signed = SignatureRequest::query()->forceCreate([
            'team_id' => $teamA->id, 'title' => 'Hoja de encargo (prueba)', 'party_id' => $client->id, 'property_id' => $property->id,
            'driver' => 'simulado', 'status' => 'completada', 'sent_at' => now()->subDay(), 'completed_at' => now(),
        ]);
        $disk->put("firma/{$signed->id}/firmado.pdf", "%PDF-1.4\n% firmado\n%%EOF");
        $signedDoc = SignatureDocument::query()->forceCreate(['signature_request_id' => $signed->id, 'kind' => 'firmado', 'name' => 'firmado.pdf',
            'path' => "firma/{$signed->id}/firmado.pdf", 'sha256' => str_repeat('a', 64), 'size' => 20]);
        $valuation = ValuationRequest::query()->forceCreate([
            'request_id' => (string) Str::uuid(), 'reference' => 'VAL-PRUEBA-'.random_int(1000, 9999), 'team_id' => $teamA->id, 'status' => 'estimada',
            'property' => ['direccion' => 'Calle Ficticia 1'], 'contact' => ['nombre' => 'Cliente Ficticio', 'email' => $client->email],
        ]);
        Storage::disk(ValuationEngine::DISK)->put("valoraciones/prueba-{$valuation->id}.pdf", "%PDF-1.4\n% informe\n%%EOF");
        $run = ValuationRun::query()->forceCreate(['valuation_request_id' => $valuation->id, 'number' => 1, 'version' => 'prueba', 'status' => 'estimada', 'pdf_path' => "valoraciones/prueba-{$valuation->id}.pdf"]);
        $valuation->forceFill(['current_run_id' => $run->id])->save();

        $rows = collect(ClientFiles::generated($client->fresh(), $superA));
        $signedRow = $rows->firstWhere('type', __('documentos.generated.signed_document'));
        $valuationRow = $rows->firstWhere('type', __('documentos.generated.valuation'));
        $this->check('agregado: documento firmado con descarga', $signedRow !== null && $signedRow['title'] === 'Hoja de encargo (prueba)' && filled($signedRow['download']));
        $this->check('agregado: informe de valoración (enlazado por el e-mail del cliente) con descarga', $valuationRow !== null && str_contains($valuationRow['title'], $valuation->reference) && filled($valuationRow['download']));
        $this->check('descarga del firmado por la ruta autorizada; otra empresa 404',
            $this->ok($this->get($signedRow['download'] ?? '/', $superA), 'application/pdf') && $this->get($signedRow['download'] ?? '/', $userB)->getStatusCode() === 404);
        $this->check('descarga del informe por su ruta (panel); otra empresa no',
            $this->get($valuationRow['download'] ?? '/', $superA)->getStatusCode() === 200 && $this->get($valuationRow['download'] ?? '/', $userB)->getStatusCode() !== 200);
        $this->as($staffA, $teamA);
        $this->check('personal sin acceso a firmas ni valoraciones: no ve esas filas', collect(ClientFiles::generated($client->fresh(), $staffA))->isEmpty());
        $links = ClientFiles::linkedProperties($client);
        $this->check('inmuebles vinculados: el inmueble de la firma aparece con su relación', in_array('signature', $links[$property->id] ?? [], true));

        // 5. The global list is out of the menu: only super administrators may open it («Archivo general»).
        $this->as($adminA, $teamA);
        $this->check('administración: sin acceso a la lista global (no aparece en el menú)', ! MediaDocumentResource::canViewAny() && ! Gate::allows('viewAny', MediaDocument::class));
        $this->as($superA, $teamA);
        $this->check('superadministración: «Archivo general» disponible', MediaDocumentResource::canViewAny() && MediaDocumentResource::getPluralModelLabel() === 'Archivo general');

        // 6. The pages render with the new tabs (owner, signed document and valuation as rendered links).
        // ponytail workaround: WhatsAppLine::forTeam() gives every team session "default" (unique), so a second team crashes the client pages.
        \App\Models\WhatsAppLine::query()->forceCreate(['team_id' => $teamA->id, 'session' => 'prueba-documentos-'.$teamA->id, 'templates' => []]);
        MediaDocument::query()->forceCreate(['team_id' => $teamA->id, 'property_id' => $property->id, 'kind' => 'certificate', 'path' => 'antiguo/certificado.pdf', 'title' => 'Certificado antiguo (prueba)']);
        MediaDocument::query()->forceCreate(['team_id' => $teamA->id, 'property_id' => $property->id, 'kind' => 'video', 'path' => 'https://www.youtube.com/watch?v=prueba123', 'title' => 'Vídeo 1', 'sort_order' => 1]);
        $this->page('ficha del inmueble: «Fotos y vídeos», «Documentos», la nota simple, el enlace de vídeo y el documento anterior',
            PropertyResource::getUrl('edit', ['record' => $property], panel: 'admin', tenant: $teamA), $superA,
            ['Fotos y vídeos', 'Nota simple 2026', 'prueba123', 'Certificado antiguo (prueba)']);
        $this->page('ficha 360 del propietario: documentos propios, generados (con enlace a la firma) e inmuebles vinculados',
            OwnerResource::getUrl('view', ['record' => $client], panel: 'admin', tenant: $teamA), $superA,
            ['Documentos del cliente', 'contrato.pdf', 'Hoja de encargo (prueba)', $valuation->reference, $property->reference(), '/firmas/'.$signed->id, 'documentos/firma/'.$signedDoc->id]);
        $this->page('ficha de contacto: sección «Documentos»', PartyResource::getUrl('edit', ['record' => $client], panel: 'admin', tenant: $teamA), $superA, ['Documentos del cliente', 'contrato.pdf']);
        // 7. Saving the property form: video links in order, a non-YouTube/Vimeo link refused, photos untouched.
        \Filament\Facades\Filament::setCurrentPanel('admin');
        \Filament\Facades\Filament::setTenant($teamA, isQuiet: true);
        $this->as($superA, $teamA);
        $photos = MediaDocument::query()->where('property_id', $property->id)->where('kind', 'photo')->count();
        $form = \Livewire\Livewire::test(\Liberu\RealEstate\PropertiesFilament\Resources\PropertyResource\Pages\EditProperty::class, ['record' => $property->getKey()]);
        $form->set('data.clarto_video_links', ['a' => ['url' => 'https://vimeo.com/123456'], 'b' => ['url' => 'https://youtu.be/prueba456']])->call('save');
        $videos = MediaDocument::query()->where('property_id', $property->id)->where('kind', 'video')->orderBy('sort_order')->pluck('path')->all();
        $this->check('guardar la ficha: enlaces de vídeo en su orden (el anterior se quita), fotos intactas',
            $videos === ['https://vimeo.com/123456', 'https://youtu.be/prueba456'] && MediaDocument::query()->where('property_id', $property->id)->where('kind', 'photo')->count() === $photos,
            json_encode($videos).' '.json_encode($form->errors()->toArray()));
        $form->set('data.clarto_video_links', ['c' => ['url' => 'https://example.com/video.mp4']])->call('save');
        $this->check('enlace que no es de YouTube/Vimeo: no se guarda', $form->errors()->isNotEmpty()
            && MediaDocument::query()->where('property_id', $property->id)->where('kind', 'video')->count() === 2);
        $this->check('vídeos subidos de hasta 60 MB: la regla temporal de Livewire lo permite', in_array('max:'.(60 * 1024), (array) config('livewire.temporary_file_upload.rules'), true));
        unset($clientB);
    }

    /** @param list<string> $expected */
    private function page(string $label, string $url, User $user, array $expected): void
    {
        $response = $this->get($url, $user);
        $html = (string) $response->getContent();
        $missing = array_values(array_filter($expected, fn (string $s): bool => ! str_contains($html, $s) && ! str_contains($html, e($s))));
        $this->check($label, $response->getStatusCode() === 200 && $missing === [],
            $response->getStatusCode() !== 200 ? 'HTTP '.$response->getStatusCode().' '.substr(trim(preg_replace('/\s+/', ' ', strip_tags($html))), 0, 300) : 'falta: '.implode(' | ', $missing));
    }

    private function component(string $owner, int $id): RecordFiles
    {
        $component = new RecordFiles;
        $component->mount($owner, $id);

        return $component;
    }

    private function pdf(\Illuminate\Contracts\Filesystem\Filesystem $disk, Team $team, string $name): string
    {
        $path = "documentos/{$team->id}/".Str::random(8).'-'.$name;
        $disk->put($path, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");

        return $path;
    }

    private function as(User $user, Team $team): void
    {
        Auth::forgetGuards();
        Auth::guard('web')->setUser($user);
        setPermissionsTeamId($team->id);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function ok(\Symfony\Component\HttpFoundation\Response $response, string $type): bool
    {
        return $response->getStatusCode() === 200 && str_starts_with((string) $response->headers->get('Content-Type'), $type)
            && $response->headers->get('X-Content-Type-Options') === 'nosniff' && str_contains((string) $response->headers->get('Content-Disposition'), 'attachment');
    }

    private function throws(callable $fn): bool
    {
        try {
            $fn();
        } catch (Throwable) {
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
            'name' => $name, 'email' => 'prueba-documentos-'.bin2hex(random_bytes(5)).'@example.com', 'password' => bcrypt(bin2hex(random_bytes(16))),
            'locale' => 'es', 'email_verified_at' => now(),
        ]));
    }

    private function get(string $uri, ?User $user): \Symfony\Component\HttpFoundation\Response
    {
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
