<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Collaborators\Collaborator;
use App\Filament\Resources\SearchRequestResource\Pages;
use App\Filament\Resources\SearchRequestResource\RelationManagers;
use App\Models\User;
use App\SearchRequests\SearchRequest;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

/**
 * «Solicitudes de inmuebles» for the team (staff panel, scoped to the company): draft a search, preview the e-mail
 * and WhatsApp, send it to one, several or every active collaborator, follow each one, compare proposals and continue
 * in the property record. Collaborators use App\Filament\Colaboradores\Resources\SearchRequestResource.
 */
final class SearchRequestResource extends Resource
{
    protected static ?string $model = SearchRequest::class;

    protected static ?string $slug = 'solicitudes-inmuebles';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-magnifying-glass-circle';

    protected static ?int $navigationSort = 45;

    protected static ?string $recordTitleAttribute = 'title';

    public static function getNavigationGroup(): ?string
    {
        return __('filament.nav_groups.sales_lettings');
    }

    public static function getNavigationLabel(): string
    {
        return __('solicitudes.menu');
    }

    public static function getModelLabel(): string
    {
        return __('solicitudes.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('solicitudes.plural');
    }

    public static function getNavigationBadge(): ?string
    {
        $n = SearchRequest::query()->forTeam(Filament::getTenant()?->getKey())->whereIn('status', SearchRequest::ACTIVE)->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return $record->status !== 'cerrada';
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return $record->status === 'borrador';
    }

    public static function form(Schema $schema): Schema
    {
        $f = fn (string $k): string => __('solicitudes.campo.'.$k);

        return $schema->columns(1)->components([
            Section::make(__('solicitudes.seccion.what'))->columns(3)->schema([
                TextInput::make('title')->label($f('title'))->required()->maxLength(160)->columnSpanFull(),
                Select::make('operation')->label($f('operation'))->required()->options(SearchRequest::options('operacion', SearchRequest::OPERATIONS)),
                Select::make('property_type')->label($f('property_type'))->required()->options(SearchRequest::options('tipo', SearchRequest::PROPERTY_TYPES)),
            ]),
            Section::make(__('solicitudes.seccion.where'))->columns(3)->schema([
                TextInput::make('municipality')->label($f('municipality'))->maxLength(120),
                TextInput::make('zones')->label($f('zones'))->maxLength(500),
                TextInput::make('address')->label($f('address'))->maxLength(250),
            ]),
            Section::make(__('solicitudes.seccion.size'))->columns(3)->schema([
                TextInput::make('surface_min')->label($f('surface_min'))->integer()->minValue(0),
                TextInput::make('surface_max')->label($f('surface_max'))->integer()->minValue(0)->gte('surface_min'),
                TextInput::make('bedrooms_min')->label($f('bedrooms_min'))->integer()->minValue(0)->maxValue(30),
                TextInput::make('budget_min')->label($f('budget_min'))->integer()->minValue(0),
                TextInput::make('budget_max')->label($f('budget_max'))->integer()->minValue(0)->gte('budget_min'),
                Textarea::make('features')->label($f('features'))->rows(2)->maxLength(2000)->columnSpanFull(),
                Textarea::make('must_have')->label($f('must_have'))->rows(2)->maxLength(2000),
                Textarea::make('preferences')->label($f('preferences'))->rows(2)->maxLength(2000),
            ]),
            Section::make(__('solicitudes.seccion.when'))->columns(2)->schema([
                DatePicker::make('needed_by')->label($f('needed_by')),
                DatePicker::make('respond_by')->label($f('respond_by'))->required()
                    ->default(fn () => now(SearchRequest::timezone())->addDays(SearchRequest::DEFAULT_DAYS)),
            ]),
            Section::make(__('solicitudes.seccion.more'))->schema([
                Textarea::make('description')->label($f('description'))->rows(4)->maxLength(5000),
                Repeater::make('example_links')->label($f('example_links'))->helperText(__('solicitudes.ayuda.example_links'))
                    ->simple(TextInput::make('url')->url()->required()->maxLength(500))->maxItems(10)->defaultItems(0),
                FileUpload::make('attachments')->label($f('attachments'))->helperText(__('solicitudes.ayuda.attachments'))
                    ->multiple()->appendFiles()->disk(SearchRequest::DISK)->directory('clarto/solicitudes')->visibility('private')
                    ->storeFileNamesIn('attachment_names')->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])
                    ->maxSize(20 * 1024)->maxFiles(10)->downloadable(false)->openable(false),
            ]),
            Section::make(__('solicitudes.seccion.who'))->columns(2)->schema([
                Select::make('owner_id')->label($f('owner'))->searchable()->default(fn () => auth()->id())
                    ->options(fn (): array => self::teamUsers()),
                Toggle::make('all_active')->label($f('all_active'))->live()->dehydrated(false)->visibleOn('create'),
                Select::make('collaborators')->label($f('recipients'))->helperText(__('solicitudes.ayuda.recipients'))
                    ->multiple()->searchable()->dehydrated(false)->visibleOn('create')
                    ->hidden(fn (Get $get): bool => (bool) $get('all_active'))
                    ->options(fn (): array => self::activeCollaborators()),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        $f = fn (string $k): string => __('solicitudes.campo.'.$k);
        $money = fn (?int $v): ?string => $v ? number_format($v, 0, ',', '.').' €' : null;

        return $schema->columns(1)->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('reference')->label($f('reference'))->weight('bold'),
                TextEntry::make('status')->label($f('status'))->badge()->formatStateUsing(fn (string $state) => SearchRequest::label('estado', $state))->color(fn (string $state) => self::statusColor($state)),
                TextEntry::make('respond_by')->label($f('respond_by'))->date('d/m/Y'),
                TextEntry::make('owner.name')->label($f('owner'))->placeholder('—'),
                TextEntry::make('operation')->label($f('operation'))->formatStateUsing(fn ($state) => SearchRequest::label('operacion', $state)),
                TextEntry::make('property_type')->label($f('property_type'))->formatStateUsing(fn ($state) => SearchRequest::label('tipo', $state)),
                TextEntry::make('municipality')->label($f('municipality'))->placeholder('—'),
                TextEntry::make('zones')->label($f('zones'))->placeholder('—'),
                TextEntry::make('budget')->label($f('budget'))->state(fn (SearchRequest $r) => trim(($money($r->budget_min) ?? '').' – '.($money($r->budget_max) ?? ''), ' –') ?: '—'),
                TextEntry::make('surface')->label($f('surface'))->state(fn (SearchRequest $r) => trim(($r->surface_min ?? '').' – '.($r->surface_max ?? ''), ' –').($r->surface_min || $r->surface_max ? ' m²' : '—')),
                TextEntry::make('bedrooms_min')->label($f('bedrooms_min'))->placeholder('—'),
                TextEntry::make('needed_by')->label($f('needed_by'))->date('d/m/Y')->placeholder('—'),
                TextEntry::make('address')->label($f('address'))->placeholder('—')->columnSpan(2),
                TextEntry::make('property')->label($f('property'))->columnSpan(2)->html()->placeholder('—')
                    ->state(fn (SearchRequest $r) => $r->property_id ? self::propertyLink($r->property_id) : null),
                TextEntry::make('features')->label($f('features'))->placeholder('—')->columnSpan(2),
                TextEntry::make('must_have')->label($f('must_have'))->placeholder('—'),
                TextEntry::make('preferences')->label($f('preferences'))->placeholder('—'),
                TextEntry::make('description')->label($f('description'))->placeholder('—')->columnSpanFull()->html()
                    ->formatStateUsing(fn (?string $state) => nl2br(e((string) $state))),
                TextEntry::make('example_links')->label($f('example_links'))->columnSpanFull()->html()->placeholder('—')
                    ->state(fn (SearchRequest $r) => collect($r->example_links ?? [])->map(fn ($l) => '<a href="'.e($l).'" target="_blank" rel="noopener noreferrer" class="underline">'.e($l).'</a>')->implode('<br>') ?: null),
                TextEntry::make('attachments')->label($f('attachments'))->columnSpanFull()->html()->placeholder('—')
                    ->state(fn (SearchRequest $r) => self::attachmentLinks($r, 'clarto.solicitudes.archivo')),
                TextEntry::make('outcome')->label($f('outcome'))->placeholder('—')->columnSpanFull()->visible(fn (SearchRequest $r) => filled($r->outcome)),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $f = fn (string $k): string => __('solicitudes.campo.'.$k);

        return $table
            ->columns([
                TextColumn::make('reference')->label($f('reference'))->searchable()->weight('bold'),
                TextColumn::make('title')->label($f('title'))->searchable()->limit(50)->wrap(),
                TextColumn::make('status')->label($f('status'))->badge()->formatStateUsing(fn (string $state) => SearchRequest::label('estado', $state))->color(fn (string $state) => self::statusColor($state)),
                TextColumn::make('municipality')->label($f('municipality'))->toggleable(),
                TextColumn::make('respond_by')->label($f('respond_by'))->date('d/m/Y')->sortable(),
                TextColumn::make('recipients_count')->label($f('recipients'))->counts(['recipients' => fn ($q) => $q->whereIn('status', ['enviada', 'respondida'])]),
                TextColumn::make('responses_count')->label($f('responses'))->counts('responses'),
                TextColumn::make('owner.name')->label($f('owner'))->toggleable(),
                TextColumn::make('updated_at')->label($f('updated_at'))->since()->sortable(),
            ])
            ->filters([SelectFilter::make('status')->label($f('status'))->options(SearchRequest::options('estado', SearchRequest::STATUSES))])
            ->recordActions([ViewAction::make()])
            ->defaultSort('updated_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\RecipientsRelationManager::class,
            RelationManagers\ResponsesRelationManager::class,
            RelationManagers\NoticesRelationManager::class,
            RelationManagers\EventsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSearchRequests::route('/'),
            'create' => Pages\CreateSearchRequest::route('/create'),
            'view' => Pages\ViewSearchRequest::route('/{record}'),
            'edit' => Pages\EditSearchRequest::route('/{record}/edit'),
        ];
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            'abierta' => 'info',
            'en_evaluacion' => 'warning',
            'cubierta' => 'success',
            'cerrada' => 'gray',
            default => 'gray',
        };
    }

    /** @return array<int, string> active collaborators of the current company */
    public static function activeCollaborators(): array
    {
        return Collaborator::query()->where('team_id', Filament::getTenant()?->getKey())->active()->orderBy('name')->pluck('name', 'id')->all();
    }

    /** @return array<int, string> */
    public static function teamUsers(): array
    {
        $team = Filament::getTenant();
        if ($team === null) {
            return [];
        }

        return User::query()->where(fn ($q) => $q->whereKey($team->user_id)->orWhereHas('teams', fn ($t) => $t->whereKey($team->getKey())))
            ->orderBy('name')->pluck('name', 'id')->all();
    }

    public static function attachmentLinks(SearchRequest $r, string $route): ?string
    {
        return collect($r->attachments ?? [])->values()->map(fn (string $path, int $i) => '<a href="'.e(route($route, ['searchRequest' => $r->getKey(), 'response' => 0, 'index' => $i]))
            .'" target="_blank" rel="noopener" class="underline">'.e($r->attachment_names[$path] ?? basename($path)).'</a>')->implode('<br>') ?: null;
    }

    private static function propertyLink(int $propertyId): HtmlString
    {
        $property = \Liberu\RealEstate\Properties\Models\Property::query()->find($propertyId);

        return new HtmlString($property === null ? '—' : '<a class="underline" href="'.e(\Liberu\RealEstate\PropertiesFilament\Resources\PropertyResource::getUrl('edit', ['record' => $property])).'">'
            .e($property->reference().' · '.($property->title ?: $property->address)).'</a>');
    }
}
