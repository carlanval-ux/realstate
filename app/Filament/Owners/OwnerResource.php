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

    public static function form(Schema $schema): Schema
    {
        $sensitive = OwnerProfile::canSeeSensitive();
        $keep = $sensitive ? null : __('propietarios.sensitive_keep');

        return $schema->columns(1)->components([
            Section::make(__('propietarios.sections.contact'))->columns(3)->schema([
                TextInput::make('name')->label(__('propietarios.fields.display_name'))->required()->maxLength(255),
                TextInput::make('email')->label(__('propietarios.fields.email'))->email()->maxLength(255),
                TextInput::make('phone')->label(__('propietarios.fields.phone'))->tel()->maxLength(50),
            ]),
            Group::make()->relationship('clartoOwnerProfile')
                ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => $data + ['team_id' => Filament::getTenant()?->getKey(), 'source' => 'panel'])
                ->schema([
                    Section::make(__('propietarios.sections.identity'))->columns(3)->schema([
                        Select::make('person_type')->label(__('propietarios.fields.person_type'))->required()->live()->default('fisica')
                            ->options(array_map('__', OwnerProfile::PERSON_TYPES)),
                        TextInput::make('tax_id')->label(fn (Get $get): string => $get('person_type') === 'juridica' ? __('propietarios.fields.cif') : __('propietarios.fields.nif_nie'))
                            ->formatStateUsing(fn (?string $state): ?string => $sensitive ? $state : null)
                            ->placeholder(fn (mixed $record): ?string => $sensitive ? null : SpanishIds::maskTaxId($record instanceof OwnerProfile ? $record->tax_id : null))
                            ->helperText($keep)
                            ->dehydrated(fn (?string $state): bool => $sensitive || filled($state))
                            ->maxLength(15)
                            ->rule(fn (Get $get, mixed $record): Closure => self::taxIdRule($get('person_type') === 'juridica' ? 'cif' : 'nif', $record instanceof OwnerProfile ? $record : null)),
                        TextInput::make('first_name')->label(__('propietarios.fields.first_name'))->maxLength(255)
                            ->visible(fn (Get $get): bool => $get('person_type') !== 'juridica'),
                        TextInput::make('last_name')->label(__('propietarios.fields.last_name'))->maxLength(255)
                            ->visible(fn (Get $get): bool => $get('person_type') !== 'juridica'),
                        TextInput::make('legal_name')->label(__('propietarios.fields.legal_name'))->maxLength(255)
                            ->visible(fn (Get $get): bool => $get('person_type') === 'juridica'),
                        TextInput::make('representative_name')->label(__('propietarios.fields.representative_name'))->maxLength(255)
                            ->visible(fn (Get $get): bool => $get('person_type') === 'juridica'),
                        TextInput::make('representative_tax_id')->label(__('propietarios.fields.representative_tax_id'))->maxLength(15)
                            ->visible(fn (Get $get): bool => $get('person_type') === 'juridica')
                            ->formatStateUsing(fn (?string $state): ?string => $sensitive ? $state : null)
                            ->placeholder(fn (mixed $record): ?string => $sensitive ? null : SpanishIds::maskTaxId($record instanceof OwnerProfile ? $record->representative_tax_id : null))
                            ->helperText($keep)
                            ->dehydrated(fn (?string $state): bool => $sensitive || filled($state))
                            ->rule(fn (): Closure => self::taxIdRule('nif', null)),
                    ]),
                    Section::make(__('propietarios.sections.preferences'))->columns(3)->schema([
                        TextInput::make('phone_secondary')->label(__('propietarios.fields.phone_secondary'))->tel()->maxLength(50),
                        Select::make('preferred_channel')->label(__('propietarios.fields.preferred_channel'))
                            ->options(self::options(OwnerProfile::CHANNELS, 'propietarios.channels.')),
                        Select::make('preferred_language')->label(__('propietarios.fields.preferred_language'))
                            ->options(self::options(OwnerProfile::LANGUAGES, 'propietarios.languages.')),
                    ]),
                    Section::make(__('propietarios.sections.fiscal_address'))->columns(3)->schema([
                        TextInput::make('fiscal_address')->label(__('propietarios.fields.address'))->maxLength(255)->columnSpan(3),
                        TextInput::make('fiscal_postal_code')->label(__('propietarios.fields.postal_code'))->maxLength(10)
                            ->rule('regex:/^(0[1-9]|[1-4]\d|5[0-2])\d{3}$/', fn (Get $get): bool => ($get('fiscal_country') ?: 'ES') === 'ES')
                            ->validationMessages(['regex' => __('propietarios.validation.postal_code')]),
                        TextInput::make('fiscal_city')->label(__('propietarios.fields.city'))->maxLength(255),
                        TextInput::make('fiscal_province')->label(__('propietarios.fields.province'))->maxLength(255),
                        TextInput::make('fiscal_country')->label(__('propietarios.fields.country'))->default('ES')->maxLength(2)->live(onBlur: true)
                            ->dehydrateStateUsing(fn (?string $state): ?string => $state ? strtoupper($state) : null),
                        Toggle::make('postal_same_as_fiscal')->label(__('propietarios.fields.postal_same_as_fiscal'))->default(true)->live()->columnSpan(2),
                    ]),
                    Section::make(__('propietarios.sections.postal_address'))->columns(3)
                        ->visible(fn (Get $get): bool => ! $get('postal_same_as_fiscal'))
                        ->schema([
                            TextInput::make('postal_address')->label(__('propietarios.fields.address'))->maxLength(255)->columnSpan(3),
                            TextInput::make('postal_postal_code')->label(__('propietarios.fields.postal_code'))->maxLength(10),
                            TextInput::make('postal_city')->label(__('propietarios.fields.city'))->maxLength(255),
                            TextInput::make('postal_province')->label(__('propietarios.fields.province'))->maxLength(255),
                            TextInput::make('postal_country')->label(__('propietarios.fields.country'))->maxLength(2)
                                ->dehydrateStateUsing(fn (?string $state): ?string => $state ? strtoupper($state) : null),
                        ]),
                    Section::make(__('propietarios.sections.consents'))->columns(3)
                        ->description(__('propietarios.consents_help', ['version' => OwnerProfile::CONSENT_VERSION]))
                        ->schema([
                            DateTimePicker::make('privacy_consent_at')->label(__('propietarios.fields.privacy_consent_at'))->seconds(false),
                            TextInput::make('privacy_consent_version')->label(__('propietarios.fields.consent_version'))->maxLength(20)
                                ->requiredWith('privacy_consent_at')->default(OwnerProfile::CONSENT_VERSION),
                            Select::make('consent_source')->label(__('propietarios.fields.consent_source'))
                                ->options(self::options(['web', 'ghl', 'papel', 'email', 'telefono'], 'propietarios.consent_sources.')),
                            DateTimePicker::make('marketing_consent_at')->label(__('propietarios.fields.marketing_consent_at'))->seconds(false),
                            TextInput::make('marketing_consent_version')->label(__('propietarios.fields.consent_version'))->maxLength(20)
                                ->requiredWith('marketing_consent_at'),
                        ]),
                    Section::make(__('propietarios.sections.bank'))->columns(2)->schema([
                        TextInput::make('iban')->label('IBAN')->maxLength(42)
                            ->formatStateUsing(fn (?string $state): ?string => $sensitive ? SpanishIds::formatIban($state) : null)
                            ->placeholder(fn (mixed $record): ?string => $sensitive ? null : SpanishIds::maskIban($record instanceof OwnerProfile ? $record->iban : null))
                            ->helperText($keep)
                            ->dehydrated(fn (?string $state): bool => $sensitive || filled($state))
                            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                if (filled($value) && ! SpanishIds::isValidIban((string) $value)) {
                                    $fail(__('propietarios.validation.iban'));
                                }
                            }),
                        TextInput::make('iban_holder')->label(__('propietarios.fields.iban_holder'))->maxLength(255),
                    ]),
                    Section::make(__('propietarios.sections.notes'))->columns(1)->schema([
                        TagsInput::make('tags')->label(__('propietarios.fields.tags')),
                        Textarea::make('notes')->label(__('propietarios.fields.notes'))->rows(4),
                    ]),
                ]),
        ]);
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

    /** NIF/NIE (or CIF for a company) with checksum, and not already used by another owner of the team. */
    private static function taxIdRule(string $expected, ?OwnerProfile $record): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($expected, $record): void {
            if (blank($value)) {
                return;
            }
            $type = SpanishIds::taxIdType((string) $value);
            if ($type === null) {
                $fail(__('propietarios.validation.tax_id'));

                return;
            }
            if ($expected === 'cif' && $type !== 'cif') {
                $fail(__('propietarios.validation.cif_required'));

                return;
            }
            if ($expected === 'nif' && $type === 'cif') {
                $fail(__('propietarios.validation.nif_required'));

                return;
            }
            if (str_ends_with($attribute, '.tax_id') || $attribute === 'tax_id') {
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
