<?php

declare(strict_types=1);

namespace App\Filament\Resources\SearchRequestResource\Pages;

use App\Collaborators\Collaborator;
use App\Filament\Resources\SearchRequestResource;
use App\SearchRequests\SearchRequest;
use App\SearchRequests\SearchRequests;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\HtmlString;
use Throwable;

/** Record page: preview, send, update, status; below, follow-up per collaborator, proposals, sends and history. */
final class ViewSearchRequest extends ViewRecord
{
    protected static string $resource = SearchRequestResource::class;

    protected function getHeaderActions(): array
    {
        $a = fn (string $k): string => __('solicitudes.accion.'.$k);
        $f = fn (string $k): string => __('solicitudes.campo.'.$k);
        $r = fn (): SearchRequest => $this->getRecord();
        $enabled = fn (): bool => SearchRequests::enabled();

        return [
            Action::make('preview')->label($a('preview'))->icon('heroicon-o-eye')->color('gray')
                ->modalWidth('5xl')->modalSubmitAction(false)->modalCancelActionLabel(__('Close'))
                ->schema([
                    Select::make('collaborator_id')->label($f('collaborator'))->live()->required()
                        ->options(fn (): array => SearchRequestResource::activeCollaborators())
                        ->default(fn () => $r()->recipients()->value('collaborator_id') ?? array_key_first(SearchRequestResource::activeCollaborators())),
                    Select::make('kind')->label(__('solicitudes.campo.message'))->live()->default('solicitud')->selectablePlaceholder(false)
                        ->options(SearchRequest::options('tipo_envio', SearchRequests::KINDS)),
                    TextEntry::make('rendered')->hiddenLabel()
                        ->state(function (Get $get) use ($r): HtmlString {
                            $c = Collaborator::query()->where('team_id', $r()->team_id)->find($get('collaborator_id'));

                            return $c === null ? new HtmlString(e(__('solicitudes.aviso.no_collaborators')))
                                : self::previewHtml($c, SearchRequests::compose($r(), $c, (string) ($get('kind') ?: 'solicitud'), $get('kind') && $get('kind') !== 'solicitud' ? '…' : null));
                        }),
                ]),
            Action::make('send')->label($a('send'))->icon('heroicon-o-paper-airplane')
                ->visible(fn (): bool => $enabled() && in_array($r()->status, ['borrador', ...SearchRequest::ACTIVE], true) && ! $r()->isPastDeadline())
                ->modalDescription(__('solicitudes.ayuda.recipients'))
                ->fillForm(fn (): array => ['collaborators' => $r()->recipients()->where('status', 'pendiente')->pluck('collaborator_id')->all()])
                ->schema([
                    Toggle::make('all_active')->label($f('all_active'))->live(),
                    Select::make('collaborators')->label($f('recipients'))->multiple()->searchable()
                        ->hidden(fn (Get $get): bool => (bool) $get('all_active'))->required(fn (Get $get): bool => ! $get('all_active'))
                        ->options(fn (): array => array_diff_key(SearchRequestResource::activeCollaborators(),
                            array_flip($r()->recipients()->whereIn('status', ['enviada', 'respondida'])->pluck('collaborator_id')->all()))),
                ])
                ->action(function (array $data) use ($r): void {
                    $ids = ($data['all_active'] ?? false) ? array_keys(SearchRequestResource::activeCollaborators()) : array_map('intval', (array) ($data['collaborators'] ?? []));
                    $this->attempt(function () use ($r, $ids): void {
                        SearchRequests::addRecipients($r(), $ids, auth()->user());
                        $names = SearchRequests::send($r(), 'solicitud', $r()->recipients()->whereIn('collaborator_id', $ids)->pluck('id')->all(), auth()->user());
                        Notification::make()->title(__('solicitudes.aviso.sent', ['n' => count($names)]))->body(implode(', ', $names))->success()->send();
                    });
                }),
            Action::make('send_update')->label($a('send_update'))->icon('heroicon-o-arrow-path-rounded-square')->color('warning')
                ->visible(fn (): bool => $enabled() && $r()->first_sent_at !== null && $r()->acceptsAnswers())
                ->schema([Textarea::make('changes')->label($f('changes'))->required()->rows(3)->maxLength(1000)])
                ->action(fn (array $data) => $this->attempt(function () use ($r, $data): void {
                    $names = SearchRequests::send($r(), 'actualizacion', null, auth()->user(), $data['changes']);
                    Notification::make()->title(__('solicitudes.aviso.sent', ['n' => count($names)]))->body(implode(', ', $names))->success()->send();
                })),
            Action::make('extend')->label(__('solicitudes.extra.extend'))->icon('heroicon-o-calendar-days')->color('gray')
                ->visible(fn (): bool => $r()->status !== 'borrador')
                ->modalDescription(__('solicitudes.extra.extend_ayuda'))
                ->fillForm(fn (): array => ['respond_by' => max($r()->respond_by->toDateString(), now(SearchRequest::timezone($r()->team_id))->addDays(7)->toDateString())])
                ->schema([
                    DatePicker::make('respond_by')->label($f('respond_by'))->required()->after('today'),
                    Textarea::make('note')->label($f('message'))->rows(2)->maxLength(1000),
                ])
                ->action(fn (array $data) => $this->attempt(function () use ($r, $data): void {
                    SearchRequests::extend($r(), (string) \Illuminate\Support\Carbon::parse($data['respond_by'])->toDateString(), auth()->user(), $data['note'] ?? null);
                    Notification::make()->title(__('solicitudes.aviso.extended'))->success()->send();
                })),
            Action::make('status')->label($a('status'))->icon('heroicon-o-flag')->color('gray')
                ->visible(fn (): bool => ! in_array($r()->status, SearchRequest::CLOSED, true))
                ->fillForm(fn (): array => ['status' => $r()->status, 'outcome' => $r()->outcome])
                ->schema([
                    Select::make('status')->label($f('status'))->required()->selectablePlaceholder(false)
                        ->options(fn (): array => SearchRequest::options('estado', array_values(array_unique([$r()->status, 'en_evaluacion', 'cubierta', 'cerrada'])))),
                    Textarea::make('outcome')->label($f('outcome'))->rows(3)->maxLength(2000),
                ])
                ->action(fn (array $data) => $this->attempt(fn () => SearchRequests::changeStatus($r(), $data['status'], $data['outcome'] ?? null, auth()->user()))),
            EditAction::make(),
        ];
    }

