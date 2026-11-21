<?php

/*
| WhatsApp through WAHA (devlikeapro/waha), an internal service of the deploy bundle (no public port, no Caddy route).
| The panel calls WAHA with X-Api-Key; WAHA calls the panel back on its internal URL with an HMAC SHA-512 signature.
| Credentials only in the server's .env (see docs/clarto/whatsapp.md).
*/

return [
    'url' => rtrim((string) env('WAHA_URL', 'http://waha:3000'), '/'),
    'api_key' => env('WAHA_API_KEY'),
    // Where WAHA delivers events: liberu-app on the internal network, never the public host.
    'webhook_url' => env('WAHA_WEBHOOK_URL', 'http://liberu-app/api/v1/whatsapp/webhook'),
    'webhook_hmac_key' => env('WAHA_WEBHOOK_HMAC_KEY'),
    // WAHA session per team. WAHA Core only runs "default" (one line); with WAHA Plus use e.g. "team-{team}".
    'session' => env('WAHA_SESSION', 'default'),
    'default_country_code' => env('WHATSAPP_DEFAULT_COUNTRY_CODE', '34'),
];
