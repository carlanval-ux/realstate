<?php

/*
| GoHighLevel (owner acquisition, docs/clarto/captacion-ghl.md). Liberu creates the contact and one opportunity per
| property in the acquisition pipeline, reports the delivery of the valuation, sends the WhatsApp messages GHL asks for
| and forwards WhatsApp replies; when an opportunity is won GHL calls Liberu's webhook and Liberu opens the file
| (owner + draft property + mandate + TARMIO task). Credentials only in the server's .env.
*/

return [
    'api_url' => rtrim((string) env('GHL_API_URL', 'https://services.leadconnectorhq.com'), '/'),
    'api_version' => env('GHL_API_VERSION', '2021-07-28'),
    'token' => env('GHL_API_TOKEN'),            // Private Integration token of the CLARTO sub-account
    'location_id' => env('GHL_LOCATION_ID'),     // CLARTO sub-account
    'pipeline_id' => env('GHL_PIPELINE_ID'),     // «CLARTO · Captación de propietarios»
    'stage_entregada' => env('GHL_STAGE_ENTREGADA'),   // stage «Valoración entregada» (L-3)
    'webhook_secret' => env('GHL_WEBHOOK_SECRET'),

    // Requests created before this date (Y-m-d) are synced only by hand, with origen = web_valoracion_previa (L-7).
    'alta_desde' => env('GHL_ALTA_DESDE'),

    /*
    | Custom fields are found BY KEY (opportunity.<key>, contact.<key>: annex A of the GHL strategy) through
    | GET /locations/{id}/customFields, cached for some hours. These maps only OVERRIDE a key with an explicit field id
    | (GHL_FIELD_<KEY> / GHL_CONTACT_FIELD_<KEY>), e.g. when GHL generated a different key. Key = annex key.
    */
    'fields' => array_filter([
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
        'plazo_venta' => env('GHL_FIELD_PLAZO'),
    ]),

    'contact_fields' => array_filter([
        'nif' => env('GHL_CONTACT_FIELD_NIF'),
        'idioma' => env('GHL_CONTACT_FIELD_IDIOMA'),
        'canal_preferido' => env('GHL_CONTACT_FIELD_CANAL'),
    ]),

    // Hours the key → id map of custom fields is cached.
    'fields_cache_hours' => (int) env('GHL_FIELDS_CACHE_HORAS', 6),
];
