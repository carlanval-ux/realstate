<?php

use App\Http\Controllers\SearchRequestController;
use App\Http\Controllers\SearchRequestLinkController;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;

/*
 * Solicitudes de inmuebles (docs/clarto/solicitudes.md), loaded by ClartoSearchRequestsServiceProvider.
 * - Inbound e-mail replies from the replies mailbox forwarder: X-Solicitudes-Clave.
 * - Files (request attachments, proposal photos): private disk, access checked per file (staff of the team, or the
 *   collaborator the request was sent to). Staff under filament/*, collaborators under colaboradores/* (fence).
 */
Route::prefix('api/v1/solicitudes')->middleware('api')->withoutMiddleware(ThrottleRequests::class)->group(function (): void {
    Route::post('correo-entrante', [SearchRequestController::class, 'inboundMail'])->middleware('throttle:120,1')->name('clarto.solicitudes.correo');
});

/*
 * Private answer links https://panel…/s/{codigo} (SearchRequestLinkController): no session, no cookies, own limiter.
 * The code is the only credential and is limited to one recipient of one request (see SearchRequestLink).
 */
Route::prefix('s/{codigo}')->where(['codigo' => '[A-Za-z0-9_-]{1,64}'])->middleware('throttle:solicitud-enlace')->group(function (): void {
    Route::get('/', [SearchRequestLinkController::class, 'show'])->name('clarto.solicitudes.enlace');
    Route::post('/', [SearchRequestLinkController::class, 'store'])->name('clarto.solicitudes.enlace.responder');
    Route::get('adjunto/{index}', [SearchRequestLinkController::class, 'attachment'])->whereNumber('index')->name('clarto.solicitudes.enlace.adjunto');
});

foreach (['filament/clarto/solicitudes' => 'clarto.solicitudes.archivo', 'colaboradores/solicitudes-archivos' => 'clarto.solicitudes.archivo.colaborador'] as $prefix => $name) {
    Route::middleware(['web', 'auth'])
        ->get($prefix.'/{searchRequest}/{response}/{index}', [SearchRequestController::class, 'file'])
        ->whereNumber(['searchRequest', 'response', 'index'])
        ->name($name);
}
