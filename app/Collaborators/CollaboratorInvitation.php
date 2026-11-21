<?php

declare(strict_types=1);

namespace App\Collaborators;

use Filament\Facades\Filament;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Invitation e-mail: one-time link to choose the password on the collaborator panel, in the collaborator's language. */
final class CollaboratorInvitation extends Notification
{
    public function __construct(private readonly string $token, private readonly string $language) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = Filament::getPanel(CollaboratorAccess::PANEL)->getResetPasswordUrl($this->token, $notifiable);
        $hours = max(1, (int) round(config('auth.passwords.users.expire', 60) / 60));
        $t = fn (string $key, array $r = []): string => __('colaboradores.invitation.'.$key, $r, $this->language);

        return (new MailMessage)
            ->subject($t('subject'))
            ->greeting($t('hello', ['name' => $notifiable->name]))
            ->line($t('line'))
            ->action($t('action'), $url)
            ->line($t('expires', ['hours' => $hours, 'email' => $notifiable->email]))
            ->line($t('ignore'))
            ->salutation($t('signature'));
    }
}
