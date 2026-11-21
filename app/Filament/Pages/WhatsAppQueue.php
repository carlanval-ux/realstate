<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Communication;
use App\WhatsApp\WhatsApp;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Liberu\RealEstate\Parties\Models\Party;

/**
 * Resolution queue: WhatsApp messages from a number that matches several contacts of the team (or none). The team
 * says which contact it is (that number is linked from then on) or dismisses it (e.g. a lead still in GoHighLevel).
 */
final class WhatsAppQueue extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-inbox-arrow-down';

    protected static ?string $slug = 'whatsapp-por-vincular';

    protected static ?int $navigationSort = 90;

    public static function getNavigationLabel(): string
    {
        return __('WhatsApp to link');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('filament.nav_groups.people_relationships');
    }

    public function getTitle(): string
    {
        return __('WhatsApp to link');
    }

    public static function canAccess(): bool
    {
        return filled(auth()->user()?->current_team_id);
    }

    /** In the menu only while there are messages to assign; otherwise reachable from «Conversaciones». */
    public static function shouldRegisterNavigation(): bool
    {
        return static::getNavigationBadge() !== null;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Communication::query()->where('team_id', auth()->user()?->current_team_id)->where('status', Communication::UNLINKED)->distinct()->count('phone');

        return $count > 0 ? (string) $count : null;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        $teamId = auth()->user()?->current_team_id;

        return $table
            ->query(Communication::query()->where('team_id', $teamId)->where('status', Communication::UNLINKED))
            ->defaultSort('id', 'desc')
            ->emptyStateHeading(__('Nothing to link'))
            ->emptyStateDescription(__('WhatsApp messages from numbers that match several contacts, or none, appear here.'))
            ->columns([
                TextColumn::make('sent_at')->label(__('Received'))->dateTime('d/m/Y H:i', 'Europe/Madrid'),
                TextColumn::make('phone')->label(__('Phone'))->searchable(),
                TextColumn::make('body')->label(__('Message'))->limit(80)->wrap(),
                TextColumn::make('candidates')->label(__('Possible contacts'))
                    ->state(fn (Communication $record): string => $record->candidates
                        ? Party::query()->forTeam($teamId)->whereKey($record->candidates)->pluck('name')->implode(' · ')
                        : __('No contact has this number')),
            ])
            ->recordActions([
                Action::make('link')->label(__('Link to contact'))->icon('heroicon-m-link')
                    ->schema(fn (Communication $record): array => [
                        Select::make('party_id')->label(__('Contact'))->required()->searchable()
                            ->options(fn (): array => $record->candidates
                                ? Party::query()->forTeam($teamId)->whereKey($record->candidates)->get()->mapWithKeys(fn (Party $p) => [$p->getKey() => $p->name.' · '.$p->phone])->all()
                                : [])
                            ->getSearchResultsUsing(fn (string $search): array => Party::query()->forTeam($teamId)
                                ->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"))
                                ->limit(20)->get()->mapWithKeys(fn (Party $p) => [$p->getKey() => $p->name.($p->phone ? ' · '.$p->phone : '')])->all())
                            ->getOptionLabelUsing(fn ($value): ?string => Party::query()->forTeam($teamId)->find($value)?->name),
                    ])
                    ->action(function (array $data, Communication $record) use ($teamId): void {
                        $n = WhatsApp::link($teamId, $record->phone, (int) $data['party_id']);
                        Notification::make()->title(trans_choice(':count message linked|:count messages linked', $n, ['count' => $n]))->success()->send();
                    }),
                Action::make('dismiss')->label(__('Dismiss'))->icon('heroicon-m-x-mark')->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription(__('The messages from this number leave the queue. If it writes again, it will appear here again.'))
                    ->action(fn (Communication $record) => WhatsApp::dismiss($teamId, $record->phone)),
            ]);
    }
}
