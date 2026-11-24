<?php

declare(strict_types=1);

namespace App\Documents;

use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notices of «Documentación de la empresa» (pending approval, approvals reached — with or without conditions —,
 * rejected by the votes against, expiry): bell (Filament database
 * notification) and e-mail through the panel's own mailer. Texts are built for each recipient in their language
 * (User::preferredLocale). The e-mail carries no attachment and no document data beyond the title, folder, who asks
 * and the approvals needed; its button opens the record in the panel, which needs a session.
 */
final class CompanyDocumentNotice extends Notification
{
    /** @param array<string, string|int> $params */
    public function __construct(public readonly string $kind, public readonly array $params, public readonly string $url) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return filled($notifiable->email ?? null) ? ['database', 'mail'] : ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()->title($this->title())->body($this->body())->icon('heroicon-o-document-text')
            ->color(match ($this->kind) {
                'threshold', 'threshold_conditions' => 'success',
                'rejected' => 'danger',
                default => 'warning',
            })
            ->actions([Action::make('open')->label(__('documentacion.notice.open'))->markAsRead()->url($this->url)])
            ->getDatabaseMessage();
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())->subject($this->title())->line($this->body())
            ->action(__('documentacion.notice.open_panel'), $this->url)
            ->line(__('documentacion.notice.mail_footer'));
    }

    private function title(): string
    {
        return __("documentacion.notice.{$this->kind}", $this->params);
    }

    private function body(): string
    {
        return __("documentacion.notice.{$this->kind}_body", $this->params);
    }
}
