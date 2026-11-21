<?php

use App\Http\Controllers\ProcessAttachmentController;
use Illuminate\Support\Facades\Route;

// «Procesos y conocimiento»: attachments are served only here (session + ProcessAccess::canDownload, 404 otherwise).
// Under filament/*, which the staff-only middlewares leave to the route's own authentication.
Route::middleware(['web', 'auth'])
    ->get('filament/clarto/procesos/adjunto/{attachment}', ProcessAttachmentController::class)
    ->whereNumber('attachment')
    ->name('clarto.procesos.adjunto');
