<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Collaborators\CollaboratorAccess;
use Filament\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Filament's «forgotten password» e-mail; to an external collaborator in Spanish it says «tú», like the rest of their
 * panel (bound in ClartoCollaboratorsServiceProvider). Everyone else gets Filament's e-mail unchanged.
 */
final class ClartoResetPassword extends ResetPassword
{
    public function toMail($notifiable): MailMessage
    {
        if (app()->getLocale() !== 'es' || ! CollaboratorAccess::isCollaborator($notifiable)) {
            return parent::toMail($notifiable);
        }
        $minutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);

        return (new MailMessage)
            ->subject(__('colaboradores.acceso.mail_subject'))
            ->line(__('colaboradores.acceso.mail_intro'))
            ->action(__('colaboradores.acceso.mail_action'), $this->url)
            ->line(__('colaboradores.acceso.mail_expiry', ['count' => $minutes]))
            ->line(__('colaboradores.acceso.mail_ignore'));
    }
}
