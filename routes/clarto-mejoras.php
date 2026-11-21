<?php

use App\Http\Controllers\ImprovementAttachmentController;
use Illuminate\Support\Facades\Route;

// «Solicitud de mejoras»: screenshots are served only here (session + ImprovementRequestPolicy::view).
// Under filament/*, which the staff-only middlewares leave to the route's own authentication.
Route::middleware(['web', 'auth'])
    ->get('filament/clarto/mejoras/{improvementRequest}/adjuntos/{index}', ImprovementAttachmentController::class)
    ->whereNumber(['improvementRequest', 'index'])
    ->name('clarto.mejoras.adjunto');
