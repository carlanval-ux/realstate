<?php

use App\Http\Controllers\SearchRequestController;
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

foreach (['filament/clarto/solicitudes' => 'clarto.solicitudes.archivo', 'colaboradores/solicitudes-archivos' => 'clarto.solicitudes.archivo.colaborador'] as $prefix => $name) {
    Route::middleware(['web', 'auth'])
        ->get($prefix.'/{searchRequest}/{response}/{index}', [SearchRequestController::class, 'file'])
        ->whereNumber(['searchRequest', 'response', 'index'])
        ->name($name);
}
