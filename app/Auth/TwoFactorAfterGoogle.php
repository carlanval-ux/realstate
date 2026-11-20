<?php

declare(strict_types=1);

namespace App\Auth;

use Illuminate\Auth\Events\Failed;
use JoelButcher\Socialstream\Actions\RedirectIfTwoFactorAuthenticatable;
use JoelButcher\Socialstream\Socialstream;

/**
 * Keeps Fortify's two-factor challenge after "Continue with Google", but identifies the user by Google's stable
 * id (sub) like GoogleSignIn does. Socialstream's own version looks the account up by e-mail, which breaks
 * when the Google e-mail changes and is not the identity we trust.
 */
final class TwoFactorAfterGoogle extends RedirectIfTwoFactorAuthenticatable
{
    protected function validateCredentials($request)
    {
        $provider = $request->route('provider');
        if (! is_string($provider) || $provider === '') {
            return parent::validateCredentials($request);
        }

        $social = $this->resolver->resolve($provider);
        $account = Socialstream::findConnectedAccountForProviderAndId($provider, (string) $social->getId());

        if ($account?->user === null) {
            event(new Failed($this->guard?->name ?? config('fortify.guard'), user: null, credentials: ['provider' => $provider]));
            $this->throwFailedAuthenticationException($request);
        }

        return $account->user;
    }
}
