<?php

namespace App\Models;

use Filament\Panel;

class User extends \Liberu\RealEstate\Application\Models\User
{
    /** New accounts start in the installation's language (es for CLARTO), not the column default "en". */
    protected static function booted(): void
    {
        parent::booted();

        static::creating(function (self $user): void {
            if (! array_key_exists('locale', $user->getAttributes())) {
                $user->locale = config('app.locale');
            }
        });
    }

    /** A disabled account cannot sign in to any panel (Filament checks this at login and on every request). */
    public function canAccessPanel(Panel $panel): bool
    {
        return blank($this->disabled_at) && parent::canAccessPanel($panel);
    }
}
