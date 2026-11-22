<?php

return [
    'column' => 'Portals',
    'simulation_off' => 'Real sends are OFF: actions run against a simulation until :portal credentials are configured on the server.',
    'portal_id' => ':portal id: :id',
    'unconfirmed' => 'The portal has not confirmed it.',
    'withdraw_help' => 'The listing is deactivated on :portal. The property is kept in the panel unchanged.',
    'actions' => ['publish' => 'Publish on :portal'],
    'filters' => [
        'published' => 'Published on :portal',
        'not_published' => 'Not published on :portal',
        'error' => 'With error on :portal',
    ],
    'bulk' => [
        'send' => 'Send to :portal…',
        'withdraw' => 'Withdraw from :portal…',
        'send_confirm' => 'The :n selected properties will be sent to :portal (create or update). Nothing else is sent.',
        'send_refused' => ':n belong to collaborators who do not allow portals: they will not be sent.',
        'withdraw_confirm' => 'The :n selected properties published on :portal will be withdrawn there. The properties do not change in the panel.',
        'done_send' => ':portal: :n sent',
        'done_withdraw' => ':portal: :n withdrawn',
        'not_done' => ':n could not be processed:',
    ],
    'refusals' => [
        'collaborator' => ':name\'s property: their record does not allow external portals («Can be published on external portals»).',
        'authorization' => ':name\'s property: the collaborator\'s written authorization date is missing.',
    ],
];
