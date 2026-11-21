<?php

use App\Http\Controllers\ValoracionesController;
use App\Http\Middleware\RequireValoracionesKey;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;

/*
 * Valoraciones automáticas (docs/clarto/valoraciones.md), loaded by ClartoValuationsServiceProvider.
 * - API for the website's server: X-Valoraciones-Clave, then per-visitor limits (X-Cliente-Ip) and a global one.
 * - Report: signed, expiring link (no login) under api/*, and a panel route for staff (ValuationAccess).
 * Both under paths the staff-only middleware leaves to the route's own authentication (api/*, filament/*).
 */
Route::prefix('api/v1/valoraciones')->middleware('api')->withoutMiddleware(ThrottleRequests::class)->group(function (): void {
    Route::middleware([RequireValoracionesKey::class, 'throttle:valoraciones'])->group(function (): void {
        Route::get('catastro', [ValoracionesController::class, 'catastro'])->name('valoraciones.catastro');
        Route::post('solicitudes', [ValoracionesController::class, 'store'])->name('valoraciones.store');
        Route::get('solicitudes/{requestId}', [ValoracionesController::class, 'show'])->whereUuid('requestId')->name('valoraciones.show');
    });

    Route::get('informes/{requestId}/{run}', [ValoracionesController::class, 'signedReport'])
        ->whereUuid('requestId')->whereNumber('run')
        ->middleware(['signed', 'throttle:60,1'])
        ->name('valoraciones.informe');
});

Route::middleware(['web', 'auth'])
    ->get('filament/clarto/valoraciones/{valuationRequest}/informe/{run?}', [ValoracionesController::class, 'panelReport'])
    ->whereNumber(['valuationRequest', 'run'])
    ->name('clarto.valoraciones.informe');
