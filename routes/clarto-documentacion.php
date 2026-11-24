<?php

use App\Http\Controllers\CompanyDocumentFileController;
use Illuminate\Support\Facades\Route;

// «Documentación de la empresa»: files served only here (session + CompanyDocumentPolicy::view), every access logged.
// Under filament/*, which the staff-only middlewares leave to the route's own authentication.
Route::middleware(['web', 'auth', 'throttle:120,1'])
    ->get('filament/clarto/documentacion/{companyDocument}/archivos/{index}', CompanyDocumentFileController::class)
    ->whereNumber(['companyDocument', 'index'])
    ->name('clarto.documentacion.archivo');
