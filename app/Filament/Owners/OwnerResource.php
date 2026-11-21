<?php

declare(strict_types=1);

namespace App\Filament\Owners;

use App\Acquisition\GhlHandoff;
use App\Owners\OwnerProfile;
use App\Owners\SpanishIds;
use BackedEnum;
use Closure;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Liberu\RealEstate\Instructions\Models\Instruction;
use Liberu\RealEstate\Parties\Models\Party;
use UnitEnum;

/**
 * Propietarios: owners (Party vendor, or any Party with an owner profile) with their 360 profile. Staff only
 * (super_admin, admin, staff); NIF and IBAN in clear for administrators only. Owners are not deleted here.
 */
final class OwnerResource extends Resource
{
    protected static ?string $model = Party::class;

    protected static ?string $slug = 'propietarios';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-home-modern';

    protected static string|UnitEnum|null $navigationGroup = null;

    protected static ?string $navigationLabel = null;

    protected static ?int $navigationSort = 5;

    public static function getNavigationLabel(): string
    {
        return __('propietarios.title');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('filament.nav_groups.people_relationships');
    }

    public static function getModelLabel(): string
    {
        return __('propietarios.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('propietarios.title');
    }

    /** Replaces the Party policy for this screen: staff may list, view, create and edit owners; nobody deletes here. */
    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        $action = $action instanceof BackedEnum ? (string) $action->value : ($action instanceof UnitEnum ? $action->name : $action);

        return OwnerProfile::canAccess() && in_array($action, ['viewAny', 'view', 'create', 'update'], true)
            ? Response::allow()
            : Response::deny();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where(fn (Builder $q) => $q->where('type', 'vendor')->orWhereIn('id', OwnerProfile::query()->select('party_id')))
            ->with('clartoOwnerProfile');
    }

