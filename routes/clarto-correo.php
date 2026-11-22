<?php

use App\Http\Controllers\InboundMailAttachmentController;
use Illuminate\Support\Facades\Route;

/* «Correo entrante» (docs/clarto/correo-entrante.md), loaded by ClartoInboundMailServiceProvider: attachments, staff only (under filament/*). */
Route::middleware(['web', 'auth'])
    ->get('filament/clarto/correo/{mail}/{index}', InboundMailAttachmentController::class)
    ->whereNumber(['mail', 'index'])
    ->name('clarto.correo.adjunto');
