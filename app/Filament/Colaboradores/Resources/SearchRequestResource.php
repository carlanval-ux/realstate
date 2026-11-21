<?php

declare(strict_types=1);

namespace App\Filament\Colaboradores\Resources;

use App\Collaborators\CollaboratorAccess;
use App\Filament\Colaboradores\Resources\SearchRequestResource\Pages;
use App\Filament\Resources\SearchRequestResource\RelationManagers\ResponsesRelationManager;
use App\SearchRequests\SearchRequest;
use App\SearchRequests\SearchRequestResponse;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/**
 * Collaborator panel (/colaboradores): the searches CLARTO sent to this collaborator, complete, and their answers.
 * Every query goes through CollaboratorAccess::scope() (server side): a direct link to a request not sent to them is 404.
 */
final class SearchRequestResource extends Resource
{
    protected static ?string $model = SearchRequest::class;

    protected static ?string $slug = 'solicitudes';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-magnifying-glass-circle';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'title';

    public static function isScopedToTenant(): bool
    {
        return false;
    }

    public static function getNavigationLabel(): string
    {
        return __('solicitudes.menu_colaborador');
    }

    public static function getModelLabel(): string
    {
        return __('solicitudes.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('solicitudes.menu_colaborador');
    }

    public static function getEloquentQuery(): Builder
    {
        return CollaboratorAccess::scope(parent::getEloquentQuery(), auth()->user());
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(fn (SearchRequest $record) => $record->reference.' · '.$record->title)->schema([
                TextEntry::make('summary')->hiddenLabel()->html()
                    ->state(fn (SearchRequest $record) => nl2br(e(\App\SearchRequests\SearchRequestMessages::summary($record, app()->getLocale())))),
                TextEntry::make('attachments')->label(__('solicitudes.campo.attachments'))->html()
                    ->visible(fn (SearchRequest $record) => filled($record->attachments))
                    ->state(fn (SearchRequest $record) => \App\Filament\Resources\SearchRequestResource::attachmentLinks($record, 'clarto.solicitudes.archivo.colaborador')),
            ]),
            Section::make(__('solicitudes.seccion.my_responses'))->schema([
                TextEntry::make('mine')->hiddenLabel()->html()->placeholder('—')
                    ->state(fn (SearchRequest $record) => self::myResponses($record)),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $f = fn (string $k): string => __('solicitudes.campo.'.$k);
        $mine = fn (SearchRequest $r) => $r->recipients->first();

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['recipients' => fn ($q) => $q->where('collaborator_id', CollaboratorAccess::collaboratorFor(auth()->user())?->getKey() ?? 0)]))
            ->columns([
                TextColumn::make('reference')->label($f('reference'))->weight('bold')->searchable(),
                TextColumn::make('title')->label($f('title'))->searchable()->wrap(),
                TextColumn::make('operation')->label($f('operation'))->formatStateUsing(fn ($state) => SearchRequest::label('operacion', $state)),
                TextColumn::make('municipality')->label($f('municipality'))->placeholder('—'),
                TextColumn::make('budget_max')->label($f('budget'))->formatStateUsing(fn ($state) => $state ? number_format((int) $state, 0, ',', '.').' €' : null)->placeholder('—'),
                TextColumn::make('respond_by')->label($f('respond_by'))->date('d/m/Y'),
                TextColumn::make('mine')->label($f('status'))->badge()
                    ->state(fn (SearchRequest $r) => $r->isActive() ? SearchRequest::label('destinatario', $mine($r)?->status) : SearchRequest::label('estado', $r->status)),
            ])
            ->recordActions([ViewAction::make()])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSearchRequests::route('/'),
            'view' => Pages\ViewSearchRequest::route('/{record}'),
        ];
    }

    private static function myResponses(SearchRequest $request): ?HtmlString
    {
        $responses = CollaboratorAccess::scope(SearchRequestResponse::query()->where('search_request_id', $request->getKey()), auth()->user())->latest('id')->get();

        return $responses->isEmpty() ? null : new HtmlString($responses->map(fn (SearchRequestResponse $r) => '<div style="border:1px solid rgba(127,127,127,.3);border-radius:.5rem;padding:.75rem;margin-bottom:.75rem">'
            .'<div style="font-size:.75rem;opacity:.7">'.e($r->created_at?->timezone(SearchRequest::timezone())->format('d/m/Y H:i')).' · '.e(__('solicitudes.canal.'.$r->channel)).'</div>'
            .ResponsesRelationManager::detail($r, 'clarto.solicitudes.archivo.colaborador').'</div>')->implode(''));
    }
}
