<?php

declare(strict_types=1);

namespace App\Filament\Colaboradores\Resources\SearchRequestResource\Pages;

use App\Filament\Colaboradores\Resources\SearchRequestResource;
use App\SearchRequests\SearchRequest;
use App\SearchRequests\SearchRequestAccess;
use App\SearchRequests\SearchRequestResponse;
use App\SearchRequests\SearchRequests;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Throwable;

/** The complete request and «Responder»: fits / can find it / needs clarification / cannot, with the proposal data. */
final class ViewSearchRequest extends ViewRecord
{
    protected static string $resource = SearchRequestResource::class;

    protected function getHeaderActions(): array
    {
        $f = fn (string $k): string => __('solicitudes.campo.'.$k);
        $proposal = fn (Get $get): bool => in_array($get('kind'), SearchRequestResponse::PROPOSALS, true);

        return [
            Action::make('respond')->label(__('solicitudes.accion.respond'))->icon('heroicon-o-chat-bubble-left-ellipsis')
                ->visible(fn (): bool => $this->getRecord()->acceptsAnswers())
                ->modalWidth('3xl')
                ->schema([
                    Radio::make('kind')->label($f('kind'))->required()->live()->options(SearchRequest::options('respuesta', SearchRequestResponse::KINDS)),
                    \Filament\Forms\Components\Select::make('parent_id')->label(__('solicitudes.pagina.completar'))->placeholder(__('solicitudes.pagina.nueva_propuesta'))
                        ->visible($proposal)
                        ->options(fn (): array => SearchRequestResponse::query()->where('recipient_id', SearchRequestAccess::recipientFor(auth()->user(), $this->getRecord())?->getKey() ?? 0)
                            ->whereIn('kind', SearchRequestResponse::PROPOSALS)->latest('id')->get()
                            ->mapWithKeys(fn (SearchRequestResponse $r) => [$r->getKey() => $r->kindLabel().' · v'.$r->version.' · '.\Illuminate\Support\Str::limit((string) $r->description, 60)])->all()),
                    Textarea::make('description')->label(fn (Get $get) => $get('kind') === 'aclaraciones' ? $f('question') : $f('description'))
                        ->rows(4)->maxLength(5000)->required(fn (Get $get) => $get('kind') === 'aclaraciones'),
                    TextInput::make('location')->label($f('location'))->maxLength(250)->visible($proposal),
                    TextInput::make('price')->label($f('price'))->integer()->minValue(0)->visible($proposal),
                    Textarea::make('features')->label($f('features'))->rows(2)->maxLength(2000)->visible($proposal),
                    Repeater::make('links')->label($f('links'))->simple(TextInput::make('url')->url()->required()->maxLength(500))->maxItems(10)->defaultItems(0)->visible($proposal),
                    FileUpload::make('photos')->label(__('solicitudes.pagina.archivos'))->multiple()->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                        ->maxFiles(SearchRequestResponse::MAX_PHOTOS)->maxSize(10 * 1024)
                        ->disk(SearchRequest::DISK)->directory('clarto/solicitudes/propuestas')->visibility('private')->visible($proposal),
                    TextInput::make('estimated_time')->label($f('estimated_time'))->maxLength(120)->visible(fn (Get $get) => $get('kind') !== 'no_puede'),
                ])
                ->action(function (array $data): void {
                    try {
                        $recipient = SearchRequestAccess::recipientFor(auth()->user(), $this->getRecord()) ?? abort(404);
                        SearchRequests::respond($recipient, $data, auth()->user());
                        Notification::make()->title(__('solicitudes.aviso.response_saved'))->success()->send();
                    } catch (Throwable $e) {
                        report($e);
                        Notification::make()->title($e->getMessage())->danger()->send();
                    }
                    $this->getRecord()->refresh();
                }),
        ];
    }
}
