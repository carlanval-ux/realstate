<?php

/**
 * Billing program («facturador») that receives albaranes as pending invoicing. See docs/clarto/albaranes.md.
 *
 * FACTURADOR_DRIVER=simulado (default) keeps everything on the server; =api talks to FACTURADOR_URL, and only
 * when FACTURADOR_ENABLED=true and the key is set.
 */
return [
    'driver' => env('FACTURADOR_DRIVER', 'simulado'),
    'enabled' => (bool) env('FACTURADOR_ENABLED', false),
    // e.g. https://<dominio>/api/public/v1 (same versioned API as the DEALER 360 client profile)
    'base_url' => env('FACTURADOR_URL'),
    // Bearer key with scopes customers:read/write, delivery-notes:write, invoices:read, catalog:read (rt_test_… in tests)
    'api_key' => env('FACTURADOR_API_KEY'),
    'timeout' => (int) env('FACTURADOR_TIMEOUT', 20),

    /*
     * Local services catalogue, used while the billing program does not expose its own (GET catalog/services).
     * Codes and prices are indicative and must be confirmed with the billing program's catalogue.
     * conformidad = the client must sign their conformity before the albarán is sent.
     */
    'servicios' => [
        'GES-CEE' => ['nombre' => 'Certificado de eficiencia energética', 'precio' => 150.00, 'conformidad' => false],
        'GES-NOTA' => ['nombre' => 'Nota simple registral', 'precio' => 20.00, 'conformidad' => false],
        'GES-PLUSV' => ['nombre' => 'Gestión de plusvalía municipal', 'precio' => 90.00, 'conformidad' => false],
        'INM-FOTO' => ['nombre' => 'Reportaje fotográfico del inmueble', 'precio' => 120.00, 'conformidad' => true],
        'INM-REPAR' => ['nombre' => 'Coordinación de reparación', 'precio' => 60.00, 'conformidad' => true],
    ],
];
