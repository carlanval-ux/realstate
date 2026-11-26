<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Notifications\Notification;

/**
 * Sign-in pages of the collaborator panel: «tú» in Spanish, like the rest of that panel (Filament's Spanish texts,
 * shared with the staff panels, say «usted»). Other languages keep Filament's wording.
 */
trait SpeaksInformally
{
    /** The informal Spanish text (lang/es/colaboradores.php «acceso»), or null in any other language. */
    protected static function informal(string $key, array $replace = []): ?string
    {
        return app()->getLocale() === 'es' ? __('colaboradores.acceso.'.$key, $replace) : null;
    }

    protected function getRateLimitedNotification(TooManyRequestsException $exception): ?Notification
    {
        $notification = parent::getRateLimitedNotification($exception);
        $text = self::informal('throttled', ['seconds' => $exception->secondsUntilAvailable]);

        return $text === null ? $notification : $notification?->title($text)->body(null);
    }
}
