<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Filament\Resources\ImprovementRequestResource;
use App\Models\ImprovementRequest;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Liberu\Foundation\Organizations\Models\Team;
use Throwable;

/**
 * «Incidencias y mejoras» (team forum, formerly «Solicitud de mejoras») end to end with fictitious teams and users: sending with several screenshots,
 * server-side rejection of wrong type / oversize / someone else's upload, an administrator's reply and status
 * changes with history, and isolation between companies (policy, listing and the attachment route).
 * Database in a rolled-back transaction, private disk faked in a temporary directory: nothing is kept.
 *
 *   php artisan clarto:mejoras-prueba
 */
final class TestImprovementRequests extends Command
{
    protected $signature = 'clarto:mejoras-prueba';

    protected $description = 'Test «Incidencias y mejoras» (uploads, replies with photos, notices, isolation between companies); nothing is kept';

    private int $pass = 0;

    private int $fail = 0;

    public function handle(): int
    {
        $root = sys_get_temp_dir().'/clarto-mejoras-prueba-'.bin2hex(random_bytes(4));
        Storage::fake(ImprovementRequest::DISK, ['root' => $root]);
        config(['session.driver' => 'array']);

        DB::beginTransaction();
        try {
            $this->scenarios();
        } catch (Throwable $e) {
            $this->check('sin errores inesperados: '.$e::class.': '.$e->getMessage(), false);
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
        $disk = Storage::disk(ImprovementRequest::DISK);
        [$teamA, $teamB] = [$this->team('Inmobiliaria Ficticia A'), $this->team('Inmobiliaria Ficticia B')];
        $userA = $this->user('Ana Prueba', $teamA, 'staff');
        $colleagueA = $this->user('Luis Prueba', $teamA, 'staff');
        $adminA = $this->user('Marta Admin Prueba', $teamA, 'admin');
        $userB = $this->user('Otra Empresa Prueba', $teamB, 'staff');

        $this->check('el apartado está en los paneles admin y app, con la ruta privada de adjuntos',
            in_array(ImprovementRequestResource::class, Filament::getPanel('admin')->getResources(), true)
            && in_array(ImprovementRequestResource::class, Filament::getPanel('app')->getResources(), true)
            && app('router')->getRoutes()->getByName('clarto.mejoras.adjunto') !== null);
        $this->check('«Incidencias y mejoras»: página del tema, avisos en el panel activados y nada en el panel de colaboradores',
            __('clarto.mejoras.menu', [], 'es') === 'Incidencias y mejoras' && ImprovementRequestResource::hasPage('view')
            && Filament::getPanel('admin')->hasDatabaseNotifications() && Filament::getPanel('app')->hasDatabaseNotifications()
            && ! in_array(ImprovementRequestResource::class, Filament::getPanel(\App\Collaborators\CollaboratorAccess::PANEL)->getResources(), true));

        // 1. Several screenshots, uploaded the way the form does (user's upload folder on the private disk).
        $paths = [];
        foreach (['captura-1.png', 'captura-2.jpg', 'captura-3.png'] as $i => $name) {
            $paths[] = $disk->putFileAs(ImprovementRequest::uploadDirectory($userA->id), UploadedFile::fake()->image($name, 800 + $i, 600), "tmp{$i}-{$name}");
        }
        $request = ImprovementRequest::submit($userA, $teamA->id, 'Filtro por zona en el listado', "Sería útil filtrar inmuebles por zona.\nAdjunto capturas.", $paths, array_combine($paths, ['captura-1.png', 'captura-2.jpg', 'captura-3.png']));
        $request->refresh();
        $stored = collect($request->attachments);
        $this->check('envío con 3 capturas: usuario y empresa automáticos, estado Recibida',
            $request->user_id === $userA->id && (int) $request->team_id === $teamA->id && $request->status === 'received' && $stored->count() === 3);
        $this->check('capturas en el disco privado junto a la solicitud (ya no en la carpeta de subida)',
            $stored->every(fn (array $f): bool => $disk->exists($f['path']) && str_starts_with($f['path'], "mejoras/{$teamA->id}/{$request->id}/"))
            && collect($paths)->every(fn (string $p): bool => ! $disk->exists($p))
            && $stored->pluck('mime')->all() === ['image/png', 'image/jpeg', 'image/png'] && $stored->pluck('name')->first() === 'captura-1.png');
        $this->check('historial: alta registrada con autor y fecha', $request->events()->count() === 1 && $request->events()->first()->user_id === $userA->id && $request->events()->first()->created_at !== null);

        // 2. Server-side validation, whatever the form let through.
        $count = ImprovementRequest::query()->count();
        $fakePng = $disk->putFileAs(ImprovementRequest::uploadDirectory($userA->id), UploadedFile::fake()->createWithContent('nota.png', 'esto no es una imagen'), 'nota.png');
        $this->check('tipo no válido (texto con extensión .png): rechazado, borrado y sin solicitud',
            $this->rejects(fn () => ImprovementRequest::submit($userA, $teamA->id, 'X', 'Y', [$fakePng])) && ! $disk->exists($fakePng) && ImprovementRequest::query()->count() === $count);

        $big = UploadedFile::fake()->image('grande.png', 50, 50);
        $bigPath = ImprovementRequest::uploadDirectory($userA->id).'/grande.png';
        $disk->put($bigPath, file_get_contents($big->getRealPath()).str_repeat("\0", ImprovementRequest::MAX_BYTES + 1));
        $this->check('imagen de más de 10 MB: rechazada, borrada y sin solicitud',
            $disk->mimeType($bigPath) === 'image/png' && $this->rejects(fn () => ImprovementRequest::submit($userA, $teamA->id, 'X', 'Y', [$bigPath])) && ! $disk->exists($bigPath) && ImprovementRequest::query()->count() === $count);

        $foreign = $disk->putFileAs(ImprovementRequest::uploadDirectory($colleagueA->id), UploadedFile::fake()->image('ajena.png'), 'ajena.png');
        $this->check('no se puede adjuntar un archivo subido por otra persona',
            $this->rejects(fn () => ImprovementRequest::submit($userA, $teamA->id, 'X', 'Y', [$foreign])) && $this->rejects(fn () => ImprovementRequest::submit($userA, $teamA->id, 'X', 'Y', ["mejoras/{$teamA->id}/{$request->id}/../../x.png"])));
        $this->check('no se puede enviar en nombre de otra empresa', $this->rejects(fn () => ImprovementRequest::submit($userA, $teamB->id, 'X', 'Y')));

        // 3. Administrator of the company answers and changes the status; history keeps who and when.
        $this->check('una usuaria sin rol de administración no puede responder ni cambiar el estado',
            ! Gate::forUser($userA)->allows('update', $request) && $this->denied(fn () => $request->respond($userA, 'implemented', 'Hecho')) && $request->refresh()->status === 'received');
        $request->respond($adminA, 'in_review', 'Gracias, lo estudiamos esta semana.');
        $request->respond($adminA, 'planned');
        $request->refresh();
        $events = $request->events()->get();
        $this->check('admin responde y cambia a En revisión y luego a Planificada', $request->status === 'planned' && $events->count() === 3);
        $this->check('historial con quién, cuándo, de qué estado a cuál y la respuesta',
            $events[1]->user_id === $adminA->id && $events[1]->from_status === 'received' && $events[1]->to_status === 'in_review' && $events[1]->body === 'Gracias, lo estudiamos esta semana.' && $events[1]->created_at !== null
            && $events[2]->from_status === 'in_review' && $events[2]->to_status === 'planned' && $events[2]->body === null);
        $this->check('estado no válido rechazado', $this->rejects(fn () => $request->respond($adminA, 'cerrada')));

        // 4. Who sees what: the whole company, never another company; process doubts stay private.
        $other = ImprovementRequest::submit($colleagueA, $teamA->id, 'Otra propuesta', 'De un compañero.');
        $doubt = ImprovementRequest::submit($colleagueA, $teamA->id, '[Procesos] Duda', 'Duda para el responsable del proceso.');
        DB::table('clarto_process_feedback')->insert(['team_id' => $teamA->id, 'improvement_request_id' => $doubt->id, 'source' => 'lector', 'created_at' => now()]);
        $ids = fn (User $u): array => ImprovementRequest::query()->visibleTo($u)->pluck('id')->sort()->values()->all();
        $sorted = fn (int ...$id): array => collect($id)->sort()->values()->all();
        $this->check('foro: cualquier miembro de la empresa ve y lista todos los temas de su empresa',
            $ids($userA) === $sorted($request->id, $other->id) && Gate::forUser($userA)->allows('view', $other) && Gate::forUser($userA)->allows('reply', $other));
        $this->check('una duda de Procesos sigue siendo privada (autor y administración, no el resto)',
            ! Gate::forUser($userA)->allows('view', $doubt) && Gate::forUser($colleagueA)->allows('view', $doubt) && Gate::forUser($adminA)->allows('view', $doubt)
            && in_array($doubt->id, $ids($colleagueA), true));
        $this->check('la administración de la empresa ve todas las de su empresa', $ids($adminA) === $sorted($request->id, $other->id, $doubt->id));
        $this->check('una cuenta de otra empresa no ve, no lista ni responde los temas (política y listado)',
            $ids($userB) === [] && ! Gate::forUser($userB)->allows('view', $request) && ! Gate::forUser($userB)->allows('update', $request)
            && $this->denied(fn () => $request->reply($userB, 'Hola')) && $request->events()->count() === 3);

        // 5. Forum: type, replies with photos from anyone in the company, admin-only status, in-panel notices.
        $incident = ImprovementRequest::submit($userA, $teamA->id, 'No carga el listado', 'Sale un error.', type: 'incidencia');
        $this->check('tipos: Incidencia elegida; Mejora por defecto (como los temas que ya existían); tipo inventado rechazado',
            $incident->type === 'incidencia' && $other->fresh()->type === 'mejora' && $this->rejects(fn () => ImprovementRequest::submit($userA, $teamA->id, 'X', 'Y', type: 'queja')));

        // Notices so far: the author got one per admin answer in section 3.
        $notices = fn (User $u): \Illuminate\Support\Collection => DB::table('notifications')->where('notifiable_type', $u->getMorphClass())->where('notifiable_id', $u->id)
            ->orderBy('created_at')->pluck('data')->map(fn (string $data): string => str_replace('\/', '/', $data));
        $before = $notices($userA)->count();
        \Illuminate\Support\Carbon::setTestNow(now()->addMinutes(5));
        $replyPaths = [];
        foreach (['fallo-1.png', 'fallo-2.webp'] as $i => $name) {
            $replyPaths[] = $disk->putFileAs(ImprovementRequest::uploadDirectory($colleagueA->id), $i === 0 ? UploadedFile::fake()->image($name) : UploadedFile::fake()->createWithContent($name, (string) base64_decode('UklGRhoAAABXRUJQVlA4TA0AAAAvAAAAEAcQERGIiP4HAA==')), "t{$i}-{$name}");
        }
        $reply = $other->reply($colleagueA, 'A mí también me pasa.', [], [], null);
        $photoReply = $request->reply($colleagueA, 'Os paso capturas.', $replyPaths, array_combine($replyPaths, ['fallo-1.png', 'fallo-2.webp']));
        $request->refresh();
        $this->check('un compañero (no admin) responde con texto y 2 fotos; fotos validadas y movidas junto al tema',
            $photoReply !== null && $photoReply->body === 'Os paso capturas.' && count($photoReply->attachments) === 2
            && collect($photoReply->attachments)->pluck('mime')->all() === ['image/png', 'image/webp']
            && collect($photoReply->attachments)->every(fn (array $f): bool => $disk->exists($f['path']) && str_starts_with($f['path'], "mejoras/{$teamA->id}/{$request->id}/r{$photoReply->id}-"))
            && collect($replyPaths)->every(fn (string $p): bool => ! $disk->exists($p)) && $request->status === 'planned' && $reply !== null);
        $fake = $disk->putFileAs(ImprovementRequest::uploadDirectory($colleagueA->id), UploadedFile::fake()->createWithContent('x.jpg', 'no soy una foto'), 'x.jpg');
        $this->check('respuesta con foto falsa: rechazada y sin respuesta; respuesta vacía: no se guarda',
            $this->rejects(fn () => $request->reply($colleagueA, 'Mira', [$fake])) && $request->reply($colleagueA, '  ') === null && $request->events()->count() === 4);
        $this->check('solo la administración cambia el estado (al responder o con «Responder» de Procesos)',
            $this->denied(fn () => $request->reply($colleagueA, 'Ya está', status: 'implemented')) && $this->denied(fn () => $request->respond($colleagueA, 'implemented'))
            && $request->refresh()->status === 'planned' && $request->events()->count() === 4);

        Auth::guard('web')->setUser($userA);
        $listed = ImprovementRequestResource::getEloquentQuery()->orderByDesc('updated_at')->get()->keyBy('id');
        $this->check('lista: la actividad más reciente primero y número de respuestas (sin contar altas ni cambios de estado sin texto)',
            $listed->keys()->take(2)->sort()->values()->all() === $sorted($request->id, $other->id)
            && (int) $listed[$request->id]->replies_count === 2 && (int) $listed[$other->id]->replies_count === 1 && (int) $listed[$incident->id]->replies_count === 0);
        Auth::forgetGuards();

        $this->check('aviso en el panel a la autora y a quienes participaron, no a quien responde ni a otra empresa',
            $before === 2 && $notices($userA)->count() === 3 && str_contains($notices($userA)->last(), 'Filtro por zona') && str_contains($notices($userA)->last(), 'Os paso capturas')
            && $notices($adminA)->count() === 1 && $notices($colleagueA)->isEmpty() && $notices($userB)->isEmpty());
        $request->reply($adminA, 'Implementado en la versión de hoy.', status: 'implemented');
        $this->check('cambio de estado por la administración: aviso a autora y participantes con el nuevo estado y enlace al tema',
            $request->refresh()->status === 'implemented' && $notices($userA)->count() === 4 && $notices($colleagueA)->count() === 1
            && str_contains($notices($colleagueA)->first(), 'Implementada') && str_contains($notices($colleagueA)->first(), '/solicitud-mejoras/'.$request->id)
            && $notices($userB)->isEmpty());
        \Illuminate\Support\Carbon::setTestNow();

        // 5. Attachments only through the authorised route.
        $url = route('clarto.mejoras.adjunto', ['improvementRequest' => $request->id, 'index' => 0], false);
        $asAuthor = $this->get($url, $userA);
        $this->check('la autora descarga su captura por la ruta autorizada (image/png, nosniff)',
            $asAuthor->getStatusCode() === 200 && $asAuthor->headers->get('Content-Type') === 'image/png' && $asAuthor->headers->get('X-Content-Type-Options') === 'nosniff');
        $this->check('la administración de la empresa también puede verla', $this->get($url, $adminA)->getStatusCode() === 200);
        $this->check('otra empresa: 403 en la ruta del adjunto', $this->get($url, $userB)->getStatusCode() === 403);
        $this->check('sin sesión: no se sirve el adjunto', $this->get($url, null)->getStatusCode() !== 200);
        $this->check('un compañero de la empresa ve las fotos del tema (200)', $this->get($url, $colleagueA)->getStatusCode() === 200);
        $replyUrl = route('clarto.mejoras.adjunto', ['improvementRequest' => $request->id, 'index' => 1, 'respuesta' => $photoReply->id], false);
        $asColleague = $this->get($replyUrl, $userA);
        $this->check('foto de una respuesta: 200 (image/webp, nosniff) para la empresa, 403 para otra empresa',
            $asColleague->getStatusCode() === 200 && $asColleague->headers->get('Content-Type') === 'image/webp' && $asColleague->headers->get('X-Content-Type-Options') === 'nosniff'
            && $this->get($replyUrl, $userB)->getStatusCode() === 403);
        $this->check('foto de una respuesta pedida a través de otro tema: 404',
            $this->get(route('clarto.mejoras.adjunto', ['improvementRequest' => $other->id, 'index' => 0, 'respuesta' => $photoReply->id], false), $userA)->getStatusCode() === 404);
        $this->check('las fotos de una duda de Procesos no se sirven al resto de la empresa (403)',
            $this->get(route('clarto.mejoras.adjunto', ['improvementRequest' => $doubt->id, 'index' => 0], false), $userA)->getStatusCode() === 403);
        $this->check('los adjuntos no están en el disco público', collect($request->attachments)->every(fn (array $f): bool => ! Storage::disk('public')->exists($f['path'])));
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
            'name' => $name, 'email' => 'prueba-mejoras-'.bin2hex(random_bytes(5)).'@example.com', 'password' => bcrypt(bin2hex(random_bytes(16))),
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

    private function rejects(callable $fn): bool
    {
        try {
            $fn();
        } catch (ValidationException) {
            return true;
        }

        return false;
    }

    private function denied(callable $fn): bool
    {
        try {
            $fn();
        } catch (AuthorizationException) {
            return true;
        }

        return false;
    }

    private function check(string $label, bool $ok): void
    {
        $ok ? $this->pass++ : $this->fail++;
        $this->line(($ok ? 'PASS  ' : 'FAIL  ').$label);
    }
}
