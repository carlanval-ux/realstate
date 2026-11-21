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
 * «Solicitud de mejoras» end to end with fictitious teams and users: sending with several screenshots,
 * server-side rejection of wrong type / oversize / someone else's upload, an administrator's reply and status
 * changes with history, and isolation between companies (policy, listing and the attachment route).
 * Database in a rolled-back transaction, private disk faked in a temporary directory: nothing is kept.
 *
 *   php artisan clarto:mejoras-prueba
 */
final class TestImprovementRequests extends Command
{
    protected $signature = 'clarto:mejoras-prueba';

    protected $description = 'Test «Solicitud de mejoras» (uploads, replies, isolation between companies); nothing is kept';

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

        // 4. Who sees what.
        $other = ImprovementRequest::submit($colleagueA, $teamA->id, 'Otra propuesta', 'De un compañero.');
        $ids = fn (User $u): array => ImprovementRequest::query()->visibleTo($u)->pluck('id')->sort()->values()->all();
        $this->check('cada usuario ve las suyas (y la respuesta), no las de sus compañeros',
            $ids($userA) === [$request->id] && Gate::forUser($userA)->allows('view', $request) && ! Gate::forUser($userA)->allows('view', $other));
        $this->check('la administración de la empresa ve todas las de su empresa', $ids($adminA) === collect([$request->id, $other->id])->sort()->values()->all());
        $this->check('una cuenta de otra empresa no ve ni lista la solicitud (política y listado)',
            $ids($userB) === [] && ! Gate::forUser($userB)->allows('view', $request) && ! Gate::forUser($userB)->allows('update', $request));

        // 5. Attachments only through the authorised route.
        $url = route('clarto.mejoras.adjunto', ['improvementRequest' => $request->id, 'index' => 0], false);
        $asAuthor = $this->get($url, $userA);
        $this->check('la autora descarga su captura por la ruta autorizada (image/png, nosniff)',
            $asAuthor->getStatusCode() === 200 && $asAuthor->headers->get('Content-Type') === 'image/png' && $asAuthor->headers->get('X-Content-Type-Options') === 'nosniff');
        $this->check('la administración de la empresa también puede verla', $this->get($url, $adminA)->getStatusCode() === 200);
        $this->check('otra empresa: 403 en la ruta del adjunto', $this->get($url, $userB)->getStatusCode() === 403);
        $this->check('sin sesión: no se sirve el adjunto', $this->get($url, null)->getStatusCode() !== 200);
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
