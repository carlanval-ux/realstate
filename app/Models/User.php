<?php

namespace App\Models;

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
}
