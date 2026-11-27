<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Collaborators\CollaboratorAccess;
use Filament\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Filament's «forgotten password» e-mail; to an external collaborator it uses the collaborator panel's own wording
 * (lang/{es,en,tr}/colaboradores.php «acceso»: «tú» in Spanish, «şifre» in Turkish, expiry in hours), bound in
 * ClartoCollaboratorsServiceProvider. Everyone else gets Filament's e-mail unchanged.
 */
final class ClartoResetPassword extends ResetPassword
{
    public function toMail($notifiable): MailMessage
    {
        if (! in_array(app()->getLocale(), \App\Collaborators\Collaborator::LOCALES, true) || ! CollaboratorAccess::isCollaborator($notifiable)) {
            return parent::toMail($notifiable);
        }
        $minutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);
        $expiry = $minutes % 60 === 0
            ? trans_choice('colaboradores.acceso.mail_expiry_hours', intdiv($minutes, 60), ['count' => intdiv($minutes, 60)])
            : __('colaboradores.acceso.mail_expiry', ['count' => $minutes]);

        return (new MailMessage)
            ->subject(__('colaboradores.acceso.mail_subject'))
            ->line(__('colaboradores.acceso.mail_intro'))
            ->action(__('colaboradores.acceso.mail_action'), $this->url)
            ->line($expiry)
            ->line(__('colaboradores.acceso.mail_ignore'));
    }
}
