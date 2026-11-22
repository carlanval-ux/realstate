<?php

/*
 * Importación de inmuebles de colaboradores (App\Collaborators\Import, docs/clarto/importacion-colaboradores.md).
 * Per-collaborator settings live in the collaborator's record; these are the shared limits.
 */
return [
    'user_agent' => env('IMPORTACION_USER_AGENT', 'ClartoBot/1.0 (+https://clarto.es; importación autorizada de inmuebles de colaboradores)'),

    'web' => [
        // Seconds between two requests to the collaborator's website (robots.txt Crawl-delay wins if larger).
        'pausa' => (float) env('IMPORTACION_WEB_PAUSA', 5),
        // Hard cap of requests per run (robots.txt, sitemaps and pages). Reaching it = incomplete run: nothing is retired.
        'max_paginas' => (int) env('IMPORTACION_WEB_MAX_PAGINAS', 150),
    ],

    'fotos' => [
        // Every photo of the listing; this is only a safety cap per media kind.
        'max_por_inmueble' => (int) env('IMPORTACION_FOTOS_MAX', 100),
        'max_bytes' => 15_000_000,
        'pausa' => 1,   // seconds between photo downloads in web mode
    ],

    // Removing a collaborator's watermark (only if its record authorizes it): IOPaint/LaMa in its own container on
    // the internal network. Empty URL = feature off (photos keep the «revisar fotos» flag).
    'marcas_agua' => [
        'url' => env('IOPAINT_URL'),
        'timeout' => (int) env('IOPAINT_TIMEOUT', 75),   // seconds per photo; the job gives up at 85 s
    ],

    // Who reads pages without JSON-LD / OpenGraph data (App\Collaborators\Import\ListingAi) and its free model
    // (null = the inbound-mail default; never a paid model).
    'ia' => env('IMPORTACION_IA', \App\Collaborators\Import\OpenRouterListingAi::class),
    'ia_modelo' => env('IMPORTACION_IA_MODELO'),
];
