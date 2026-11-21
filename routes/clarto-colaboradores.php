<?php

declare(strict_types=1);

use App\Http\Controllers\CollaboratorController;
use Illuminate\Support\Facades\Route;

// «Colaboradores»: files of shared updates (web session) and the collaborator's read-only API (Sanctum).
Route::middleware(['web', 'auth'])->get('colaboradores/adjuntos/{update}/{index}', [CollaboratorController::class, 'attachment'])
    ->whereNumber(['update', 'index'])->name('colaboradores.adjunto');

Route::middleware(['api', 'auth:sanctum', 'throttle:api'])->prefix('api/v1/colaboradores')->group(function (): void {
    Route::get('inmuebles', [CollaboratorController::class, 'index'])->name('colaboradores.api.inmuebles');
    Route::get('inmuebles/{property}', [CollaboratorController::class, 'show'])->whereNumber('property')->name('colaboradores.api.inmueble');
});
