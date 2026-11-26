<?php

declare(strict_types=1);

namespace App\Filament\Resources\SearchRequestResource\RelationManagers;

use App\SearchRequests\SearchRequest;
use App\SearchRequests\SearchRequestResponse;
use App\SearchRequests\SearchRequests;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Liberu\RealEstate\Properties\Models\Property;
use Throwable;

/**
 * «Comparar propuestas»: every answer side by side (kind, price, location, time, links, photos), the team's review,
 * a clarification request, and «Crear inmueble» / «Vincular inmueble existente» from the selected proposal.
 */
final class ResponsesRelationManager extends RelationManager
{
    protected static string $relationship = 'responses';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('solicitudes.accion.compare');
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        $f = fn (string $k): string => __('solicitudes.campo.'.$k);
        $a = fn (string $k): string => __('solicitudes.accion.'.$k);

        return $table
            // Latest version of each proposal (earlier versions: «Ver» → history).
            ->modifyQueryUsing(fn ($query) => $query->with('collaborator', 'recipient')->whereDoesntHave('children'))
            ->columns([
                TextColumn::make('collaborator.name')->label($f('collaborator'))->weight('bold')->wrap()
                    ->description(fn (SearchRequestResponse $record) => \Illuminate\Support\Str::limit((string) $record->description, 180))
                    ->extraAttributes(['style' => 'min-width:20rem;max-width:28rem']),
                TextColumn::make('kind')->label($f('kind'))->badge()->state(fn (SearchRequestResponse $record) => $record->kindLabel())
                    ->color(fn (SearchRequestResponse $record) => ['encaja' => 'success', 'puede_buscar' => 'info', 'aclaraciones' => 'warning', 'no_puede' => 'gray'][$record->kind] ?? 'gray'),
                TextColumn::make('price')->label($f('price'))->formatStateUsing(fn ($state) => $state ? number_format((int) $state, 0, ',', '.').' €' : null)->placeholder('—')->sortable(),
                TextColumn::make('location')->label($f('location'))->placeholder('—')->wrap()->extraAttributes(['style' => 'min-width:9rem']),
                TextColumn::make('estimated_time')->label($f('plazo'))->placeholder('—')->wrap(),
                TextColumn::make('version')->label($f('version'))->formatStateUsing(fn ($state) => 'v'.$state),
                TextColumn::make('links')->label($f('links'))->html()->placeholder('—')
                    ->state(fn (SearchRequestResponse $record) => collect($record->links ?? [])->map(fn ($l) => '<a class="underline" target="_blank" rel="noopener noreferrer" href="'.e($l).'">'.e(parse_url((string) $l, PHP_URL_HOST) ?: $l).'</a>')->implode('<br>') ?: null),
                TextColumn::make('photos')->label($f('photos'))->state(fn (SearchRequestResponse $record) => count($record->photos ?? []) ?: null)->placeholder('—'),
                TextColumn::make('channel')->label($f('channel'))->formatStateUsing(fn ($state) => __('solicitudes.canal.'.$state))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')->label($f('status'))->badge()->formatStateUsing(fn ($state) => SearchRequest::label('propuesta', $state))
                    ->color(fn ($state) => ['seleccionada' => 'success', 'descartada' => 'danger', 'en_estudio' => 'warning'][$state] ?? 'gray'),
                TextColumn::make('created_at')->label($f('received'))->dateTime('d/m/Y H:i', 'Europe/Madrid')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('kind')->label($f('kind'))->options(SearchRequest::options('respuesta', SearchRequestResponse::KINDS)),
                SelectFilter::make('status')->label($f('status'))->options(SearchRequest::options('propuesta', SearchRequestResponse::STATUSES)),
            ])
            ->defaultSort('price')
            ->recordActions([
                Action::make('create_property')->label($a('create_property'))->icon('heroicon-o-home-modern')->color('success')->button()
                    ->visible(fn (SearchRequestResponse $record): bool => $record->property_id === null && $record->kind !== 'no_puede')
                    ->requiresConfirmation()
                    ->action(fn (SearchRequestResponse $record) => $this->select($record, null)),
                ActionGroup::make([
                Action::make('detail')->label(__('View'))->icon('heroicon-o-eye')->color('gray')->modalSubmitAction(false)
                    ->modalHeading(fn (SearchRequestResponse $record) => $record->collaborator?->name.' · '.$record->kindLabel())
                    ->schema(fn (SearchRequestResponse $record): array => [
                        TextEntry::make('d')->hiddenLabel()->state(new HtmlString(self::detail($record))),
                    ]),
                Action::make('review')->label($a('classify'))->icon('heroicon-o-pencil-square')->color('gray')
                    ->fillForm(fn (SearchRequestResponse $record): array => $record->only(['status', 'kind', 'team_notes']))
                    ->schema([
                        Select::make('kind')->label($f('kind'))->options(SearchRequest::options('respuesta', SearchRequestResponse::KINDS)),
                        Select::make('status')->label($f('status'))->required()->options(SearchRequest::options('propuesta', ['nueva', 'en_estudio', 'descartada'])),
                        Textarea::make('team_notes')->label($f('team_notes'))->rows(3)->maxLength(2000),
                    ])
                    ->action(fn (SearchRequestResponse $record, array $data) => $this->attempt(fn () => SearchRequests::review($record, $data['status'], $data['team_notes'] ?? null, $data['kind'] ?? null, auth()->user()))),
                Action::make('ask')->label($a('ask'))->icon('heroicon-o-question-mark-circle')->color('gray')
                    ->visible(fn (): bool => SearchRequests::enabled())
                    ->schema([Textarea::make('question')->label($f('question'))->required()->rows(4)->maxLength(2000)])
                    ->action(fn (SearchRequestResponse $record, array $data) => $this->attempt(fn () => SearchRequests::send($this->getOwnerRecord(), 'aclaracion', [$record->recipient_id], auth()->user(), $data['question']))),
                Action::make('link_property')->label($a('link_property'))->icon('heroicon-o-link')->color('gray')
                    ->visible(fn (SearchRequestResponse $record): bool => $record->property_id === null && $record->kind !== 'no_puede')
                    ->schema([
                        Select::make('property_id')->label($f('property'))->required()->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => Property::query()->where('team_id', $this->getOwnerRecord()->team_id)
                                ->where(fn ($q) => $q->where('title', 'like', "%{$search}%")->orWhere('address', 'like', "%{$search}%"))
                                ->limit(20)->get()->mapWithKeys(fn (Property $p) => [$p->getKey() => $p->reference().' · '.($p->title ?: $p->address)])->all())
                            ->getOptionLabelUsing(fn ($value): ?string => ($p = Property::query()->where('team_id', $this->getOwnerRecord()->team_id)->find($value)) ? $p->reference().' · '.($p->title ?: $p->address) : null),
                    ])
                    ->action(fn (SearchRequestResponse $record, array $data) => $this->select($record, Property::query()->where('team_id', $this->getOwnerRecord()->team_id)->findOrFail($data['property_id']))),
                ]),
            ]);
    }

    private function select(SearchRequestResponse $record, ?Property $property): void
    {
        try {
            $url = SearchRequests::selectProposal($record, $property, auth()->user());
            Notification::make()->title(__('solicitudes.aviso.property_ready', ['ref' => Property::query()->find($record->fresh()->property_id)?->reference()]))->success()->send();
            $this->redirect($url);
        } catch (Throwable $e) {
            report($e);
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    private function attempt(callable $operation): void
    {
        try {
            $operation();
            Notification::make()->title(__('solicitudes.aviso.saved'))->success()->send();
        } catch (Throwable $e) {
            report($e);
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    /** $withTeamNotes false for the collaborator's own view: the team's notes are internal. */
    public static function detail(SearchRequestResponse $r, string $route = 'clarto.solicitudes.archivo', bool $withTeamNotes = true): string
    {
        $f = fn (string $k): string => e(__('solicitudes.campo.'.$k));
        $rows = array_filter([
            $f('kind') => e($r->kindLabel()),
            $f('description') => filled($r->description) ? nl2br(e($r->description)) : null,
            $f('location') => e($r->location),
            $f('price') => $r->price ? e(number_format($r->price, 0, ',', '.').' €') : null,
            $f('features') => filled($r->features) ? nl2br(e($r->features)) : null,
            $f('estimated_time') => e($r->estimated_time),
            $f('links') => collect($r->links ?? [])->map(fn ($l) => '<a class="underline" target="_blank" rel="noopener noreferrer" href="'.e($l).'">'.e($l).'</a>')->implode('<br>'),
            $f('team_notes') => $withTeamNotes && filled($r->team_notes) ? nl2br(e($r->team_notes)) : null,
        ], fn ($v) => filled($v));
        $html = collect($rows)->map(fn ($v, $k) => '<div style="margin-bottom:.5rem"><div style="font-size:.75rem;opacity:.7">'.$k.'</div><div>'.$v.'</div></div>')->implode('');
        foreach (array_values($r->photos ?? []) as $i => $path) {
            $url = e(route($route, ['searchRequest' => $r->search_request_id, 'response' => $r->getKey(), 'index' => $i]));
            $html .= str_ends_with(strtolower((string) $path), '.pdf')
                ? '<a class="underline" href="'.$url.'" target="_blank" rel="noopener" style="display:inline-block;margin:0 .5rem .5rem 0">'.e(basename((string) $path)).'</a>'
                : '<a href="'.$url.'" target="_blank" rel="noopener" style="display:inline-block;margin:0 .5rem .5rem 0"><img src="'.$url.'" alt="" style="height:110px;max-width:180px;object-fit:cover;border-radius:.4rem"></a>';
        }

        return $html;
    }
}