    /**
     * The owner profile form, after the CRM001 customer record (DEALER): identity by person type, typed id
     * document, contact rules, addresses with postcode ↔ province, consents with version and evidence, bank data.
     */
    public static function form(Schema $schema): Schema
    {
        $sensitive = OwnerProfile::canSeeSensitive();
        $keep = $sensitive ? null : __('propietarios.sensitive_keep');
        $company = fn (Get $get): bool => $get('person_type') === 'juridica';

        return $schema->columns(1)->components([
            Section::make(__('propietarios.sections.contact'))->columns(3)->schema([
                TextInput::make('name')->label(__('propietarios.fields.display_name'))->required()->maxLength(200)
                    ->rule(fn (Get $get, string $operation): Closure => self::duplicateRule($get, $operation)),
                TextInput::make('email')->label(__('propietarios.fields.email'))->maxLength(255)
                    ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? strtolower(trim($state)) : null)
                    ->rule(fn (): Closure => self::check([SpanishIds::class, 'isValidEmail'], 'propietarios.validation.email')),
                TextInput::make('phone')->label(__('propietarios.fields.phone'))->tel()->maxLength(50)
                    ->rule(fn (): Closure => self::check([SpanishIds::class, 'isValidPhone'], 'propietarios.validation.phone')),
                Checkbox::make('confirm_duplicate')->label(__('propietarios.confirm_duplicate'))->dehydrated(false)->columnSpanFull()
                    ->visibleOn('create'),
            ]),
            Group::make()->relationship('clartoOwnerProfile')
                ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => $data + ['team_id' => Filament::getTenant()?->getKey(), 'source' => 'panel'])
                ->schema([
                    Section::make(__('propietarios.sections.identity'))->columns(3)->schema([
                        Select::make('person_type')->label(__('propietarios.fields.person_type'))->required()->live()->default('fisica')
                            ->options(array_map('__', OwnerProfile::PERSON_TYPES))
                            ->afterStateUpdated(fn (Set $set) => $set('tax_id_type', null)),
                        Select::make('tax_id_type')->label(__('propietarios.fields.tax_id_type'))->live()
                            ->options(fn (Get $get): array => self::options($company($get) ? ['cif', 'other'] : ['nif', 'nie', 'passport', 'other'], 'propietarios.id_types.'))
                            ->helperText(__('propietarios.tax_id_type_help')),
                        TextInput::make('tax_id')->label(__('propietarios.fields.tax_id'))
                            ->formatStateUsing(fn (?string $state): ?string => $sensitive ? $state : null)
                            ->placeholder(fn (mixed $record): ?string => $sensitive ? null : SpanishIds::maskTaxId($record instanceof OwnerProfile ? $record->tax_id : null))
                            ->helperText($keep)
                            ->dehydrated(fn (?string $state): bool => $sensitive || filled($state))
                            ->maxLength(40)
                            ->rule(fn (Get $get, mixed $record): Closure => self::taxIdRule($get('tax_id_type'), $company($get), $record instanceof OwnerProfile ? $record : null, true)),
                        TextInput::make('first_name')->label(__('propietarios.fields.first_name'))->maxLength(200)
                            ->required(fn (Get $get): bool => ! $company($get))->visible(fn (Get $get): bool => ! $company($get)),
                        TextInput::make('last_name')->label(__('propietarios.fields.last_name'))->maxLength(200)
                            ->required(fn (Get $get): bool => ! $company($get))->visible(fn (Get $get): bool => ! $company($get)),
                        TextInput::make('second_last_name')->label(__('propietarios.fields.second_last_name'))->maxLength(200)
                            ->visible(fn (Get $get): bool => ! $company($get)),
                        DatePicker::make('birth_date')->label(__('propietarios.fields.birth_date'))
                            ->minDate('1900-01-01')->maxDate(now())->visible(fn (Get $get): bool => ! $company($get)),
                        TextInput::make('nationality')->label(__('propietarios.fields.nationality'))->maxLength(2)->placeholder('ES')
                            ->rule('regex:/^[A-Za-z]{2}$/')->validationMessages(['regex' => __('propietarios.validation.country')])
                            ->visible(fn (Get $get): bool => ! $company($get)),
                        TextInput::make('legal_name')->label(__('propietarios.fields.legal_name'))->maxLength(200)
                            ->required(fn (Get $get): bool => $company($get))->visible(fn (Get $get): bool => $company($get)),
                        TextInput::make('trading_name')->label(__('propietarios.fields.trading_name'))->maxLength(200)
                            ->visible(fn (Get $get): bool => $company($get)),
                        TextInput::make('representative_name')->label(__('propietarios.fields.representative_name'))->maxLength(200)
                            ->visible(fn (Get $get): bool => $company($get)),
                        TextInput::make('representative_tax_id')->label(__('propietarios.fields.representative_tax_id'))->maxLength(40)
                            ->visible(fn (Get $get): bool => $company($get))
                            ->formatStateUsing(fn (?string $state): ?string => $sensitive ? $state : null)
                            ->placeholder(fn (mixed $record): ?string => $sensitive ? null : SpanishIds::maskTaxId($record instanceof OwnerProfile ? $record->representative_tax_id : null))
                            ->helperText($keep)
                            ->dehydrated(fn (?string $state): bool => $sensitive || filled($state))
                            ->rule(fn (): Closure => self::taxIdRule(null, false, null, false)),
                    ]),
                    Section::make(__('propietarios.sections.preferences'))->columns(3)->schema([
                        TextInput::make('phone_secondary')->label(__('propietarios.fields.phone_secondary'))->tel()->maxLength(50)
                            ->rule(fn (): Closure => self::check([SpanishIds::class, 'isValidPhone'], 'propietarios.validation.phone')),
                        Select::make('preferred_channel')->label(__('propietarios.fields.preferred_channel'))
                            ->options(self::options(OwnerProfile::CHANNELS, 'propietarios.channels.')),
                        Select::make('preferred_language')->label(__('propietarios.fields.preferred_language'))
                            ->options(self::options(OwnerProfile::LANGUAGES, 'propietarios.languages.')),
                        TextInput::make('contact_window')->label(__('propietarios.fields.contact_window'))->maxLength(100)
                            ->placeholder(__('propietarios.contact_window_example'))->columnSpanFull(),
                    ]),
                    Section::make(__('propietarios.sections.fiscal_address'))->columns(3)->schema([
                        ...self::addressFields('fiscal'),
                        Toggle::make('postal_same_as_fiscal')->label(__('propietarios.fields.postal_same_as_fiscal'))->default(true)->live()->columnSpanFull(),
                    ]),
                    Section::make(__('propietarios.sections.postal_address'))->columns(3)
                        ->visible(fn (Get $get): bool => ! $get('postal_same_as_fiscal'))
                        ->schema(self::addressFields('postal')),
                    Section::make(__('propietarios.sections.consents'))->columns(3)
                        ->description(__('propietarios.consents_help', ['version' => OwnerProfile::CONSENT_VERSION]))
                        ->schema([
                            DateTimePicker::make('privacy_consent_at')->label(__('propietarios.fields.privacy_consent_at'))->seconds(false),
                            TextInput::make('privacy_consent_version')->label(__('propietarios.fields.consent_version'))->maxLength(20)
                                ->requiredWith('privacy_consent_at')->default(OwnerProfile::CONSENT_VERSION),
                            Select::make('consent_source')->label(__('propietarios.fields.consent_source'))
                                ->options(self::options(['web', 'ghl', 'papel', 'email', 'telefono'], 'propietarios.consent_sources.')),
                            DateTimePicker::make('marketing_consent_at')->label(__('propietarios.fields.marketing_consent_at'))->seconds(false)
                                ->helperText(__('propietarios.marketing_help')),
                            TextInput::make('marketing_consent_version')->label(__('propietarios.fields.consent_version'))->maxLength(20)
                                ->requiredWith('marketing_consent_at'),
                            Select::make('marketing_consent_channel')->label(__('propietarios.fields.marketing_consent_channel'))
                                ->options(self::options(OwnerProfile::CONSENT_CHANNELS, 'propietarios.consent_channels.'))
                                ->requiredWith('marketing_consent_at'),
                            Textarea::make('consent_evidence')->label(__('propietarios.fields.consent_evidence'))->maxLength(2000)->rows(2)->columnSpanFull(),
                        ]),
                    Section::make(__('propietarios.sections.bank'))->columns(2)->schema([
                        Select::make('payment_method')->label(__('propietarios.fields.payment_method'))
                            ->options(self::options(OwnerProfile::PAYMENT_METHODS, 'propietarios.payment_methods.')),
                        TextInput::make('iban_holder')->label(__('propietarios.fields.iban_holder'))->maxLength(200),
                        TextInput::make('iban')->label('IBAN')->maxLength(42)
                            ->formatStateUsing(fn (?string $state): ?string => $sensitive ? SpanishIds::formatIban($state) : null)
                            ->placeholder(fn (mixed $record): ?string => $sensitive ? null : SpanishIds::maskIban($record instanceof OwnerProfile ? $record->iban : null))
                            ->helperText($keep)
                            ->dehydrated(fn (?string $state): bool => $sensitive || filled($state))
                            ->rule(fn (): Closure => self::check([SpanishIds::class, 'isValidIban'], 'propietarios.validation.iban')),
                        TextInput::make('bic')->label('BIC')->maxLength(11)
                            ->rule(fn (): Closure => self::check([SpanishIds::class, 'isValidBic'], 'propietarios.validation.bic')),
                    ]),
                    Section::make(__('propietarios.sections.notes'))->columns(1)->schema([
                        TagsInput::make('tags')->label(__('propietarios.fields.tags')),
                        Textarea::make('notes')->label(__('propietarios.fields.notes'))->rows(4),
                    ]),
                ]),
        ]);
    }

    /**
     * Address, postcode, town, province, country. Spain: 5-digit postcode (01000–52999) that fills the province
     * and must agree with it.
     *
     * @return list<\Filament\Schemas\Components\Component>
     */
    private static function addressFields(string $prefix): array
    {
        $spain = fn (Get $get): bool => strtoupper((string) ($get($prefix.'_country') ?: 'ES')) === 'ES';

        return [
            TextInput::make($prefix.'_address')->label(__('propietarios.fields.address'))->maxLength(255)->columnSpanFull(),
            TextInput::make($prefix.'_postal_code')->label(__('propietarios.fields.postal_code'))->maxLength(10)->live(onBlur: true)
                ->afterStateUpdated(function (?string $state, Get $get, Set $set) use ($spain, $prefix): void {
                    if ($spain($get) && ($province = SpanishIds::provinceForPostalCode($state)) !== null) {
                        $set($prefix.'_province', $province);
                    }
                })
                ->rule(fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $spain, $prefix): void {
                    if (blank($value) || ! $spain($get)) {
                        return;
                    }
                    $province = SpanishIds::provinceForPostalCode((string) $value);
                    if ($province === null) {
                        $fail(__('propietarios.validation.postal_code'));
                    } elseif (filled($get($prefix.'_province')) && $get($prefix.'_province') !== $province) {
                        $fail(__('propietarios.validation.postal_code_province', ['province' => $province]));
                    }
                }),
            TextInput::make($prefix.'_city')->label(__('propietarios.fields.city'))->maxLength(120),
            TextInput::make($prefix.'_province')->label(__('propietarios.fields.province'))->maxLength(120)->datalist(array_values(SpanishIds::PROVINCES)),
            TextInput::make($prefix.'_country')->label(__('propietarios.fields.country'))->maxLength(2)->live(onBlur: true)
                ->default($prefix === 'fiscal' ? 'ES' : null)
                ->rule('regex:/^[A-Za-z]{2}$/')->validationMessages(['regex' => __('propietarios.validation.country')]),
        ];
    }

    /** A validation closure that fails with $message when $valid(value) is false (blank values pass). */
    private static function check(callable $valid, string $message): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($valid, $message): void {
            if (filled($value) && ! $valid((string) $value)) {
                $fail(__($message));
            }
        };
    }

    /** Creating an owner that looks like an existing one (id, e-mail, phone, company name) needs explicit confirmation. */
    private static function duplicateRule(Get $get, string $operation): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($get, $operation): void {
            if ($operation !== 'create' || $get('confirm_duplicate')) {
                return;
            }
            $found = OwnerProfile::duplicates(Filament::getTenant()?->getKey(), $get('email'), $get('phone'),
                $get('clartoOwnerProfile.tax_id'), $get('clartoOwnerProfile.person_type') === 'juridica' ? $get('clartoOwnerProfile.legal_name') : null);
            if ($found->isNotEmpty()) {
                $fail(__('propietarios.validation.duplicate', ['names' => $found->take(5)->pluck('name')->implode(', ')]));
            }
        };
    }

    public static function infolist(Schema $schema): Schema
    {
        return OwnerTabs::schema($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->addSelect(['active_mandates_count' => Instruction::query()->selectRaw('count(*)')
                ->whereColumn('party_id', 'real_estate_parties.id')->whereIn('status', OwnerProfile::ACTIVE_MANDATE)]))
            ->columns([
                TextColumn::make('code')->label(__('propietarios.fields.code'))
                    ->state(fn (Party $record): ?string => $record->clartoOwnerProfile?->code())->toggleable(),
                TextColumn::make('name')->label(__('propietarios.fields.display_name'))->searchable()->sortable()
                    ->description(fn (Party $record): ?string => $record->clartoOwnerProfile?->person_type === 'juridica' ? __('propietarios.person_types.juridica') : null),
                TextColumn::make('tax_id')->label(__('propietarios.fields.tax_id'))
                    ->state(fn (Party $record): ?string => $record->clartoOwnerProfile?->displayTaxId())
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->orWhereIn('id', OwnerProfile::query()->select('party_id')->where('tax_id', 'like', '%'.SpanishIds::normalize($search).'%'))),
                TextColumn::make('email')->label(__('propietarios.fields.email'))->searchable()->toggleable(),
                TextColumn::make('phone')->label(__('propietarios.fields.phone'))->searchable()->toggleable(),
                TextColumn::make('active_mandates_count')->label(__('propietarios.fields.active_mandates'))->badge()->sortable()
                    ->color(fn (int $state): string => $state > 0 ? 'success' : 'gray'),
                TextColumn::make('missing')->label(__('propietarios.fields.missing'))->badge()
                    ->state(fn (Party $record): int => count(($record->clartoOwnerProfile ?? new OwnerProfile(['person_type' => 'fisica']))->missingFields($record)))
                    ->formatStateUsing(fn (int $state): string => $state === 0 ? __('propietarios.complete') : trans_choice('propietarios.missing_count', $state, ['count' => $state]))
                    ->color(fn (int $state): string => $state === 0 ? 'success' : 'warning'),
                TextColumn::make('created_at')->label(__('propietarios.fields.created_at'))->date('d/m/Y')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('active_mandate')->label(__('propietarios.filters.active_mandate'))
                    ->trueLabel(__('propietarios.filters.with_mandate'))->falseLabel(__('propietarios.filters.without_mandate'))
                    ->queries(
                        true: fn (Builder $query) => $query->whereIn('id', self::activeMandateOwners()),
                        false: fn (Builder $query) => $query->whereNotIn('id', self::activeMandateOwners()),
                    ),
                Filter::make('pending_link')->label(__('propietarios.filters.pending_link'))->toggle()
                    ->query(fn (Builder $query) => $query->whereIn('id', self::pendingCandidateIds())),
                Filter::make('incomplete')->label(__('propietarios.filters.incomplete'))->toggle()
                    ->query(fn (Builder $query) => $query->where(fn (Builder $q) => $q
                        ->whereNotIn('id', OwnerProfile::query()->select('party_id'))
                        ->orWhereIn('id', OwnerProfile::query()->select('party_id')->where(fn (Builder $p) => $p->whereNull('tax_id')->orWhereNull('iban')->orWhereNull('privacy_consent_at')->orWhereNull('fiscal_address'))))),
            ])
            ->recordUrl(fn (Party $record): string => self::getUrl('view', ['record' => $record]))
            ->recordActions([ViewAction::make(), EditAction::make()])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOwners::route('/'),
            'create' => Pages\CreateOwner::route('/create'),
            'view' => Pages\ViewOwner::route('/{record}'),
            'edit' => Pages\EditOwner::route('/{record}/edit'),
        ];
    }

    private static function activeMandateOwners(): \Illuminate\Database\Query\Builder
    {
        return Instruction::query()->toBase()->select('party_id')->whereNotNull('party_id')->whereNull('deleted_at')->whereIn('status', OwnerProfile::ACTIVE_MANDATE);
    }

    /** @return list<int> owners proposed as candidates by a file waiting to be linked */
    private static function pendingCandidateIds(): array
    {
        return GhlHandoff::query()->where('status', 'pendiente')->pluck('candidate_party_ids')->flatten()->map(fn ($id): int => (int) $id)->unique()->values()->all();
    }

    /**
     * @param  list<string>  $values
     * @return array<string, string>
     */
    private static function options(array $values, string $prefix): array
    {
        return array_combine($values, array_map(fn (string $v): string => __($prefix.$v), $values));
    }

    /**
     * Id document: the chosen type (or, when none is chosen, a Spanish NIF/NIE/CIF recognised by its control
     * digit) must be valid; a company uses CIF (or "other"), a person NIF, NIE, passport or "other". The main id is
     * not repeated within the team.
     */
    private static function taxIdRule(?string $type, bool $company, ?OwnerProfile $record, bool $unique): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($type, $company, $record, $unique): void {
            if (blank($value)) {
                return;
            }
            // NIF, NIE and CIF are recognised by their control character whatever Spanish type was chosen.
            $type = in_array($type, ['passport', 'other'], true) ? $type : (SpanishIds::taxIdType((string) $value) ?? ($type ?: null));
            if ($type === null || ! SpanishIds::isValidId($type, (string) $value)) {
                $fail(__('propietarios.validation.tax_id_'.($type ?? 'unknown')));

                return;
            }
            if ($company && ! in_array($type, ['cif', 'other'], true)) {
                $fail(__('propietarios.validation.cif_required'));

                return;
            }
            if (! $company && $type === 'cif') {
                $fail(__('propietarios.validation.nif_required'));

                return;
            }
            if ($unique) {
                $taken = OwnerProfile::query()
                    ->where('team_id', Filament::getTenant()?->getKey() ?? $record?->team_id)
                    ->where('tax_id', SpanishIds::normalize((string) $value))
                    ->when($record?->exists, fn (Builder $q) => $q->whereKeyNot($record->getKey()))
                    ->with('party')->first();
                if ($taken !== null) {
                    $fail(__('propietarios.validation.tax_id_taken', ['name' => $taken->party?->name ?? '#'.$taken->party_id]));
                }
            }
        };
    }
}
