<?php

namespace App\Models;

use Filament\Panel;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class User extends \Liberu\RealEstate\Application\Models\User implements HasLocalePreference
{
    protected static function booted(): void
    {
        parent::booted();

        static::creating(function (self $user): void {
            // New accounts start in the installation's language (es for CLARTO), not the column default "en".
            if (! array_key_exists('locale', $user->getAttributes())) {
                $user->locale = config('app.locale');
            }
            // Accounts created by an administrator get an unknown random password: the person chooses theirs
            // from the e-mailed access link (App\Auth\AccessLink).
            if (blank($user->getAttributes()['password'] ?? null)) {
                $user->password = Hash::make(Str::random(64));
            }
        });
    }

    /** E-mails (access links, notifications) go out in the user's own language. */
    public function preferredLocale(): ?string
    {
        return $this->locale ?: config('app.locale');
    }

    /** A disabled account cannot sign in to any panel (Filament checks this at login and on every request). */
    public function canAccessPanel(Panel $panel): bool
    {
        if (filled($this->disabled_at)) {
            return false;
        }
        // Colaboradores: only their own panel, only while enabled; never /admin or /app (App\Collaborators\CollaboratorAccess).
        if ($panel->getId() === \App\Collaborators\CollaboratorAccess::PANEL) {
            return \App\Collaborators\Collaborations::enabled() && \App\Collaborators\CollaboratorAccess::collaboratorFor($this) !== null;
        }

        return ! \App\Collaborators\CollaboratorAccess::isCollaborator($this) && parent::canAccessPanel($panel);
    }
}
