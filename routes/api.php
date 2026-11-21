<?php

use App\Http\Controllers\CatalogoController;
use App\Http\Middleware\RequireCatalogoKey;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;

/*
 * Public property catalog for the CLARTO website (read-only).
 * - Listing, detail and sitemap: server-to-server, X-Catalogo-Clave required.
 * - Photo variants: loaded by browsers through signed URLs; served only while
 *   the listing is public.
 * The API-wide 60/min limiter is replaced here by limits sized for a website server.
 */
Route::prefix('v1/catalogo')->withoutMiddleware(ThrottleRequests::class)->group(function (): void {
    Route::middleware([RequireCatalogoKey::class, 'throttle:1200,1'])->group(function (): void {
        Route::get('inmuebles', [CatalogoController::class, 'index'])->name('catalogo.index');
        Route::get('inmuebles/{referencia}', [CatalogoController::class, 'show'])
            ->where('referencia', '[A-Za-z]+-\d{4}-\d+')->name('catalogo.show');
        Route::get('sitemap', [CatalogoController::class, 'sitemap'])->name('catalogo.sitemap');
    });

    Route::get('fotos/{documento}/{ancho}', [CatalogoController::class, 'foto'])
        ->whereNumber(['documento', 'ancho'])
        ->middleware(['signed', 'throttle:600,1'])
        ->name('catalogo.foto');
});

/*
 * GoHighLevel → Liberu: an acquisition opportunity was won. Shared-secret header, then verified with the GHL API
 * (App\Acquisition\GhlHandoff). Idempotent per opportunity.
 */
Route::post('v1/ghl/oportunidad-ganada', [\App\Http\Controllers\GhlWebhookController::class, 'won'])
    ->withoutMiddleware(ThrottleRequests::class)
    ->middleware('throttle:120,1')
    ->name('ghl.won');

/*
 * WAHA → Liberu: WhatsApp events of the company line, from the internal network only, HMAC-signed
 * (App\Http\Controllers\WhatsAppWebhookController). Idempotent per event and per message.
 */
Route::post('v1/whatsapp/webhook', \App\Http\Controllers\WhatsAppWebhookController::class)
    ->withoutMiddleware(ThrottleRequests::class)
    ->middleware('throttle:600,1')
    ->name('whatsapp.webhook');

/*
 * DocuSeal → CLARTO: signing events. HMAC-signed body, then reconciled with the DocuSeal API
 * (App\Signing\Signing). Idempotent per event; a duplicated delivery does nothing.
 */
Route::post('v1/firma/docuseal', \App\Http\Controllers\DocusealWebhookController::class)
    ->withoutMiddleware(ThrottleRequests::class)
    ->middleware('throttle:600,1')
    ->name('firma.docuseal.webhook');
