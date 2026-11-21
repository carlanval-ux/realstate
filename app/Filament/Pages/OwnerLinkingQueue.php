<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Acquisition\GhlHandoff;
use App\Filament\Owners\OwnerResource;
use App\Owners\OwnerProfile;
use App\Owners\SpanishIds;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Liberu\RealEstate\Parties\Models\Party;
use Liberu\RealEstate\Properties\Models\Property;
use Throwable;
use UnitEnum;

/**
 * Propietarios → Pendientes de vinculación: won GHL opportunities whose owner could not be identified without
 * doubt. The property and mandate already exist without owner; a person picks the right owner among the
 * candidates (or any owner of the team) or confirms a new one, and both attach to it (GhlHandoff::link()).
 */
final class OwnerLinkingQueue extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-link';

    protected static string|UnitEnum|null $navigationGroup = null;

    protected static ?string $navigationLabel = null;

    protected static ?int $navigationSort = 6;

    protected static ?string $slug = 'propietarios-pendientes';

    public static function getNavigationLabel(): string
    {
        return __('propietarios.queue.title');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('filament.nav_groups.people_relationships');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = GhlHandoff::pendingForTeam(Filament::getTenant()?->getKey())->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public function getTitle(): string
    {
        return __('propietarios.queue.title');
    }

    public static function canAccess(): bool
    {
        return OwnerProfile::canAccess();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Text::make(__('propietarios.queue.help')),
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => GhlHandoff::pendingForTeam(Filament::getTenant()?->getKey()))
            ->columns([
                TextColumn::make('processed_at')->label(__('propietarios.cols.date'))->dateTime('d/m/Y H:i', 'Europe/Madrid')->sortable(),
                TextColumn::make('contact_name')->label(__('propietarios.queue.ghl_contact'))
                    ->state(fn (GhlHandoff $record): string => (string) ($record->contact['name'] ?? trim(($record->contact['firstName'] ?? '').' '.($record->contact['lastName'] ?? ''))))
                    ->description(fn (GhlHandoff $record): string => implode(' · ', array_filter([$record->contact['email'] ?? null, $record->contact['phone'] ?? null, $record->contact_id]))),
                TextColumn::make('reason')->label(__('propietarios.queue.reason'))->wrap()->color('warning'),
                TextColumn::make('candidates')->label(__('propietarios.queue.candidates'))->listWithLineBreaks()
                    ->state(fn (GhlHandoff $record): array => self::candidates($record)->map(fn (Party $p): string => self::label($p))->values()->all()),
                TextColumn::make('property')->label(__('propietarios.cols.property'))
                    ->state(fn (GhlHandoff $record): ?string => Property::query()->find($record->property_id)?->reference()),
            ])
            ->recordActions([
                Action::make('link')->label(__('propietarios.queue.link'))->icon('heroicon-m-link')->color('primary')
                    ->modalHeading(__('propietarios.queue.link'))
                    ->schema(fn (GhlHandoff $record): array => [
                        Select::make('party_id')->label(__('propietarios.singular'))->required()->searchable()
                            ->options(self::candidates($record)->mapWithKeys(fn (Party $p): array => [$p->getKey() => self::label($p)])->all())
                            ->getSearchResultsUsing(fn (string $search): array => Party::query()
                                ->where('team_id', Filament::getTenant()?->getKey())
                                ->where(fn ($q) => $q->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%')->orWhere('phone', 'like', '%'.$search.'%')
                                    ->orWhereIn('id', OwnerProfile::query()->select('party_id')->where('tax_id', SpanishIds::normalize($search))))
                                ->limit(30)->get()->mapWithKeys(fn (Party $p): array => [$p->getKey() => self::label($p)])->all())
                            ->getOptionLabelUsing(fn ($value): ?string => ($p = Party::query()->find($value)) ? self::label($p) : null)
                            ->helperText(__('propietarios.queue.link_help')),
                    ])
                    ->action(fn (GhlHandoff $record, array $data) => $this->resolve($record, Party::query()->where('team_id', Filament::getTenant()?->getKey())->findOrFail($data['party_id']))),
                Action::make('new_owner')->label(__('propietarios.queue.new_owner'))->icon('heroicon-m-user-plus')->color('gray')
                    ->requiresConfirmation()->modalDescription(__('propietarios.queue.new_owner_help'))
                    ->action(fn (GhlHandoff $record) => $this->resolve($record, null)),
            ])
            ->emptyStateHeading(__('propietarios.queue.empty'))
            ->defaultSort('processed_at', 'desc');
    }

    private function resolve(GhlHandoff $handoff, ?Party $party): void
    {
        try {
            $owner = $handoff->link($party, auth()->user());
        } catch (Throwable $e) {
            Notification::make()->title(__('propietarios.queue.failed'))->body($e->getMessage())->danger()->send();

            return;
        }
        Notification::make()->title(__('propietarios.queue.linked', ['name' => $owner->name]))->success()->send();
        $this->redirect(OwnerResource::getUrl('view', ['record' => $owner]));
    }

    /** @return \Illuminate\Support\Collection<int, Party> */
    private static function candidates(GhlHandoff $record): \Illuminate\Support\Collection
    {
        return Party::query()->whereIn('id', (array) $record->candidate_party_ids)->with('clartoOwnerProfile')->get();
    }

    /** "Name · e-mail · phone · NIF ••••678Z" (NIF masked unless the user may see it). */
    private static function label(Party $p): string
    {
        return Str::limit(implode(' · ', array_filter([$p->name, $p->email, $p->phone, $p->clartoOwnerProfile?->displayTaxId()])), 120);
    }
}
