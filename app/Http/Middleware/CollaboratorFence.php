<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Collaborators\CollaboratorAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps signed-in external collaborators inside their area (groups `web` and `api`). The collaborator panel runs its
 * own middleware stack and only lets enabled collaborators in; here every other web page and API endpoint (inventory,
 * contacts, documents, exports, the generic REST API…) is closed to them, whatever role or permission they hold.
 */
final class CollaboratorFence
{
    private const ALLOWED = [
        CollaboratorAccess::PANEL, CollaboratorAccess::PANEL.'/*', 'api/v1/colaboradores', 'api/v1/colaboradores/*',
        'livewire/*', 'livewire-*/*', 'filament/*', 'logout', 'up',
        'forgot-password', 'reset-password', 'reset-password/*',
        'css/*', 'js/*', 'build/*', 'fonts/*', 'brand/*', 'favicon*', 'robots.txt',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->is('api/*') ? $request->user('sanctum') : $request->user();
        if ($user === null || $request->is(...self::ALLOWED) || ! CollaboratorAccess::isCollaborator($user)) {
            return $next($request);
        }

        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        return redirect('/'.CollaboratorAccess::PANEL);
    }
}