    /** @param array{subject: string, email: string, whatsapp: list<string>} $m */
    public static function previewHtml(Collaborator $c, array $m): HtmlString
    {
        $email = '<div style="border:1px solid rgba(127,127,127,.35);border-radius:.5rem;padding:1rem;margin-bottom:1rem">'
            .'<div style="font-size:.8rem;opacity:.7">'.e(__('solicitudes.campo.channel')).': '.e(__('solicitudes.canal.email')).' · '.e($c->email ?: __('solicitudes.aviso.email_none')).'</div>'
            .'<div style="font-weight:600;margin:.35rem 0">'.e($m['subject']).'</div>'
            .'<div style="white-space:pre-wrap;font-family:inherit">'.e($m['email']).'</div></div>';
        $wa = '<div style="background:#e5ddd5;border-radius:.5rem;padding:1rem;color:#111">'
            .'<div style="font-size:.8rem;opacity:.7;margin-bottom:.5rem">WhatsApp · '.e($c->whatsapp ?: __('solicitudes.aviso.whatsapp_none')).' · '.count($m['whatsapp']).' '.e(mb_strtolower(__('solicitudes.campo.block'))).'</div>';
        foreach ($m['whatsapp'] as $block) {
            $wa .= '<div style="background:#dcf8c6;border-radius:.5rem;padding:.6rem .8rem;margin:0 0 .5rem auto;max-width:85%;white-space:pre-wrap">'.e($block).'</div>';
        }

        return new HtmlString('<div style="font-size:.85rem">'.e(__('solicitudes.aviso.preview_for', ['nombre' => $c->name, 'idioma' => strtoupper(SearchRequests::locale($c))])).'</div>'
            .'<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:1rem;margin-top:.5rem">'.$email.$wa.'</div></div>');
    }

    private function attempt(callable $operation): void
    {
        try {
            $operation();
        } catch (Throwable $e) {
            report($e);
            Notification::make()->title($e->getMessage())->danger()->send();
        }
        $this->getRecord()->refresh();
    }
}
