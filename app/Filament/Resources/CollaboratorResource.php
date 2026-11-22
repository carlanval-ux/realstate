<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Collaborators\Collaborations;
use App\Collaborators\Collaborator;
use App\Filament\Collaborators\CollaboratorImportUi;
use App\Filament\Collaborators\CollaboratorUi;
use App\Filament\Resources\CollaboratorResource\Pages\CreateCollaborator;
use App\Filament\Resources\CollaboratorResource\Pages\EditCollaborator;
use App\Filament\Resources\CollaboratorResource\Pages\ListCollaborators;
use App\Filament\Resources\CollaboratorResource\RelationManagers\AssignmentsRelationManager;
use App\Filament\Resources\CollaboratorResource\RelationManagers\NoticesRelationManager;
use App\Models\User;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Unique;

/** «Colaboradores» (staff): external agents and companies, their access to the collaborator panel and assignments. */
final class CollaboratorResource extends Resource
{
    protected static ?string $model = Collaborator::class;

    protected static ?string $slug = 'colaboradores';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static ?int $navigationSort = 45;

    protected static ?string $recordTitleAttribute = 'name';

    public static function isScopedToTenant(): bool
    {
        return false;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('filament.nav_groups.people_relationships');
    }

    public static function getNavigationLabel(): string
    {
        return __('colaboradores.menu');
    }

    public static function getModelLabel(): string
    {
        return __('colaboradores.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('colaboradores.plural');
    }

    public static function canViewAny(): bool
    {
        return CollaboratorUi::staff();
    }

    public static function canCreate(): bool
    {
        return CollaboratorUi::staff() && Collaborations::enabled();
    }

    public static function canEdit(Model $record): bool
    {
        return CollaboratorUi::staff() && (int) $record->team_id === CollaboratorUi::teamId();
    }

    public static function canDelete(Model $record): bool
    {
        return false;   // «Dar de baja» keeps the history
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('team_id', CollaboratorUi::teamId())->withCount(['activeAssignments']);
    }

    /** @return list<string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'legal_name', 'email', 'whatsapp'];
    }

    public static function form(Schema $schema): Schema
    {
        $f = fn (string $k): string => __('colaboradores.fields.'.$k);
        $company = fn (Get $get): bool => $get('kind') === 'empresa';

        return $schema->components([
            Section::make(__('colaboradores.sections.contact'))->columns(2)->schema([
                ToggleButtons::make('kind')->label($f('kind'))->options(CollaboratorUi::options('kinds', Collaborator::KINDS))->default('persona')->inline()->required()->live()->columnSpanFull(),
                TextInput::make('name')->label(fn (Get $get): string => $company($get) ? $f('name_company') : $f('name'))->required()->maxLength(190),
                TextInput::make('legal_name')->label($f('legal_name'))->maxLength(190)->visible($company),
                TextInput::make('tax_id')->label($f('tax_id'))->maxLength(32),
                TextInput::make('email')->label($f('email'))->email()->maxLength(190)
                    ->unique(table: 'clarto_collaborators', column: 'email', ignoreRecord: true, modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('team_id', CollaboratorUi::teamId())),
                TextInput::make('whatsapp')->label($f('whatsapp'))->tel()->maxLength(32)->placeholder('+34 600 000 000'),
                Select::make('locale')->label($f('locale'))->options(CollaboratorUi::options('locales', ['es', 'en']))->default('es')->required()->native(false),
                Repeater::make('contacts')->label($f('contacts'))->visible($company)->defaultItems(0)->columns(4)->columnSpanFull()->schema([
                    TextInput::make('name')->label($f('contact_name'))->required()->maxLength(120),
                    TextInput::make('role')->label($f('contact_role'))->maxLength(80),
                    TextInput::make('email')->label($f('contact_email'))->email()->maxLength(190),
                    TextInput::make('phone')->label($f('contact_phone'))->tel()->maxLength(32),
                ]),
            ]),
            Section::make(__('colaboradores.sections.access'))->columns(2)->schema([
                Select::make('owner_id')->label($f('owner'))->native(false)->searchable()
                    ->options(fn (): array => User::query()->whereHas('teams', fn (Builder $q) => $q->whereKey(CollaboratorUi::teamId()))
                        ->orWhereKey(\Filament\Facades\Filament::getTenant()?->user_id)->orderBy('name')->pluck('name', 'id')->all())
                    ->default(fn (): ?int => auth()->id()),
                TextInput::make('status')->label($f('status'))->disabled()->dehydrated(false)
                    ->formatStateUsing(fn (?string $state): string => __('colaboradores.statuses.'.($state ?: Collaborator::PENDING)))
                    ->visibleOn('edit'),
                Textarea::make('notes')->label($f('notes'))->helperText($f('notes_help'))->rows(3)->columnSpanFull(),
            ]),
            CollaboratorImportUi::section(),
        ]);
    }

    public static function table(Table $table): Table
    {
        $f = fn (string $k): string => __('colaboradores.fields.'.$k);

        return $table
            ->columns([
                TextColumn::make('name')->label($f('name'))->searchable()->sortable()->description(fn (Collaborator $record): ?string => $record->legal_name),
                TextColumn::make('kind')->label($f('kind'))->formatStateUsing(CollaboratorUi::label('kinds')),
                TextColumn::make('email')->label($f('email'))->searchable()->placeholder('—'),
                TextColumn::make('whatsapp')->label($f('whatsapp'))->searchable()->placeholder('—'),
                TextColumn::make('owner.name')->label($f('owner'))->placeholder('—'),
                TextColumn::make('active_assignments_count')->label($f('properties')),
                TextColumn::make('status')->label($f('status'))->badge()->formatStateUsing(CollaboratorUi::label('statuses'))
                    ->color(fn (string $state): string => ['activo' => 'success', 'pendiente' => 'warning'][$state] ?? 'gray'),
            ])
            ->filters([SelectFilter::make('status')->label($f('status'))->options(CollaboratorUi::options('statuses', Collaborator::STATUSES))])
            ->recordActions([EditAction::make()])
            ->defaultSort('name');
    }

    public static function getRelations(): array
    {
        return [AssignmentsRelationManager::class, NoticesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCollaborators::route('/'),
            'create' => CreateCollaborator::route('/create'),
            'edit' => EditCollaborator::route('/{record}/edit'),
        ];
    }
}
