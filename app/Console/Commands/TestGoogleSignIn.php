<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Auth\GoogleSignIn;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use JoelButcher\Socialstream\Contracts\AuthenticatesOAuthCallback;
use JoelButcher\Socialstream\Socialstream;
use Laravel\Socialite\Two\User as GoogleUser;

/**
 * Tests "Continue with Google" without Google: the OAuth redirect and callback guards go through the real HTTP
 * kernel (with a placeholder client id when none is configured), and Google's answer is simulated with Socialite
 * user objects passed to the real callback. Runs inside a rolled-back transaction: no user or link is kept.
 *
 *   php artisan clarto:google-prueba
 */
final class TestGoogleSignIn extends Command
{
    protected $signature = 'clarto:google-prueba';

    protected $description = 'Test Google sign-in rules with simulated Google answers (nothing is kept)';

    private int $pass = 0;

    private int $fail = 0;

    public function handle(): int
    {
        if (blank(config('services.google.client_id')) || blank(config('services.google.client_secret'))) {
            config(['services.google.client_id' => 'prueba.apps.googleusercontent.com', 'services.google.client_secret' => 'secreto-de-prueba',
                'socialstream.providers' => [\JoelButcher\Socialstream\Providers::google('Continuar con Google')]]);
            $this->line('(sin credenciales de Google en el servidor: se usa un cliente de prueba solo en memoria)');
        }

        DB::beginTransaction();
        try {
            $this->scenarios();
        } finally {
            Auth::guard('web')->logout();
            DB::rollBack();
        }

        $this->line(sprintf('RESULT: %d passed, %d failed (Google simulated; database rolled back)', $this->pass, $this->fail));

        return $this->fail === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function scenarios(): void
    {
        $kernel = app(Kernel::class);

        // 1. Redirect to Google: identity scopes only and an OAuth state.
        $response = $kernel->handle(Request::create('/oauth/google', 'GET'));
        $location = (string) $response->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $q);
        $scopes = explode(' ', (string) ($q['scope'] ?? ''));
        sort($scopes);
        $this->check('redirección a Google con estado OAuth', str_starts_with($location, 'https://accounts.google.com/') && filled($q['state'] ?? null));
        $this->check('solo permisos de identidad (openid, email, profile)', $scopes === ['email', 'openid', 'profile']);
        $this->check('callback registrado: '.($q['redirect_uri'] ?? '?'), ($q['redirect_uri'] ?? '') === url('/oauth/google/callback'));
        $this->check('otros proveedores cerrados (404)', $kernel->handle(Request::create('/oauth/github', 'GET'))->getStatusCode() === 404);

        // 2. Callback with a state that does not match the session → back to the login, never a session.
        $bad = $kernel->handle(Request::create('/oauth/google/callback', 'GET', ['state' => 'falso', 'code' => 'x']));
        $this->check('estado OAuth inválido: vuelve al login sin sesión', $bad->isRedirect() && str_contains((string) $bad->headers->get('Location'), '/admin/login') && ! Auth::check());

        // 3. User cancels on Google's screen.
        $cancel = $kernel->handle(Request::create('/oauth/google/callback', 'GET', ['error' => 'access_denied']));
        $this->check('cancelación en Google: vuelve al login', $cancel->isRedirect() && ! Auth::check());

        $user = User::query()->forceCreate(['name' => 'Prueba Google', 'email' => 'prueba.google@clarto.es', 'password' => Hash::make('clave-prueba-123'), 'email_verified_at' => now()]);
        $callback = app(AuthenticatesOAuthCallback::class);
        $this->check('callback de CLARTO activo', $callback instanceof GoogleSignIn);
        $google = fn (string $sub, string $email, bool $verified = true) => (new GoogleUser)->setRaw(['sub' => $sub, 'email' => $email, 'email_verified' => $verified])
            ->map(['id' => $sub, 'email' => $email, 'name' => 'Prueba', 'nickname' => null, 'avatar' => null])->setToken('t');
        $users = User::query()->count();

        // 4. Unknown Google account (or a known e-mail never linked): no sign-in, no account created.
        $callback->authenticate('google', $google('sub-desconocido', 'otra@gmail.com'));
        $this->check('cuenta de Google desconocida: rechazada', ! Auth::check() && User::query()->count() === $users);
        $callback->authenticate('google', $google('sub-sin-vincular', 'prueba.google@clarto.es'));
        $this->check('mismo correo sin vincular: no entra ni se vincula solo', ! Auth::check() && ! $user->connectedAccounts()->exists());

        // 5. Linking from the profile (signed in with the password): e-mail must be verified and equal.
        Auth::guard('web')->login($user);
        $callback->authenticate('google', $google('sub-ajeno', 'otra@gmail.com'));
        $this->check('vinculación con otro correo: rechazada', ! $user->connectedAccounts()->exists());
        $callback->authenticate('google', $google('sub-no-verificado', 'prueba.google@clarto.es', false));
        $this->check('vinculación con correo no verificado: rechazada', ! $user->connectedAccounts()->exists());
        $callback->authenticate('google', $google('sub-clarto-1', 'prueba.google@clarto.es'));
        $account = $user->connectedAccounts()->where('provider', 'google')->first();
        $this->check('vinculación correcta: guarda el identificador estable (sub)', $account?->provider_id === 'sub-clarto-1');
        Auth::guard('web')->logout();

        // 6. Later sign-ins are recognised by the id, even if the Google e-mail changes.
        $callback->authenticate('google', $google('sub-clarto-1', 'nuevo-alias@gmail.com'));
        $this->check('acceso posterior por identificador', Auth::id() === $user->getKey());
        $this->check('mismos permisos que con contraseña (acceso al panel según roles)', $user->canAccessPanel(\Filament\Facades\Filament::getPanel('admin')) === $user->hasAdminAccess());
        Auth::guard('web')->logout();

        // 7. Disabled account: Google refused; the panel refuses it too.
        $user->forceFill(['disabled_at' => now()])->save();
        $callback->authenticate('google', $google('sub-clarto-1', 'prueba.google@clarto.es'));
        $this->check('cuenta desactivada: Google rechazado', ! Auth::check());
        $this->check('cuenta desactivada: sin acceso al panel', ! $user->fresh()->canAccessPanel(\Filament\Facades\Filament::getPanel('admin')));
        $user->forceFill(['disabled_at' => null])->save();

        // 8. Password sign-in still works for the same account; public registration stays closed.
        $this->check('acceso por contraseña intacto', Auth::guard('web')->validate(['email' => 'prueba.google@clarto.es', 'password' => 'clave-prueba-123']));
        $this->check('registro público cerrado (404)', $kernel->handle(Request::create('/register', 'GET'))->getStatusCode() === 404);
        $this->check('sin alta automática al iniciar con Google', ! \JoelButcher\Socialstream\Features::hasCreateAccountOnFirstLoginFeatures() && Socialstream::providers() !== []);
    }

    private function check(string $label, bool $ok): void
    {
        $ok ? $this->pass++ : $this->fail++;
        $this->line(($ok ? 'PASS  ' : 'FAIL  ').$label);
    }
}
