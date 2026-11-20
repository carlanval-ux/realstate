<?php

use JoelButcher\Socialstream\Features;
use JoelButcher\Socialstream\Providers;

return [
    'guard' => 'web', // used if Fortify is not installed
    'middleware' => ['web'],
    'prompt' => 'or',
    'providers' => array_values(array_filter([
        // CLARTO: Google only when its OAuth client (separate from other projects) is configured on the server.
        env('GOOGLE_CLIENT_ID') && env('GOOGLE_CLIENT_SECRET') ? Providers::google('Continuar con Google') : null,
    ])),
    'features' => [
        // Features::generateMissingEmails(),
        // Features::createAccountOnFirstLogin(),
        // Features::globalLogin(),
        // Features::authExistingUnlinkedUsers(),
        Features::rememberSession(),
        Features::providerAvatars(),
        Features::refreshOAuthTokens(),
    ],
    'home' => '/dashboard',
    'redirects' => [
        'login' => '/dashboard',
        'register' => '/dashboard',
        'login-failed' => '/admin/login',
        'registration-failed' => '/register',
        'provider-linked' => '/user/profile',
        'provider-link-failed' => '/user/profile',
    ],
];
