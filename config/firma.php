<?php

/*
| Formularios and signing (docs/clarto/formularios-firma.md). Everything is OFF by default: the "simulado"
| drivers never touch the network. Credentials only in the server's .env.
*/

return [
    // simulado | docuseal (later: the shared «Firma API», behind the same App\Signing\SignatureProvider interface)
    'driver' => env('FIRMA_DRIVER', 'simulado'),

    // Private disk for sent PDFs, signed PDFs and audit logs (never a public disk).
    'disk' => env('FIRMA_DISK', 'local'),

    'docuseal' => [
        // sandbox = global cloud (fictitious data only); production = EU cloud (the only one with AdES)
        'environment' => env('DOCUSEAL_ENVIRONMENT', 'sandbox'),
        'urls' => ['sandbox' => 'https://api.docuseal.com', 'production' => 'https://api.docuseal.eu'],
        'api_key' => env('DOCUSEAL_ENVIRONMENT', 'sandbox') === 'production' ? env('DOCUSEAL_API_KEY_PROD') : env('DOCUSEAL_API_KEY_SANDBOX'),
        'webhook_secret' => env('DOCUSEAL_ENVIRONMENT', 'sandbox') === 'production' ? env('DOCUSEAL_WEBHOOK_SECRET_PROD') : env('DOCUSEAL_WEBHOOK_SECRET_SANDBOX'),
        'webhook_tolerance' => 300,
        // Hosts the signed documents and audit logs may be downloaded from (anti-SSRF).
        'download_hosts' => ['docuseal.com', 'docuseal.eu'],
        // DocuSeal e-mails the link itself? Off: CLARTO sends it (e-mail from DocuSeal can be turned on later).
        'send_email' => (bool) env('DOCUSEAL_SEND_EMAIL', false),
    ],

    'sms' => [
        // simulado | redtelecom (SmsEasySms white-label by Red Telecom)
        'driver' => env('FIRMA_SMS_DRIVER', 'simulado'),
        'url' => env('REDTELECOM_SMS_URL', 'https://sms.redtelecom.es/Api/rest/message'),
        'username' => env('REDTELECOM_SMS_USER'),
        'password' => env('REDTELECOM_SMS_PASSWORD'),
        'sender' => env('REDTELECOM_SMS_SENDER', 'CLARTO'),   // ≤ 11 alphanumeric
        // Until the owner says otherwise, SMS only go to these numbers (comma separated, international without +).
        'only_test_destinations' => (bool) env('FIRMA_SMS_ONLY_TEST_DESTINATIONS', true),
        'test_destinations' => array_values(array_filter(array_map('trim', explode(',', (string) env('FIRMA_SMS_TEST_DESTINATIONS', ''))))),
    ],
];
