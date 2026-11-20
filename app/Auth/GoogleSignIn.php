<?php

declare(strict_types=1);

namespace App\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use JoelButcher\Socialstream\Actions\AuthenticateOAuthCallback;
use JoelButcher\Socialstream\Contracts\OAuthFailedResponse;
use JoelButcher\Socialstream\Contracts\OAuthProviderLinkFailedResponse;
use JoelButcher\Socialstream\Contracts\SocialstreamResponse;
use JoelButcher\Socialstream\Events\OAuthFailed;
use JoelButcher\Socialstream\Socialstream;
use Laravel\Socialite\Contracts\User as ProviderUser;

/**
 * "Continue with Google" for an internal tool (replaces Socialstream's default callback):
 *
 * - Sign-in only through a Google account already linked to an existing, active CLARTO user, recognised by
 *   Google's stable id (sub). Never by e-mail alone, never creating an account.
 * - Linking happens while signed in with the password (profile → Connected accounts), and only with a
 *   Google account whose e-mail is verified by Google and equal to the account's e-mail.
 * - The login itself goes through Socialstream's pipeline, which keeps Fortify two-factor authentication;
 *   panel, team and permission checks apply afterwards exactly as for a password login.
 */
final class GoogleSignIn extends AuthenticateOAuthCallback
{
    public function authenticate(string $provider, ProviderUser $providerAccount): SocialstreamResponse|RedirectResponse
    {
        if ($provider !== 'google') {
            return $this->deny(__('This sign-in method is not available.'), $provider, $providerAccount);
        }

        if (($user = auth()->user()) !== null) {
            if (! self::verifiedEmail($providerAccount) || Str::lower((string) $providerAccount->getEmail()) !== Str::lower((string) $user->email)) {
                Session::flash('flash.banner', __('Google can only be connected with a verified Google e-mail equal to your account e-mail (:email).', ['email' => $user->email]));
                Session::flash('flash.bannerStyle', 'danger');

                return app(OAuthProviderLinkFailedResponse::class);
            }

            return $this->link($user, $provider, $providerAccount);
        }

        $account = Socialstream::findConnectedAccountForProviderAndId($provider, $providerAccount->getId());
        $user = $account?->user;

        if ($user === null) {
            return $this->deny(__('This Google account is not connected to any CLARTO user. Sign in with your password and connect Google from your profile.'), $provider, $providerAccount);
        }
        if (self::disabled($user)) {
            return $this->deny(__('This account is disabled. Contact an administrator.'), $provider, $providerAccount);
        }

        return $this->login(user: $user, account: $account, provider: $provider, providerAccount: $providerAccount);
    }

    public static function verifiedEmail(ProviderUser $account): bool
    {
        $raw = method_exists($account, 'getRaw') ? (array) $account->getRaw() : [];

        return filter_var($raw['email_verified'] ?? $raw['verified_email'] ?? false, FILTER_VALIDATE_BOOLEAN) && filled($account->getEmail());
    }

    public static function disabled(Authenticatable $user): bool
    {
        return filled($user->disabled_at ?? null);
    }

    private function deny(string $error, string $provider, ProviderUser $providerAccount): OAuthFailedResponse
    {
        event(new OAuthFailed($provider, $providerAccount));
        Session::flash('errors', (new ViewErrorBag)->put('default', new MessageBag(['socialstream' => $error])));

        return app(OAuthFailedResponse::class);
    }
}
