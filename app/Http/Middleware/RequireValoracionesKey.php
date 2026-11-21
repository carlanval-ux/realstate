<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The valuations API is called by the website's server only, with the shared key in X-Valoraciones-Clave.
 * Without a configured key the API is closed.
 */
final class RequireValoracionesKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('valoraciones.api_key');
        if ($expected === '' || ! hash_equals($expected, (string) $request->header('X-Valoraciones-Clave', ''))) {
            return response()->json(['message' => 'No autorizado.'], 401);
        }

        $response = $next($request);
        $response->headers->set('X-Robots-Tag', 'noindex');
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
