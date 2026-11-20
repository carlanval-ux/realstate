<?php

/*
 * Public property catalog API consumed server-side by the CLARTO website
 * (clarto.es/inmuebles). Read-only; exposes only the public fields of listings
 * whose status authorises publication. See App\Http\Controllers\CatalogoController.
 */
return [
    // Shared secret sent by the website's server in X-Catalogo-Clave. Never shipped to browsers.
    'api_key' => env('CATALOGO_API_KEY'),

    // Liberu statuses shown in the catalog and the public label for each.
    'estados_publicos' => [
        'available' => 'disponible',
        'published' => 'disponible',
        'under_offer' => 'reservado',
    ],

    // While the catalog only holds fictitious listings every one is flagged so the
    // website marks it "Inmueble de prueba". Turn off before publishing real stock.
    'modo_pruebas' => (bool) env('CATALOGO_MODO_PRUEBAS', false),

    // Location precision exposed publicly: "zona" (no coordinates), "aproximada"
    // (coordinates rounded to ~1 km) or "exacta".
    'precision_ubicacion' => env('CATALOGO_PRECISION_UBICACION', 'aproximada'),

    // Photo variant widths (px) the website may request.
    'anchos_foto' => [480, 960, 1600],
];
