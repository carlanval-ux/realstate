<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use Filament\Auth\Pages\PasswordReset\ResetPassword;
use Illuminate\Contracts\Support\Htmlable;

/** First password (invitation link) and new password of /colaboradores («tú» in Spanish). */
final class CollaboratorResetPassword extends ResetPassword
{
    use SpeaksInformally;

    public function getTitle(): string|Htmlable
    {
        return self::informal('reset_heading') ?? parent::getTitle();
    }

    public function getHeading(): string|Htmlable|null
    {
        return self::informal('reset_heading') ?? parent::getHeading();
    }
}
