<?php

/*
 * External portals the staff can send properties to, one by one or by selecting them in the property list.
 * Nothing is ever sent automatically. Adding a portal = a class implementing App\Portals\PortalConnector + an entry
 * here (key = PortalListing.portal). Each connector has its own config (config/pisos.php) and its own simulation.
 */
return [
    'pisos' => [
        'nombre' => 'pisos.com',
        'conector' => \App\Portals\Pisos\PisosConnector::class,
        'modulo' => \App\Modules\ClartoModules::PISOS,   // the CLARTO module that switches it on/off (null: always on)
    ],
];
