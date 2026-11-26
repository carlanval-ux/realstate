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
    // The web viewer's frame: PDF and images as they are, Word / Excel / OpenDocument as the PDF converted here; inline,
    // framable by the panel itself only (X-Frame-Options SAMEORIGIN), same permission and log as the files.
    Route::get('archivos/{index}/vista', [CompanyDocumentFileController::class, 'preview'])
        ->whereNumber(['companyDocument', 'index'])
        ->name('clarto.documentacion.archivo.vista');
    Route::get('anotaciones/{annotation}/vista', [CompanyDocumentFileController::class, 'annotationPreview'])
        ->whereNumber(['companyDocument', 'annotation'])
        ->name('clarto.documentacion.anotacion.vista');
});
