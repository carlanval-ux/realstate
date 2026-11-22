<?php

return [
    'column' => 'Portales',
    'simulation_off' => 'Envíos reales DESACTIVADOS: las acciones usan una simulación hasta configurar en el servidor las credenciales de :portal.',
    'portal_id' => 'id :portal: :id',
    'unconfirmed' => 'El portal no lo ha confirmado.',
    'withdraw_help' => 'El anuncio se desactiva en :portal. El inmueble se conserva igual en el panel.',
    'actions' => ['publish' => 'Publicar en :portal'],
    'filters' => [
        'published' => 'Publicado en :portal',
        'not_published' => 'No publicado en :portal',
        'error' => 'Con error en :portal',
    ],
    'bulk' => [
        'send' => 'Enviar a :portal…',
        'withdraw' => 'Retirar de :portal…',
        'send_confirm' => 'Se enviarán a :portal los :n inmuebles seleccionados (alta o actualización). No se envía nada más.',
        'send_refused' => ':n son de colaboradores que no permiten difundirlos en portales: no se enviarán.',
        'withdraw_confirm' => 'Se retirarán de :portal los :n inmuebles seleccionados que estén publicados allí. Los inmuebles no cambian en el panel.',
        'done_send' => ':portal: :n enviados',
        'done_withdraw' => ':portal: :n retirados',
        'not_done' => ':n no se han podido procesar:',
    ],
    'refusals' => [
        'collaborator' => 'Inmueble de :name: su ficha no permite difundirlo en portales externos («Puede difundirse en portales externos»).',
        'authorization' => 'Inmueble de :name: falta la fecha de la autorización por escrito del colaborador.',
    ],
];
