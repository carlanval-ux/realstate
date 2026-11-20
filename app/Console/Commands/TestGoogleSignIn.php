<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Auth\GoogleSignIn;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Hash;
use JoelButcher\Socialstream\Contracts\AuthenticatesOAuthCallback;
use JoelButcher\Socialstream\Features;
use JoelButcher\Socialstream\Providers;
use JoelButcher\Socialstream\Socialstream;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;

/**
 * Tests "Continue with Google" without Google. The redirect and the callback guards go through the real HTTP
 * kernel; Google's answer is simulated with Socialite::fake() and handed to the real callback inside a routed
 * request (as /oauth/google/callback). Runs in a rolled-back transaction: no user or link is kept.
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
                'socialstream.providers' => [Providers::google('Continuar con Google')]]);
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

        // 1. Redirect to Google (real Socialite driver, nothing is sent): identity scopes only, OAuth state, callback.
        $response = $kernel->handle(Request::create('/oauth/google', 'GET'));
        $location = (string) $response->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $q);
        $scopes = explode(' ', (string) ($q['scope'] ?? ''));
        sort($scopes);
        $this->check('redirección a Google con estado OAuth', str_starts_with($location, 'https://accounts.google.com/') && filled($q['state'] ?? null));
        $this->check('solo permisos de identidad (openid, email, profile)', $scopes === ['email', 'openid', 'profile']);
        $this->check('callback: '.($q['redirect_uri'] ?? '?'), str_ends_with((string) ($q['redirect_uri'] ?? ''), '/oauth/google/callback'));
        $other = $kernel->handle(Request::create('/oauth/github', 'GET'));
        $this->check('otros proveedores cerrados', ! str_contains((string) $other->headers->get('Location'), 'github.com'));

        // 2. Callback with a state that does not match the session → back to the login, no session.
        $bad = $kernel->handle(Request::create('/oauth/google/callback', 'GET', ['state' => 'falso', 'code' => 'x']));
        $this->check('estado OAuth inválido: vuelve al login sin sesión', $bad->isRedirect() && str_contains((string) $bad->headers->get('Location'), '/admin/login') && ! Auth::check());

        // 3. The user cancels on Google's screen.
        $cancel = $kernel->handle(Request::create('/oauth/google/callback', 'GET', ['error' => 'access_denied']));
        $this->check('cancelación en Google: vuelve al login', $cancel->isRedirect() && ! Auth::check());

        $callback = app(AuthenticatesOAuthCallback::class);
        $this->check('callback de CLARTO activo', $callback instanceof GoogleSignIn);

        $user = User::query()->forceCreate(['name' => 'Prueba Google', 'email' => 'prueba.google@clarto.es', 'password' => Hash::make('clave-prueba-123'), 'email_verified_at' => now()]);
        $users = User::query()->count();

        // 4. Unknown Google account, or the same e-mail never linked: no sign-in, nothing created or linked.
        $this->google($callback, 'sub-desconocido', 'otra@gmail.com');
        $this->check('cuenta de Google desconocida: rechazada', ! Auth::check() && User::query()->count() === $users);
        $this->google($callback, 'sub-sin-vincular', 'prueba.google@clarto.es');
        $this->check('mismo correo sin vincular: no entra ni se vincula solo', ! Auth::check() && ! $user->connectedAccounts()->exists());

        // 5. Linking from the profile, signed in with the password: verified e-mail equal to the account's.
        Auth::guard('web')->login($user);
        $this->google($callback, 'sub-ajeno', 'otra@gmail.com');
        $this->check('vinculación con otro correo: rechazada', ! $user->connectedAccounts()->exists());
        $this->google($callback, 'sub-no-verificado', 'prueba.google@clarto.es', verified: false);
        $this->check('vinculación con correo no verificado: rechazada', ! $user->connectedAccounts()->exists());
        $this->google($callback, 'sub-clarto-1', 'prueba.google@clarto.es');
        $this->check('vinculación correcta: guarda el identificador estable (sub)', $user->connectedAccounts()->where('provider', 'google')->value('provider_id') === 'sub-clarto-1');
        Auth::guard('web')->logout();

        // 6. Later sign-ins are recognised by the id, even if the Google e-mail changed.
        $this->google($callback, 'sub-clarto-1', 'alias-nuevo@gmail.com');
        $this->check('acceso posterior por identificador (aunque cambie el correo de Google)', Auth::id() === $user->getKey());
        $this->check('mismos permisos que con contraseña', $user->canAccessPanel(Filament::getPanel('admin')) === $user->hasAdminAccess());
        Auth::guard('web')->logout();

        // 7. Two-factor authentication is kept: a user with 2FA gets the challenge, not a session.
        $user->forceFill(['two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_recovery_codes' => encrypt(json_encode(['a-b'])), 'two_factor_confirmed_at' => now()])->save();
        $response = $this->google($callback, 'sub-clarto-1', 'prueba.google@clarto.es');
        $target = method_exists($response, 'getTargetUrl') ? $response->getTargetUrl() : (string) ($response->headers->get('Location') ?? '');
        $this->check('2FA conservado: pide el código, sin sesión todavía', ! Auth::check() && str_contains($target, 'two-factor'));
        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();

        // 8. Disabled account: Google refused and the panel refuses it too.
        $user->forceFill(['disabled_at' => now()])->save();
        $this->google($callback, 'sub-clarto-1', 'prueba.google@clarto.es');
        $this->check('cuenta desactivada: Google rechazado', ! Auth::check());
        $this->check('cuenta desactivada: sin acceso al panel', ! $user->fresh()->canAccessPanel(Filament::getPanel('admin')));
        $user->forceFill(['disabled_at' => null])->save();

        // 9. Password sign-in unchanged; no public or automatic registration.
        $this->check('acceso por contraseña intacto', Auth::guard('web')->validate(['email' => 'prueba.google@clarto.es', 'password' => 'clave-prueba-123']));
        $this->check('registro público cerrado (404)', $kernel->handle(Request::create('/register', 'GET'))->getStatusCode() === 404);
        $this->check('sin alta automática con Google', ! Features::hasCreateAccountOnFirstLoginFeatures() && User::query()->count() === $users);
    }

    /** Simulates Google answering the callback for this account, through the real CLARTO callback. */
    private function google(AuthenticatesOAuthCallback $callback, string $sub, string $email, bool $verified = true): mixed
    {
        $google = (new GoogleUser)->setRaw(['sub' => $sub, 'email' => $email, 'email_verified' => $verified])
            ->map(['id' => $sub, 'email' => $email, 'name' => 'Prueba', 'nickname' => null, 'avatar' => null])->setToken('t');
        Socialite::fake('google', $google);

        $request = Request::create('/oauth/google/callback', 'GET');
        $session = app('session')->driver('array');
        $session->start();
        $request->setLaravelSession($session);
        $route = (new Route(['GET'], '/oauth/{provider}/callback', fn () => null))->bind($request);
        $route->setParameter('provider', 'google');
        $request->setRouteResolver(fn () => $route);
        app()->instance('request', $request);
        Facade::clearResolvedInstance('request');

        try {
            return $callback->authenticate('google', $google);
        } catch (\Illuminate\Validation\ValidationException) {
            return null; // Fortify refuses the attempt (same as a failed login)
        }
    }

    private function check(string $label, bool $ok): void
    {
        $ok ? $this->pass++ : $this->fail++;
        $this->line(($ok ? 'PASS  ' : 'FAIL  ').$label);
    }
}
