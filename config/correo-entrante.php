<?php

/*
| «Correo entrante» (docs/clarto/correo-entrante.md): the company mailbox (info@clarto.es) read by IMAP, STRICTLY
| read-only. The password lives only in the server's .env (scripts/set-imap.sh in the deploy bundle).
*/

return [
    'host' => env('MAIL_IN_HOST', 'imap.hostinger.com'),
    'port' => (int) env('MAIL_IN_PORT', 993),
    'username' => env('MAIL_IN_USERNAME'),
    'password' => env('MAIL_IN_PASSWORD'),
    'folder' => env('MAIL_IN_FOLDER', 'INBOX'),

    // Messages fetched per poll (the rest wait for the next one).
    'batch' => (int) env('MAIL_IN_LOTE', 50),
];
