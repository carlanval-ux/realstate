<?php

/*
| TARMIO (the team's task manager). Liberu creates/updates one TARMIO task per pending action and keeps its id in
| clarto_tarmio_tasks. Credentials only in the server's .env (see docs/clarto/tarmio.md).
| Driver "simulado" (default) sends nothing and invents ids; "http" talks to TARMIO's Supabase REST API.
*/

return [
    'driver' => env('TARMIO_DRIVER', 'simulado'),                  // simulado | http
    'url' => rtrim((string) env('TARMIO_SUPABASE_URL'), '/'),        // https://<ref>.supabase.co
    'api_key' => env('TARMIO_SUPABASE_KEY'),                         // TARMIO's publishable (anon) key
    'user' => env('TARMIO_USUARIO'),                                 // integration user, role manager in the CLARTO company
    'password' => env('TARMIO_PASSWORD'),
    'organization_id' => env('TARMIO_ORGANIZATION_ID'),              // CLARTO company in TARMIO
    'project_id' => env('TARMIO_PROJECT_ID'),                        // optional: project for Liberu tasks (else company inbox)

    // Who gets the "new owner from GHL" task (e-mail of a Liberu user that also exists in TARMIO). Empty: team owner.
    'responsable_captacion' => env('TARMIO_RESPONSABLE_CAPTACION'),
];
