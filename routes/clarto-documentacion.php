<?php

use App\Http\Controllers\CompanyDocumentFileController;
use Illuminate\Support\Facades\Route;

// «Documentación de la empresa»: files (and annotations' attachments) served only here (session +
// CompanyDocumentPolicy::view), every access logged. Under filament/*, which the staff-only middlewares leave to the
// route's own authentication.
Route::middleware(['web', 'auth', 'throttle:120,1'])->prefix('filament/clarto/documentacion/{companyDocument}')->group(function (): void {
    Route::get('archivos/{index}', CompanyDocumentFileController::class)
        ->whereNumber(['companyDocument', 'index'])
        ->name('clarto.documentacion.archivo');
    Route::get('anotaciones/{annotation}', [CompanyDocumentFileController::class, 'annotation'])
        ->whereNumber(['companyDocument', 'annotation'])
        ->name('clarto.documentacion.anotacion');
});
