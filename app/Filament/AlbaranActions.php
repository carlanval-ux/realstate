<?php

declare(strict_types=1);

namespace App\Filament;

use App\Albaranes\Albaran;
use App\Albaranes\AlbaranPdf;
use App\Albaranes\BillingConnector;
use Filament\Actions\Action;
use App\Filament\Resources\AlbaranResource;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Actions on an albarán, shared by the list, the edit page and the owner 360 tab. */
final class AlbaranActions
{
    public static function send(): Action
    {
        return Action::make('albaran_send')
            ->label(fn (Albaran $record): string => $record->status === Albaran::DRAFT ? __('albaranes.actions.send') : __('albaranes.actions.retry'))
            ->icon('heroicon-m-paper-airplane')->color('primary')
            ->visible(fn (Albaran $record): bool => in_array($record->status, [Albaran::DRAFT, Albaran::PENDING, Albaran::ERROR], true))
            ->requiresConfirmation()
            ->modalDescription(fn (): string => __('albaranes.actions.send_help').(config('facturador.driver') === 'api' ? '' : ' '.__('albaranes.simulated')))
            ->action(function (Albaran $record): void {
                $albaran = BillingConnector::make()->send($record);
                $albaran->status === Albaran::SENT
                    ? Notification::make()->success()->title(__('albaranes.notices.sent', ['n' => $albaran->number]))->send()
                    : Notification::make()->danger()->persistent()->title(__('albaranes.notices.not_sent'))->body($albaran->last_error)->send();
            });
    }

    /** «New albarán» from a mandate (expediente): client and property come from it. */
    public static function newFromInstruction(): Action
    {
        return Action::make('albaran_new')->label(__('albaranes.actions.new'))->icon('heroicon-m-clipboard-document-list')->color('gray')
            ->url(fn (Model $record): string => AlbaranResource::getUrl('create', ['instruction' => $record->getKey()]));
    }

    public static function refresh(): Action
    {
        return Action::make('albaran_refresh')->label(__('albaranes.actions.refresh'))->icon('heroicon-m-arrow-path')->color('gray')
            ->visible(fn (Albaran $record): bool => $record->status === Albaran::SENT)
            ->action(function (Albaran $record): void {
                $albaran = BillingConnector::make()->refresh($record);
                match (true) {
                    $albaran->status === Albaran::INVOICED => Notification::make()->success()->title(__('albaranes.notices.invoiced', ['n' => $albaran->invoice_number]))->send(),
                    filled($albaran->last_error) => Notification::make()->danger()->title(__('albaranes.notices.check_failed'))->body($albaran->last_error)->send(),
                    default => Notification::make()->info()->title(__('albaranes.notices.not_invoiced_yet'))->send(),
                };
            });
    }

    public static function pdf(): Action
    {
        return Action::make('albaran_pdf')->label(__('albaranes.actions.pdf'))->icon('heroicon-m-document-arrow-down')->color('gray')
            ->action(fn (Albaran $record): StreamedResponse => response()->streamDownload(
                function () use ($record): void {
                    echo AlbaranPdf::render($record->loadMissing(['lines', 'property']));
                },
                $record->number.'.pdf',
                ['Content-Type' => 'application/pdf'],
            ));
    }

    /** Sends the albarán to the client for signature through the signing feature, when it is connected. */
    public static function requestConformity(): Action
    {
        return Action::make('albaran_conformity')->label(__('albaranes.actions.request_conformity'))->icon('heroicon-m-pencil-square')->color('warning')
            ->visible(fn (Albaran $record): bool => $record->conformity === Albaran::CONFORMITY_PENDING)
            ->action(function (Albaran $record): void {
                if (Albaran::$conformityRequester === null) {
                    Notification::make()->warning()->persistent()->title(__('albaranes.notices.signing_unavailable'))->send();

                    return;
                }
                $record->forceFill(['signature_request_id' => (Albaran::$conformityRequester)($record)])->save();
                Notification::make()->success()->title(__('albaranes.notices.signing_requested'))->send();
            });
    }

    public static function markConformity(): Action
    {
        return Action::make('albaran_conformity_received')->label(__('albaranes.actions.mark_conformity'))->icon('heroicon-m-check-badge')->color('gray')
            ->visible(fn (Albaran $record): bool => $record->conformity === Albaran::CONFORMITY_PENDING)
            ->requiresConfirmation()->modalDescription(__('albaranes.actions.mark_conformity_help'))
            ->action(fn (Albaran $record) => $record->markConformitySigned());
    }
}
