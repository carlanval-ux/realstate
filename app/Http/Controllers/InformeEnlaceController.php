<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Valuations\GhlValuationSync;
use App\Valuations\ReportData;
use App\Valuations\ReportLink;
use App\Valuations\ReportLinks;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public report links on valoraciones.clarto.es (docs/clarto/valoraciones.md «Enlaces públicos del informe»):
 *  GET /r/{codigo}              consultation page (opens counted, previews and scanners not)
 *  GET /r/{codigo}/informe.pdf  the PDF, inline (?descargar=1: attachment)
 * Resolved here, no redirect: the browser stays on this host. GET changes nothing but the open counters, so link
 * previews and mail scanners never use up or invalidate a link. Every answer: noindex, no-referrer, strict CSP with
 * no third-party resources, no cache. Any other host answers 404 (the panel does not serve /r/*).
 */
final class InformeEnlaceController
{
    public function show(Request $request, string $codigo): Response
    {
        [$link, $refusal] = $this->resolve($request, $codigo);
        if ($refusal !== null) {
            return $refusal;
        }
        if ($request->isMethod('GET') && ! ValoracionesController::isPreview($request)) {
            $link->forceFill(['opens' => $link->opens + 1, 'last_opened_at' => now()])->save();
            $valuation = $link->request;
            $valuation->increment('report_opens');
            dispatch(fn () => (new GhlValuationSync)->reportOpens($valuation->refresh()))->afterResponse();
        }

        return self::page('activo', 200, [], ['link' => $link, 'codigo' => $codigo]);
    }

    public function pdf(Request $request, string $codigo): Response
    {
        [$link, $refusal] = $this->resolve($request, $codigo);
        if ($refusal !== null) {
            return $refusal;
        }
        $response = ValoracionesController::pdf($link->request, $link->run, $request->boolean('descargar') ? 'attachment' : 'inline');
        $response->headers->add(self::headers("default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'"));

        return $response;
    }

    /** Rendered page with the link's headers (also the rate limiter's 429). */
    public static function page(string $estado, int $status, array $headers = [], array $data = []): Response
    {
        $nonce = Str::random(24);
        $html = view('valoraciones.enlace', $data + [
            'estado' => $estado, 'nonce' => $nonce, 'logo' => self::logo(),
            'phone' => ReportData::PHONE, 'whatsapp' => ReportData::WHATSAPP, 'email' => ReportData::EMAIL,
        ])->render();

        return response($html, $status, self::headers(
            "default-src 'none'; style-src 'nonce-{$nonce}'; img-src data:; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
        ) + ['Content-Type' => 'text/html; charset=utf-8'] + $headers);
    }

    /** @return array<string, string> */
    public static function headers(string $csp): array
    {
        return [
            'Content-Security-Policy' => $csp,
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
        ];
    }

    /** @return array{0: ?ReportLink, 1: ?Response} the active link, or the page that explains why not */
    private function resolve(Request $request, string $codigo): array
    {
        if (Str::lower($request->getHost()) !== Str::lower(ReportLinks::host())) {
            abort(404);
        }
        $found = ReportLinks::resolve($codigo);
        $link = $found['link'];
        if ($found['estado'] !== 'activo' || $link->run?->pdf_path === null) {
            $estado = $found['estado'] === 'activo' ? 'desconocido' : $found['estado'];

            return [null, self::page($estado, $estado === 'desconocido' ? 404 : 410, [], ['link' => $link])];
        }

        return [$link, null];
    }

    private static function logo(): string
    {
        static $logo = null;

        return $logo ??= 'data:image/jpeg;base64,'.base64_encode((string) file_get_contents(resource_path('valoraciones/logo-negativo.jpg')));
    }
}
