<?php

declare(strict_types=1);

namespace App\Filament\Resources\CollaboratorResource\RelationManagers;

use App\Collaborators\Collaborations;
use App\Collaborators\Collaborator;
use App\Filament\Collaborators\CollaboratorUi;
use App\Filament\Collaborators\PropertyCollaborationTab;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Liberu\RealEstate\Properties\Models\Property;

/** Properties assigned to this collaborator (active and withdrawn), assign another one, withdraw. */
final class AssignmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'assignments';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('colaboradores.fields.properties');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('property')->orderByRaw('revoked_at is not null')->orderByDesc('assigned_at'))
            ->columns(PropertyCollaborationTab::assignmentColumns(withProperty: true))
            ->headerActions([
                Action::make('assign')->label(__('colaboradores.actions.assign_property'))->icon('heroicon-o-plus')
                    ->visible(fn (): bool => Collaborations::enabled() && $this->getOwnerRecord()->status !== Collaborator::INACTIVE)
                    ->schema([
                        Select::make('property_id')->label(__('colaboradores.fields.property'))->required()->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => Property::query()->where('team_id', $this->getOwnerRecord()->team_id)
                                ->where('title', 'like', '%'.$search.'%')->limit(30)->get()->mapWithKeys(fn (Property $p): array => [$p->getKey() => $p->reference().' · '.$p->title])->all())
                            ->getOptionLabelUsing(fn ($value): ?string => ($p = Property::query()->where('team_id', $this->getOwnerRecord()->team_id)->find($value)) ? $p->reference().' · '.$p->title : null),
                        ...PropertyCollaborationTab::assignFields(),
                    ])
                    ->action(function (array $data): void {
                        $property = Property::query()->where('team_id', $this->getOwnerRecord()->team_id)->findOrFail($data['property_id']);
                        CollaboratorUi::attempt(fn () => Collaborations::assign($property, $this->getOwnerRecord(), $data['relation'], $data['authorised_price'] ?? null, (bool) ($data['announce'] ?? false), $data['summary'] ?? null),
                            __('colaboradores.messages.assigned', ['name' => $this->getOwnerRecord()->name]));
                    }),
            ])
            ->recordActions([PropertyCollaborationTab::revokeAction()])
            ->emptyStateHeading(__('colaboradores.panel.empty'));
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return CollaboratorUi::staff();
    }
}
