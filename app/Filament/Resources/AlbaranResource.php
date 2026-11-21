<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Albaranes\Albaran;
use App\Albaranes\AlbaranLine;
use App\Albaranes\BillingCatalogue;
use App\Filament\AlbaranActions;
use App\Filament\Resources\AlbaranResource\Pages;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Liberu\RealEstate\Instructions\Models\Instruction;
use Liberu\RealEstate\Parties\Models\Party;
use Liberu\RealEstate\Properties\Models\Property;

/**
 * «Albaranes de servicio»: general list plus create/edit. Opened from the owner 360 page, a property or a
 * mandate with ?party=, ?property=, ?instruction= to prefill the client (property and mandate are optional).
 */
final class AlbaranResource extends Resource
{
    protected static ?string $model = Albaran::class;

    protected static ?string $slug = 'albaranes';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?int $navigationSort = 40;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getModelLabel(): string
    {
        return __('albaranes.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('albaranes.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('albaranes.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('filament.nav_groups.sales_lettings');
    }

    public static function form(Schema $schema): Schema
    {
        $locked = fn (?Albaran $record): bool => $record !== null && ! $record->isEditable();
        $team = fn (): mixed => Filament::getTenant()?->getKey() ?? auth()->user()?->current_team_id;

        return $schema->components([
            Text::make(fn (?Albaran $record): ?string => $record && filled($record->last_error) ? __('albaranes.last_error', ['m' => $record->last_error]) : null)
                ->color('danger')->visible(fn (?Albaran $record): bool => filled($record?->last_error))->columnSpanFull(),
            Text::make(fn (?Albaran $record): string => __('albaranes.locked_notice'))
                ->color('warning')->visible($locked)->columnSpanFull(),
            Section::make(__('albaranes.sections.client'))->columns(2)->disabled($locked)->schema([
                Select::make('party_id')->label(__('albaranes.fields.owner'))->required()
                    ->relationship('party', 'name', fn (Builder $q) => $q->where('team_id', $team()))
                    ->searchable()->preload()->live()
                    ->default(fn (): ?int => self::prefill()['party_id'])
                    ->afterStateUpdated(function (?string $state, Set $set): void {
                        $party = $state ? Party::query()->find($state) : null;
                        foreach ($party ? Albaran::clientDataFor($party) : [] as $field => $value) {
                            $set($field, $value);
                        }
                    }),
                DatePicker::make('service_date')->label(__('albaranes.fields.service_date'))->required()->default(now()),
                Select::make('property_id')->label(__('albaranes.fields.property'))
                    ->relationship('property', 'title', fn (Builder $q) => $q->where('team_id', $team()))
                    ->getOptionLabelFromRecordUsing(fn (Property $p): string => $p->reference().' · '.$p->title)
                    ->searchable()->preload()->default(fn (): ?int => self::prefill()['property_id']),
                Select::make('instruction_id')->label(__('albaranes.fields.instruction'))
                    ->relationship('instruction', 'subject', fn (Builder $q, Get $get) => $q->where('team_id', $team())
                        ->when($get('party_id'), fn (Builder $q, $party) => $q->where(fn (Builder $q) => $q->where('party_id', $party)->orWhereNull('party_id'))))
                    ->searchable()->preload()->default(fn (): ?int => self::prefill()['instruction_id']),
                TextInput::make('client_name')->label(__('albaranes.fields.client_name'))->required()->maxLength(255)->default(fn (): ?string => self::prefill()['client_name'] ?? null),
                TextInput::make('client_tax_id')->label(__('albaranes.fields.client_tax_id'))->maxLength(20)->default(fn (): ?string => self::prefill()['client_tax_id'] ?? null),
                TextInput::make('client_email')->label(__('albaranes.fields.client_email'))->email()->maxLength(255)->default(fn (): ?string => self::prefill()['client_email'] ?? null),
                TextInput::make('client_phone')->label(__('albaranes.fields.client_phone'))->maxLength(50)->default(fn (): ?string => self::prefill()['client_phone'] ?? null),
                TextInput::make('client_address')->label(__('albaranes.fields.client_address'))->maxLength(255)->columnSpanFull()->default(fn (): ?string => self::prefill()['client_address'] ?? null),
            ]),
            Section::make(__('albaranes.sections.lines'))->disabled($locked)
                ->description(__('albaranes.lines_help'))
                ->schema([
                    Repeater::make('lines')->hiddenLabel()->relationship()->orderColumn('sort')->minItems(1)->defaultItems(1)->columns(12)
                        ->schema([
                            Select::make('service_code')->label(__('albaranes.fields.service'))->columnSpan(3)
                                ->options(fn (): array => BillingCatalogue::options())->placeholder(__('albaranes.free_line'))->live()
                                ->afterStateUpdated(function (?string $state, Set $set): void {
                                    if ($service = BillingCatalogue::services()[$state] ?? null) {
                                        $set('description', $service['nombre']);
                                        $set('unit_price', $service['precio']);
                                    }
                                }),
                            TextInput::make('description')->label(__('albaranes.fields.description'))->required()->maxLength(500)->columnSpan(4),
                            TextInput::make('quantity')->label(__('albaranes.fields.quantity'))->numeric()->required()->minValue(0.01)->default(1)->columnSpan(1),
                            TextInput::make('unit_price')->label(__('albaranes.fields.unit_price'))->numeric()->required()->default(0)->suffix('€')->columnSpan(2),
                            TextInput::make('discount_pct')->label(__('albaranes.fields.discount'))->numeric()->minValue(0)->maxValue(100)->default(0)->suffix('%')->columnSpan(2),
                        ]),
                    Text::make(function (Get $get): string {
                        $total = collect((array) $get('lines'))->sum(fn (array $l): float => AlbaranLine::amountOf((float) ($l['quantity'] ?? 0), (float) ($l['unit_price'] ?? 0), (float) ($l['discount_pct'] ?? 0)));

                        return __('albaranes.total_before_taxes', ['total' => number_format($total, 2, ',', '.').' €']);
                    })->weight('bold'),
                    Textarea::make('notes')->label(__('albaranes.fields.notes'))->rows(2),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->label(__('albaranes.fields.number'))->searchable()->sortable(),
                TextColumn::make('client_name')->label(__('albaranes.fields.client_name'))->searchable(),
                TextColumn::make('property_id')->label(__('albaranes.fields.property'))->formatStateUsing(fn (Albaran $record): string => $record->property?->reference() ?? '')->placeholder('—'),
                TextColumn::make('service_date')->label(__('albaranes.fields.service_date'))->date('d/m/Y')->sortable(),
                TextColumn::make('total')->label(__('albaranes.fields.total'))->money('EUR'),
                TextColumn::make('status')->label(__('albaranes.fields.status'))->badge()
                    ->formatStateUsing(fn (string $state): string => __('albaranes.statuses.'.$state))
                    ->color(fn (string $state): string => Albaran::COLORS[$state] ?? 'gray')
                    ->tooltip(fn (Albaran $record): ?string => $record->last_error),
                TextColumn::make('conformity')->label(__('albaranes.fields.conformity'))->badge()
                    ->formatStateUsing(fn (string $state): string => __('albaranes.conformity.'.$state))
                    ->color(fn (string $state): string => match ($state) { Albaran::CONFORMITY_PENDING => 'warning', Albaran::CONFORMITY_SIGNED => 'success', default => 'gray' })
                    ->toggleable(),
                TextColumn::make('invoice_number')->label(__('albaranes.fields.invoice_number'))->placeholder('—'),
                TextColumn::make('invoice_date')->label(__('albaranes.fields.invoice_date'))->date('d/m/Y')->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('albaranes.fields.status'))
                    ->options(collect(Albaran::STATUSES)->mapWithKeys(fn (string $s): array => [$s => __('albaranes.statuses.'.$s)])->all()),
                SelectFilter::make('party_id')->label(__('albaranes.fields.owner'))->relationship('party', 'name')->searchable(),
                SelectFilter::make('property_id')->label(__('albaranes.fields.property'))->relationship('property', 'title')->searchable(),
            ])
            ->recordActions([
                EditAction::make(),
                AlbaranActions::pdf(),
                AlbaranActions::send(),
                AlbaranActions::refresh(),
                DeleteAction::make()->visible(fn (Albaran $record): bool => $record->status === Albaran::DRAFT && blank($record->external_id)),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /**
     * Prefill from ?party=, ?property=, ?instruction= (the owner, property or mandate the albarán was opened from).
     *
     * @return array<string, mixed>
     */
    public static function prefill(): array
    {
        $team = Filament::getTenant()?->getKey() ?? auth()->user()?->current_team_id;
        $instruction = request()->integer('instruction') ? Instruction::query()->where('team_id', $team)->find(request()->integer('instruction')) : null;
        $property = request()->integer('property') ? Property::query()->where('team_id', $team)->find(request()->integer('property')) : null;
        $partyId = request()->integer('party') ?: $instruction?->party_id;
        $party = $partyId ? Party::query()->where('team_id', $team)->find($partyId) : null;

        return [
            'party_id' => $party?->getKey(),
            'property_id' => $property?->getKey() ?? $instruction?->property_id,
            'instruction_id' => $instruction?->getKey(),
        ] + ($party ? Albaran::clientDataFor($party) : []);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAlbaranes::route('/'),
            'create' => Pages\CreateAlbaran::route('/create'),
            'edit' => Pages\EditAlbaran::route('/{record}/edit'),
        ];
    }
}
