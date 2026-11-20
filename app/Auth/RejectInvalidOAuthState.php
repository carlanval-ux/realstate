<?php

declare(strict_types=1);

namespace App\Auth;

use Illuminate\Support\Facades\Session;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use JoelButcher\Socialstream\Contracts\HandlesInvalidState;
use Laravel\Socialite\Two\InvalidStateException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

/** An OAuth callback whose state does not match the session (expired, replayed or forged) goes back to the login. */
final class RejectInvalidOAuthState implements HandlesInvalidState
{
    public function handle(InvalidStateException $exception): Response|RedirectResponse
    {
        Session::flash('errors', (new ViewErrorBag)->put('default', new MessageBag([
            'socialstream' => __('The Google sign-in expired or is not valid. Please try again.'),
        ])));

        return redirect('/admin/login');
    }
}
