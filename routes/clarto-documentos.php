<?php

use App\Http\Controllers\DocumentDownloadController;
use Illuminate\Support\Facades\Route;

// Files of client and property records: session + team + permission (DocumentDownloadController). Under filament/*,
// which the staff-only middlewares leave to the route's own authentication.
Route::middleware(['web', 'auth', 'throttle:120,1'])
    ->get('filament/clarto/documentos/{source}/{id}', DocumentDownloadController::class)
    ->whereIn('source', ['archivo', 'firma', 'firma-enviado', 'albaran'])
    ->whereNumber('id')
    ->name('clarto.documentos.descargar');
