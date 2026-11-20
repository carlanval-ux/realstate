<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Staff-only mode (CLARTO staging: panel.clarto.es is an internal tool).
 *
 * When config('app.staff_only') is on, every web URL requires an
 * authenticated session — including the public storefront routes this host
 * also ships (/, /properties/*, /news, /contact, ...) — except what is needed
 * to log in. Registration routes answer 404. `/_auth/check` answers 204/401 so
 * the reverse proxy can put static files (e.g. /storage photos, which Apache
 * serves without Laravel) behind the same session via forward_auth.
 */
final class RequireStaffLogin
{
    /** Route names reachable without a session (login and password recovery). */
    private const OPEN_ROUTES = [
        'filament.*.auth.login',
        'filament.*.auth.password-reset.*',
        'filament.*.auth.email-verification.*',
        'filament.*.auth.multi-factor-authentication.*',
        'livewire.*',
        '*-livewire.*',
        'login',
        'logout',
        'password.*',
        'two-factor.*',
        'sanctum.csrf-cookie',
    ];

    /** Route names that must not exist for the public in staff-only mode. */
    private const CLOSED_ROUTES = [
        'register',
        'filament.*.auth.register',
        'oauth.*',
    ];

    /** Paths reachable without a session (assets and machine endpoints). */
    private const OPEN_PATHS = ['up', 'api/*', 'livewire/*', 'livewire-*/*', 'filament/*', 'css/*', 'js/*', 'build/*', 'fonts/*', 'favicon*'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('app.staff_only')) {
            return $next($request);
        }

        if ($request->is('_auth/check')) {
            return response()->noContent($request->user() !== null ? 204 : 401);
        }

        if ($request->routeIs(...self::CLOSED_ROUTES) || $request->is('register', 'register/*')) {
            abort(404);
        }

        // The stock welcome page is a product landing page, not part of the
        // internal tool: signed-in staff go straight to their dashboard.
        if ($request->user() !== null && $request->is('/')) {
            return redirect('/dashboard');
        }

        // One login screen for the internal tool: Fortify's /login (where a password
        // reset lands) would otherwise be a second, differently styled form.
        if ($request->user() === null && $request->isMethod('GET') && $request->is('login')) {
            return redirect('/admin/login');
        }

        if ($request->user() !== null || $request->routeIs(...self::OPEN_ROUTES) || $request->is(...self::OPEN_PATHS)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(401);
        }

        return redirect()->guest('/admin/login');
    }
}
