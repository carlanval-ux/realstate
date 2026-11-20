<?php

declare(strict_types=1);

namespace App\Auth;

use App\Notifications\AccessLinkNotification;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Password;
use Throwable;

/**
 * Administrators never set other people's passwords: the user receives a one-time link by e-mail to create it
 * (when the account is created, or on demand with "Send access link").
 */
final class AccessLink
{
    /** @return array{ok: bool, message: string} */
    public static function send(Model&CanResetPassword $user, bool $invitation = false): array
    {
        if (filled($user->disabled_at ?? null)) {
            return ['ok' => false, 'message' => __('This account is disabled. Enable it before sending an access link.')];
        }

        try {
            $status = Password::broker()->sendResetLink(
                ['email' => $user->getEmailForPasswordReset()],
                fn ($u, string $token) => $u->notify(new AccessLinkNotification($token, $invitation)),
            );
        } catch (Throwable $e) {
            report($e);

            return ['ok' => false, 'message' => __('The e-mail could not be sent (:reason). Check the mail settings of the server.', ['reason' => class_basename($e)])];
        }

        return $status === Password::RESET_LINK_SENT
            ? ['ok' => true, 'message' => __('Access link sent to :email.', ['email' => $user->getEmailForPasswordReset()])]
            : ['ok' => false, 'message' => __($status)];
    }

    public static function notify(array $result): void
    {
        $n = Notification::make()->title($result['message']);
        $result['ok'] ? $n->success() : $n->danger()->persistent();
        $n->send();
    }

    /** "Send access link" for the user record and the users table. */
    public static function action(): Action
    {
        return Action::make('send_access_link')
            ->label(__('Send access link'))
            ->icon('heroicon-o-envelope')
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription(__('The user receives an e-mail with a one-time link to choose a new password. The current password stops working once the new one is set.'))
            ->action(fn (Model&CanResetPassword $record) => self::notify(self::send($record)));
    }
}
