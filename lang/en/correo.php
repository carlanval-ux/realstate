<?php

return [
    'menu' => 'Inbound mail',
    'vacio' => 'No messages in this tray',
    'sin_asunto' => '(no subject)',
    'sugerido' => 'Suggested: :c',
    'confirmar_texto' => 'It will be routed as «:c».',
    'ver_html' => 'Show the formatted version',
    'adjunto_omitido' => 'not stored (over 20 MB)',
    'bandeja' => ['revisar' => 'To review', 'entrada' => 'Inbox', 'enrutado' => 'Routed', 'archivo' => 'Archive'],
    'categoria' => [
        'solicitud' => 'Reply to a property request', 'propietario' => 'Owner / valuation', 'colaborador' => 'Collaborator',
        'cliente' => 'Client with a file', 'consulta' => 'New enquiry', 'proveedor' => 'Supplier / invoice',
        'administracion' => 'Official notice', 'publicidad' => 'Advertising / spam', 'otros' => 'Other',
        'automatico' => 'Automatic (receipt, bounce, out of office)',
    ],
    'area' => [
        'inmobiliaria' => 'Real estate', 'alquiler' => 'Lettings', 'gestoria' => 'Administrative services', 'mercantil' => 'Corporate',
        'extranjeria' => 'Immigration', 'fiscal' => 'Tax', 'otros' => 'Other', 'proveedor' => 'Suppliers / invoices', 'administracion' => 'Official notices',
    ],
    'metodo' => ['hilo' => 'Reply to our message', 'referencia' => 'Reference', 'regla' => 'Rule', 'remitente' => 'Known sender', 'cabeceras' => 'Headers', 'ia' => 'AI', 'manual' => 'Team'],
    'motivo' => [
        'conflicto' => 'Conflicting signals', 'sin_ia' => 'No rule and AI is off', 'ia_no_disponible' => 'The AI did not answer',
        'ia_sin_respuesta' => 'The AI gave no valid category', 'sin_expediente' => 'No matching record or contact',
        'baja_confianza' => 'Low confidence', 'solicitud_sin_vincular' => 'Could not be linked to a request',
        'solicitudes_desactivado' => 'The Property requests module is off', 'error' => 'Processing error',
    ],
    'ruta' => [
        'solicitud_vinculada' => 'Linked to the request', 'solicitud_pendiente' => 'In «Replies to link»', 'solicitud_acuse' => 'Receipt on the request',
        'solicitud_fuera_plazo' => 'Request closed (kept in history)', 'solicitud_duplicada' => 'Already received', 'cronologia' => 'In the contact timeline',
        'entrada' => 'Inbox', 'archivo' => 'Archived',
    ],
    'campo' => [
        'recibido' => 'Received', 'de' => 'From', 'para' => 'To', 'fecha' => 'Date', 'asunto' => 'Subject', 'categoria' => 'Category', 'area' => 'Area',
        'como' => 'Classified by', 'contacto' => 'Contact', 'regla' => 'Remember for next messages', 'adjuntos' => 'Attachments',
    ],
    'regla' => ['ninguna' => 'Do not remember', 'remitente' => 'This sender', 'dominio' => 'Their whole domain'],
    'ayuda' => [
        'contacto' => 'Empty: the contact with this e-mail address.',
        'regla' => 'Next messages from this sender (or domain) will be classified like this without asking.',
    ],
    'accion' => ['ver' => 'View', 'confirmar' => 'Confirm', 'clasificar' => 'Classify', 'reclasificar' => 'Reclassify', 'atendido' => 'Handled'],
    'aviso' => ['hecho' => 'Done', 'guardado' => 'Settings saved'],
    'error' => [
        'sin_sugerencia' => 'There is no suggestion to confirm.', 'categoria' => 'Invalid category or area.', 'contacto' => 'That contact belongs to another company.',
        'ya_enrutado' => 'It is already in a request or a contact timeline.', 'dominio_publico' => 'Public e-mail domain: the rule can only be for this sender.',
        'otra_empresa' => 'Another company already reads this mailbox: only one can poll it.',
    ],
    'modulo' => ['falta_buzon' => 'Mailbox missing: the server administrator must run scripts/set-imap.sh with the info@ password.'],
    'sondeo' => [
        'ok' => 'Read: :n new messages', 'error' => 'Error reading the mailbox: :e', 'esperando' => 'Not due yet (interval)', 'en_curso' => 'A read is already running',
        'sin_buzon' => 'The mailbox is not configured', 'sin_sondeo' => 'Polling is off', 'varias_empresas' => 'Several companies have polling on',
        'modulo_desactivado' => 'The Inbound mail module is off',
    ],
    'ajustes' => [
        'titulo' => 'Inbound mail settings', 'buzon' => 'Mailbox', 'funcionamiento' => 'Operation',
        'buzon_ok' => 'Mailbox :buzon on :host (password stored on the server only).',
        'ultimo' => 'Last read: :fecha · :n new', 'nunca' => 'The mailbox has not been read yet.', 'error' => 'last error: :e',
        'solo_lectura' => 'Read-only: the panel never marks as read, moves, deletes or replies to messages in the mailbox. The first read starts with messages arriving from then on.',
        'polling' => 'Read the mailbox', 'polling_ayuda' => 'Only one company can have it on.', 'interval' => 'Every (minutes)',
        'ai' => 'Classify with AI what rules cannot place', 'ai_ayuda' => 'The AI only gets the subject and a short excerpt without personal data; never attachments.',
        'retention' => 'Keep copies (days)', 'retention_ayuda' => 'Default 24 months, as in the privacy policy. Advertising and automatic: 30 days.',
        'responsables' => 'Owner of each area', 'responsables_ayuda' => 'E-mail of the TARMIO user who gets the task of each inbox item. Empty: «Other», or the company owner.',
        'probar' => 'Test connection', 'probar_ok' => 'Connected: :n messages in the inbox (nothing changed).', 'probar_error' => 'Could not connect: :e',
        'sondear' => 'Read now',
    ],
];
