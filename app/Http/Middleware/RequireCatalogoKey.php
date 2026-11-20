<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The catalog API is called by the website's server, never by browsers: it
 * must present the shared key in X-Catalogo-Clave. Without a configured key the
 * API is closed.
 */
final class RequireCatalogoKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('catalogo.api_key');
        $given = (string) $request->header('X-Catalogo-Clave', '');

        if ($expected === '' || ! hash_equals($expected, $given)) {
            return response()->json(['message' => 'No autorizado.'], 401);
        }

        $response = $next($request);
        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
    }
}
