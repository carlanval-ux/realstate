<?php

/*
| Valoraciones automáticas (docs/clarto/valoraciones.md). Secrets only in the server's .env or in the panel
| (Configuración de la plataforma → Valoraciones automáticas, stored encrypted). Nothing here is a credential.
*/

return [
    // Shared key the website's server sends in X-Valoraciones-Clave. Without it the API is closed.
    'api_key' => env('VALORACIONES_API_KEY'),

    // Days a signed report link stays valid (the web and GHL get it).
    'informe_dias' => (int) env('VALORACIONES_INFORME_DIAS', 30),

    // Requests per minute and visitor IP (the web forwards it in X-Cliente-Ip) and for all visitors together.
    'limite_por_ip' => (int) env('VALORACIONES_LIMITE_IP', 20),
    'limite_global' => (int) env('VALORACIONES_LIMITE_GLOBAL', 300),

    'catastro' => [
        'url' => rtrim((string) env('CATASTRO_URL', 'https://ovc.catastro.meh.es/OVCServWeb/OVCWcfCallejero'), '/'),
        'timeout' => (int) env('CATASTRO_TIMEOUT', 8),
        'cache_horas' => (int) env('CATASTRO_CACHE_HORAS', 168),
    ],

    /*
    | External AVM providers. driver: off | simulado | http. Defaults here; the panel can override them (encrypted).
    | Their APIs are not publicly documented: the HTTP driver sends our fields as JSON and reads the answer through
    | the *_path settings, to be confirmed when the contract and documentation arrive (see the doc).
    */
    'proveedores' => [
        'idealista' => [
            'nombre' => 'idealista/data',
            'driver' => env('VALORACIONES_IDEALISTA_DRIVER', 'off'),
            'url' => env('VALORACIONES_IDEALISTA_URL'),
            'token_url' => env('VALORACIONES_IDEALISTA_TOKEN_URL'),   // OAuth2 client credentials, if it applies
            'client_id' => env('VALORACIONES_IDEALISTA_CLIENT_ID'),
            'secret' => env('VALORACIONES_IDEALISTA_SECRET'),
        ],
        'tinsa' => [
            'nombre' => 'Tinsa AVM',
            'driver' => env('VALORACIONES_TINSA_DRIVER', 'off'),
            'url' => env('VALORACIONES_TINSA_URL'),
            'token_url' => env('VALORACIONES_TINSA_TOKEN_URL'),
            'client_id' => env('VALORACIONES_TINSA_CLIENT_ID'),
            'secret' => env('VALORACIONES_TINSA_SECRET'),
        ],
    ],
    'proveedor_timeout' => (int) env('VALORACIONES_PROVEEDOR_TIMEOUT', 12),

    // Delivery of the result to the person who asked (queued after the API answers): e-mail with the PDF + WhatsApp link.
    'envio' => [
        'email' => (bool) env('VALORACIONES_ENVIO_EMAIL', true),
        'whatsapp' => (bool) env('VALORACIONES_ENVIO_WHATSAPP', true),
    ],

    // Price sources whose commercial reuse needs a licence (Notariado, portals): never in production. Internal tests only.
    'fuentes_pendientes_licencia' => (bool) env('VALORACIONES_FUENTES_PENDIENTES_LICENCIA', false),

    // Résumé written by the AI (App\Ai\AiClient) from the computed result; a template when it fails or is off.
    'resumen_ia' => (bool) env('VALORACIONES_RESUMEN_IA', true),

    // Booking links of the «Visita de valoración» calendars in GHL, one per office (the panel setting wins).
    'citas' => [
        'cita_valencia' => env('VALORACIONES_CITA_VALENCIA'),
        'cita_sagunto' => env('VALORACIONES_CITA_SAGUNTO'),
        'cita_bellreguard' => env('VALORACIONES_CITA_BELLREGUARD'),
    ],

    /*
    | WhatsApp nurture sent by Liberu through WAHA (GHL strategy annex B.3 v2 §5): window (Europe/Madrid), rate per line
    | and non-working days besides weekends. Texts: App\Acquisition\NurturePlan::TEXTS, overridable here by code (wa1…).
    */
    'nutricion_whatsapp' => [
        'zona' => 'Europe/Madrid',
        'ventana' => [env('NUTRICION_WA_DESDE', '17:30'), env('NUTRICION_WA_HASTA', '18:30')],
        'max_por_hora' => (int) env('NUTRICION_WA_MAX_HORA', 30),
        // ponytail: fixed list, to be checked against BOE/DOGV and the three town halls (local holidays missing); an
        // admin page if it changes often. Includes 24/12 and 31/12 (B.3 v2 §1).
        'festivos' => array_filter(explode(',', (string) env('NUTRICION_WA_FESTIVOS', '2026-12-08,2026-12-24,2026-12-25,2026-12-31,2027-01-01,2027-01-06,2027-03-19,2027-03-26,2027-03-29,2027-10-12,2027-11-01,2027-12-06,2027-12-08,2027-12-24,2027-12-31'))),
        'textos' => [],
        // Codes NOT sent until CLARTO's legal team approves their text (tax content). Empty the list to release them.
        'revision_legal' => array_filter(explode(',', (string) env('NUTRICION_WA_REVISION_LEGAL', 'wa2,wa_t1'))),
    ],

    // GoHighLevel: the opportunity of each request (credentials and pipeline in config/ghl.php).
    'ghl' => [
        'stage_id' => env('GHL_PIPELINE_STAGE_ID'),
        // Custom fields of the opportunity for the valuation (besides config ghl.fields). Key = our name.
        'fields' => [
            'referencia_valoracion' => env('GHL_FIELD_REFERENCIA_VALORACION'),
            'estado_valoracion' => env('GHL_FIELD_ESTADO_VALORACION'),
            'precio_estimado' => env('GHL_FIELD_PRECIO_ESTIMADO'),
            'intervalo' => env('GHL_FIELD_INTERVALO'),
            'suficiencia' => env('GHL_FIELD_SUFICIENCIA'),
            'version_calculo' => env('GHL_FIELD_VERSION_CALCULO'),
            'informe_url' => env('GHL_FIELD_INFORME_URL'),
            'datos_aportados' => env('GHL_FIELD_DATOS_APORTADOS'),
        ],
    ],
];
