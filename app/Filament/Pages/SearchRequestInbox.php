<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Resources\SearchRequestResource;
use App\SearchRequests\SearchRequest;
use App\SearchRequests\SearchRequestInboxItem;
use App\SearchRequests\SearchRequests;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Throwable;

/**
 * «Respuestas por vincular»: e-mail or WhatsApp replies whose request is not certain (no reference, several requests
 * sent to that collaborator, or unknown sender quoting a reference). The team links each one to a request (it
 * becomes that collaborator's answer) or dismisses it.
 */
final class SearchRequestInbox extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-inbox-arrow-down';

    protected static ?string $slug = 'solicitudes-por-vincular';

    protected static ?int $navigationSort = 46;

    public static function getNavigationLabel(): string
    {
        return __('solicitudes.menu_pendientes');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('filament.nav_groups.sales_lettings');
    }

    public function getTitle(): string
    {
        return __('solicitudes.menu_pendientes');
    }

    /** In the menu only while there is something to link; otherwise reachable from «Solicitudes de inmuebles». */
    public static function shouldRegisterNavigation(): bool
    {
        return static::getNavigationBadge() !== null;
    }

    public static function getNavigationBadge(): ?string
    {
        $n = SearchRequestInboxItem::query()->where('team_id', Filament::getTenant()?->getKey())->where('status', 'pendiente')->count();

        return $n > 0 ? (string) $n : null;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        $teamId = Filament::getTenant()?->getKey();
        $f = fn (string $k): string => __('solicitudes.campo.'.$k);
        $requests = fn (?array $ids = null): array => SearchRequest::query()->forTeam($teamId)->when($ids !== null, fn ($q) => $q->whereKey($ids))
            ->latest('id')->limit(100)->get()->mapWithKeys(fn (SearchRequest $r) => [$r->getKey() => $r->reference.' · '.$r->title])->all();

        return $table
            ->query(SearchRequestInboxItem::query()->with('communication', 'collaborator')->where('team_id', $teamId)->where('status', 'pendiente'))
            ->defaultSort('id', 'desc')
            ->emptyStateHeading(__('Nothing to link'))
            ->columns([
                TextColumn::make('communication.created_at')->label($f('received'))->dateTime('d/m/Y H:i', 'Europe/Madrid'),
                TextColumn::make('communication.channel')->label($f('channel'))->formatStateUsing(fn ($state) => __('solicitudes.canal.'.$state)),
                TextColumn::make('from')->label($f('from'))->state(fn (SearchRequestInboxItem $record) => $record->collaborator?->name ?? $record->communication?->address()),
                TextColumn::make('communication.body')->label($f('message'))->limit(120)->wrap()
                    ->description(fn (SearchRequestInboxItem $record) => $record->communication?->subject),
                TextColumn::make('candidates')->label($f('candidates'))->wrap()
                    ->state(fn (SearchRequestInboxItem $record) => SearchRequest::query()->whereKey($record->candidates ?? [])->pluck('reference')->implode(', ')),
                TextColumn::make('reason')->label($f('reason'))->formatStateUsing(fn ($state) => __('solicitudes.motivo.'.$state)),
            ])
            ->recordActions([
                Action::make('link')->label(__('solicitudes.accion.link'))->icon('heroicon-m-link')
                    ->fillForm(fn (SearchRequestInboxItem $record): array => ['search_request_id' => count($record->candidates ?? []) === 1 ? $record->candidates[0] : null, 'collaborator_id' => $record->collaborator_id])
                    ->schema(fn (SearchRequestInboxItem $record): array => [
                        Select::make('search_request_id')->label($f('request'))->required()->live()->searchable()
                            ->options(fn (): array => $requests($record->collaborator_id ? $record->candidates : null)),
                        Select::make('collaborator_id')->label($f('collaborator'))->required()
                            ->options(fn (Get $get): array => SearchRequest::query()->forTeam($teamId)->find($get('search_request_id'))?->recipients()->with('collaborator')->get()
                                ->mapWithKeys(fn ($r) => [$r->collaborator_id => $r->collaborator?->name])->all() ?? []),
                    ])
                    ->action(function (SearchRequestInboxItem $record, array $data) use ($teamId): void {
                        try {
                            $request = SearchRequest::query()->forTeam($teamId)->findOrFail($data['search_request_id']);
                            SearchRequests::linkInboxItem($record, $request, (int) $data['collaborator_id'], auth()->user());
                            Notification::make()->title(__('solicitudes.aviso.linked', ['ref' => $request->reference]))->success()
                                ->actions([Action::make('open')->label($request->reference)->url(SearchRequestResource::getUrl('view', ['record' => $request]))])->send();
                        } catch (Throwable $e) {
                            report($e);
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),
                Action::make('dismiss')->label(__('solicitudes.accion.dismiss'))->icon('heroicon-m-x-mark')->color('gray')->requiresConfirmation()
                    ->action(fn (SearchRequestInboxItem $record) => SearchRequests::dismissInboxItem($record, auth()->user())),
            ]);
    }
}
