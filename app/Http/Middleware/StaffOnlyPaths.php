<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Staff-only mode, global layer (runs for every request, before routing).
 *
 * Some modules register public storefront routes without the `web` group
 * (e.g. real-estate-properties-livewire: /properties, /properties/{id}), so
 * RequireStaffLogin — which lives in the `web` group — never sees them. Here,
 * any path outside the areas that enforce their own authentication is sent to
 * the panel login: Filament panels, the API (Sanctum or the catalog key), the
 * web routes guarded by RequireStaffLogin, login/password recovery, Livewire
 * endpoints and static assets.
 */
final class StaffOnlyPaths
{
    private const ALLOWED = [
        '/', 'admin', 'admin/*', 'app', 'app/*', 'dashboard',
        'colaboradores', 'colaboradores/*', // collaborator panel: its own login; CollaboratorFence + canAccessPanel restrict it
        'api/*', 'up', '_auth/check', 'sanctum/*',
        'r/*', // public valuation report links: only on valoraciones.clarto.es, 404 on any other host (InformeEnlaceController)
        'livewire/*', 'livewire-*/*', 'filament/*',
        'oauth/*', // RequireStaffLogin only lets Google through, and only when its client is configured
        'login', 'logout', 'forgot-password', 'reset-password', 'reset-password/*',
        'two-factor-challenge', 'user/*', 'email/*', 'register', 'register/*',
        'telescope', 'telescope/*', 'pulse', 'pulse/*', 'horizon', 'horizon/*',
        'css/*', 'js/*', 'build/*', 'fonts/*', 'brand/*', 'storage/*', 'favicon*', 'robots.txt',
    ];

    /**
     * Anonymous API endpoints shipped for a public storefront. In staff-only mode the
     * website reads the key-protected catalog instead, so these stay closed: the public
     * property API returns exact address and coordinates, and auth/register would add
     * self-registered users to the agency's team.
     */
    private const CLOSED_API = [
        'api/v1/public/*',
        'api/v1/auth/register',
        'api/v1/real-estate/properties/*/ar-tour/availability',
        'api/v1/real-estate/properties/*/ar-tour/config',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (config('app.staff_only') && $request->is(...self::CLOSED_API)) {
            return response()->json(['message' => 'No encontrado.'], 404);
        }

        if (! config('app.staff_only') || $request->is(...self::ALLOWED)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'No autorizado.'], 401);
        }

        return redirect('/admin/login');
    }
}
