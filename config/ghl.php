<?php

/*
| GoHighLevel (owner acquisition). The opportunity is managed in GHL; when it is won in the acquisition
| pipeline, GHL calls Liberu's webhook and Liberu opens the file (owner + draft property + mandate).
| Credentials only in the server's .env (see docs/clarto/captacion-ghl.md).
*/

return [
    'api_url' => rtrim((string) env('GHL_API_URL', 'https://services.leadconnectorhq.com'), '/'),
    'api_version' => env('GHL_API_VERSION', '2021-07-28'),
    'token' => env('GHL_API_TOKEN'),            // Private Integration token of the CLARTO sub-account
    'location_id' => env('GHL_LOCATION_ID'),     // CLARTO sub-account
    'pipeline_id' => env('GHL_PIPELINE_ID'),     // acquisition pipeline
    'webhook_secret' => env('GHL_WEBHOOK_SECRET'),

    // Custom field ids of the opportunity in GHL (set once the sub-account is reviewed). Key = our name.
    'fields' => [
        'solicitud_id' => env('GHL_FIELD_SOLICITUD_ID'),
        'operacion' => env('GHL_FIELD_OPERACION'),
        'tipo_inmueble' => env('GHL_FIELD_TIPO_INMUEBLE'),
        'municipio' => env('GHL_FIELD_MUNICIPIO'),
        'zona' => env('GHL_FIELD_ZONA'),
        'codigo_postal' => env('GHL_FIELD_CODIGO_POSTAL'),
        'direccion' => env('GHL_FIELD_DIRECCION'),
        'superficie_m2' => env('GHL_FIELD_SUPERFICIE_M2'),
        'dormitorios' => env('GHL_FIELD_DORMITORIOS'),
        'banos' => env('GHL_FIELD_BANOS'),
        'plazo' => env('GHL_FIELD_PLAZO'),
    ],
];
