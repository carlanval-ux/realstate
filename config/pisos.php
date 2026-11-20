<?php

/*
| pisos.com publishing connector (CLARTO).
|
| Real sends are OFF until PISOS_ENABLED=true and pisos.com has provided the credentials and the integration
| spec (see docs/clarto/pisos-com.md). With PISOS_DRIVER=simulado the panel actions work against a local
| simulation with the same contract, so the team can try the flow without touching the portal.
*/

return [
    'enabled' => (bool) env('PISOS_ENABLED', false),
    'driver' => env('PISOS_DRIVER', 'simulado'), // simulado | api

    'base_url' => env('PISOS_BASE_URL', 'https://api.pisos.com/v5'),
    'api_key' => env('PISOS_API_KEY'),
    'user_id' => env('PISOS_USER_ID'),          // agency account userId (deactivate/activate need it)
    'agency_id' => env('PISOS_AGENCY_ID'),      // "código de agencia / volcado", if the gateway requires it
    'origen_publicacion' => (int) env('PISOS_ORIGEN_PUBLICACION', 15),
    'timeout' => (int) env('PISOS_TIMEOUT', 30),

    // Authorised contact data shown on pisos.com (the agency's, never the owner's).
    'contacto' => [
        'nombre' => env('PISOS_CONTACTO_NOMBRE', 'CLARTO Inmobiliaria'),
        'email' => env('PISOS_CONTACTO_EMAIL', 'info@clarto.es'),
        'telefono' => env('PISOS_CONTACTO_TELEFONO'),
    ],

    /*
    | Code tables. pisos.com only serves them with an ApiKey (maestros/tiposinmueble, dictionary/operation,
    | zona/*), so they are filled once access is granted. Documented examples are pre-filled; null = unknown.
    */
    'tipos' => [                       // our property_type => CodigoTipoInmueble
        'apartment' => 'F002S021T001', // piso (documented example)
        'residential' => 'F002S021T001',
        'condo' => 'F002S021T001',
        'house' => null,
        'villa' => null,
        'townhouse' => null,
        'commercial' => null,
        'land' => null,
    ],
    'operaciones' => [                 // our deal_type => Precio.TipoOperacion (codes not documented)
        'sale' => env('PISOS_OPERACION_VENTA'),
        'rent' => env('PISOS_OPERACION_ALQUILER'),
    ],
    // City/district slug => CodigoZonaGeo (pisos.com zone code). Without it the listing is not sent.
    'zonas' => [],
];
