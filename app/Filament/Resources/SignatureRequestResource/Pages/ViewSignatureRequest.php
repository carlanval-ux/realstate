<?php

declare(strict_types=1);

namespace App\Filament\Resources\SignatureRequestResource\Pages;

use App\Filament\Resources\SignatureRequestResource;
use App\Signing\SignatureDocument;
use App\Signing\SignatureRequest;
use App\Signing\SignatureSigner;
use App\Signing\Signing;
use App\Signing\SigningFailed;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Storage;

/** One request: check the state with the provider, download the PDF sent / signed / audit log, SMS the link or a reminder. */
final class ViewSignatureRequest extends ViewRecord
{
    protected static string $resource = SignatureRequestResource::class;

    protected function getHeaderActions(): array
    {
        /** @var SignatureRequest $request */
        $request = $this->getRecord();
        $pendingSigners = fn (): array => $request->signers()->whereNotIn('status', ['firmado', 'rechazado'])->whereNotNull('phone')->get()
            ->mapWithKeys(fn (SignatureSigner $s): array => [$s->getKey() => $s->name.' · +'.$s->phone])->all();

        return [
            Action::make('reconcile')->label(__('Check status'))->icon('heroicon-m-arrow-path')
                ->visible(fn (): bool => filled($request->provider_submission_id) && $request->driver !== 'simulado')
                ->action(function () use ($request): void {
                    try {
                        Signing::make()->reconcile($request);
                        Notification::make()->title(__('Status: :s', ['s' => $request->refresh()->statusLabel()]))->success()->send();
                    } catch (SigningFailed $e) {
                        Notification::make()->title(__('Could not check the status'))->body($e->getMessage())->danger()->send();
                    }
                    $this->redirect(SignatureRequestResource::getUrl('view', ['record' => $request]));
                }),
            ActionGroup::make([
                $this->smsAction('enlace', __('Send signing link by SMS'), $pendingSigners),
                $this->smsAction('recordatorio', __('Send SMS reminder'), $pendingSigners),
            ])->label(__('SMS'))->icon('heroicon-m-chat-bubble-left-ellipsis')->button()->color('gray')
                ->visible(fn (): bool => ! $request->isFinal() && filled($request->provider_submission_id)),
            ActionGroup::make([
                Action::make('sent')->label(__('PDF sent'))->icon('heroicon-m-document-arrow-down')
                    ->visible(fn (): bool => filled($request->document_path))
                    ->action(fn () => Storage::disk(config('firma.disk'))->download($request->document_path, 'enviado-'.$request->getKey().'.pdf')),
                ...$request->documents()->get()->map(fn (SignatureDocument $d) => Action::make('doc'.$d->getKey())
                    ->label(__(SignatureDocument::KINDS[$d->kind] ?? $d->kind).' · '.$d->name)->icon('heroicon-m-document-check')
                    ->action(fn () => Storage::disk(config('firma.disk'))->download($d->path, $d->kind.'-'.$request->getKey().'.pdf')))->all(),
            ])->label(__('Download'))->icon('heroicon-m-arrow-down-tray')->button()->color('gray'),
        ];
    }

    /** @param callable(): array<int, string> $signers */
    private function smsAction(string $kind, string $label, callable $signers): Action
    {
        return Action::make('sms_'.$kind)->label($label)
            ->schema([Select::make('signer')->label(__('Signer'))->options($signers)->required()])
            ->modalDescription(__('Individual message to this signer only. It does not verify identity or change the signature level.'))
            ->action(function (array $data) use ($kind): void {
                $signer = SignatureSigner::query()->where('signature_request_id', $this->getRecord()->getKey())->findOrFail($data['signer']);
                try {
                    $sms = Signing::make()->sendSms($signer, $kind, auth()->id());
                    Notification::make()->title($sms->status === 'aceptado' ? __('SMS sent') : __('SMS not sent'))
                        ->body($sms->error)->{$sms->status === 'aceptado' ? 'success' : 'warning'}()->send();
                } catch (SigningFailed $e) {
                    Notification::make()->title(__('SMS not sent'))->body($e->getMessage())->danger()->send();
                }
            });
    }
}
