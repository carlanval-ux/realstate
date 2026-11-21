<?php

/*
| «Solicitudes de inmuebles» (docs/clarto/solicitudes.md). Secrets only in the server's .env.
*/

return [
    // Company time zone: deadlines and dates shown in panel and messages.
    'timezone' => env('SOLICITUDES_ZONA_HORARIA', 'Europe/Madrid'),

    // WhatsApp text above this length is sent as ordered blocks "(1/3) …" (WhatsApp truncates very long texts on some phones).
    'whatsapp_block' => (int) env('SOLICITUDES_WHATSAPP_BLOQUE', 3500),

    // Inbound e-mail replies (POST /api/v1/solicitudes/correo-entrante, header X-Solicitudes-Clave), sent by the mail
    // forwarder of the replies mailbox. Empty: the endpoint answers 503 and e-mail replies are not linked automatically.
    'inbound_mail_key' => env('SOLICITUDES_CORREO_ENTRANTE_CLAVE'),

    // Who gets TARMIO tasks when the request has no internal owner (a Liberu user's e-mail). Empty: the team owner.
    'responsable' => env('SOLICITUDES_RESPONSABLE'),

    'signature' => 'Equipo CLARTO',
];
