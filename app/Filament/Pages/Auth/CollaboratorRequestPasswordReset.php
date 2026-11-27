<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use Filament\Auth\Pages\PasswordReset\RequestPasswordReset;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Support\Htmlable;

/** «¿Has olvidado tu contraseña?» of /colaboradores («tú» in Spanish). */
final class CollaboratorRequestPasswordReset extends RequestPasswordReset
{
    use SpeaksInformally;

    public function getHeading(): string|Htmlable|null
    {
        return self::informal('forgot') ?? parent::getHeading();
    }

    protected function getSentNotification(string $status): ?Notification
    {
        $notification = parent::getSentNotification($status);
        $body = self::informal('reset_sent_body');

        return $body === null || $notification === null || $notification->getBody() === null ? $notification : $notification->body($body);
    }
}
