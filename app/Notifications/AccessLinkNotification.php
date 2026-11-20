<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** E-mail with a one-time link to create (invitation) or reset the password of a panel account. */
final class AccessLinkNotification extends Notification
{
    public function __construct(private readonly string $token, private readonly bool $invitation) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = route('password.reset', ['token' => $this->token, 'email' => $notifiable->getEmailForPasswordReset()]);
        $hours = (int) round(config('auth.passwords.users.expire', 60) / 60);
        $app = (string) config('app.name');

        return (new MailMessage)
            ->subject($this->invitation ? __('Your access to :app', ['app' => $app]) : __('Create a new password for :app', ['app' => $app]))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line($this->invitation
                ? __('An account has been created for you in :app. Choose your password to sign in.', ['app' => $app])
                : __('An administrator has sent you a link to choose a new password for :app.', ['app' => $app]))
            ->action(__('Create my password'), $url)
            ->line(__('The link can be used once and expires in :hours hours. Your account is :email.', ['hours' => $hours, 'email' => $notifiable->email]))
            ->line(__('If you did not expect this e-mail, you can ignore it.'));
    }
}
