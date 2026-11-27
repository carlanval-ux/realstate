<?php

/**
 * Billing program that receives albaranes as pending invoicing: Factúralo (the group's FacturaScripts).
 * See docs/clarto/albaranes.md.
 *
 * FACTURADOR_DRIVER=simulado (default) keeps everything on the server; =facturalo talks to the Factúralo instance
 * at FACTURADOR_URL, and only when FACTURADOR_ENABLED=true and the key is set. Lines are free text and articles are
 * made by hand in the panel: there is no predefined catalogue.
 */
return [
    'driver' => env('FACTURADOR_DRIVER', 'simulado'),
    'enabled' => (bool) env('FACTURADOR_ENABLED', false),
    // Factúralo instance, e.g. https://<slug>.facturalo.redtelecom.app (the client adds /api/3/…)
    'base_url' => env('FACTURADOR_URL'),
    // Factúralo API key (header Token), set on the server by the owner; permissions in docs/clarto/albaranes.md
    'api_key' => env('FACTURADOR_API_KEY'),
    'timeout' => (int) env('FACTURADOR_TIMEOUT', 20),

    // Defaults of the albaranes created in Factúralo (empty = the instance's own default).
    'facturalo' => [
        'codserie' => env('FACTURALO_CODSERIE'),
        'codpago' => env('FACTURALO_CODPAGO'),
        'codalmacen' => env('FACTURALO_CODALMACEN'),
    ],
];
