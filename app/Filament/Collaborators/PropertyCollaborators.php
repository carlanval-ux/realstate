<?php

declare(strict_types=1);

namespace App\Filament\Collaborators;

use App\Collaborators\Collaborations;
use App\Collaborators\Collaborator;
use App\Collaborators\CollaboratorProperty;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Select;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Liberu\RealEstate\Properties\Models\Property;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** Collaborators of one property (staff): assign one or several, withdraw. Only the user's current team. */
final class PropertyCollaborators extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    #[Locked]
    public int $propertyId;

    public function mount(int $propertyId): void
    {
        $this->propertyId = $propertyId;
        $this->property();
    }

    public function property(): Property
    {
        abort_unless(CollaboratorUi::staff(), 403);

        return Property::query()->where('team_id', CollaboratorUi::teamId())->findOrFail($this->propertyId);
    }

    public function table(Table $table): Table
    {
        $property = $this->property();

        return $table
            ->query(CollaboratorProperty::query()->with(['collaborator', 'assignedBy'])->where('team_id', $property->team_id)->where('property_id', $property->getKey())
                ->orderByRaw('revoked_at is not null')->orderByDesc('assigned_at'))
            ->paginated([10, 25])
            ->emptyStateHeading(__('colaboradores.messages.no_recipients'))
            ->emptyStateIcon('heroicon-o-user-group')
            ->columns(PropertyCollaborationTab::assignmentColumns())
            ->headerActions([
                Action::make('assign')->label(__('colaboradores.actions.assign'))->icon('heroicon-m-plus')
                    ->visible(fn (): bool => Collaborations::enabled())
                    ->schema([
                        Select::make('collaborator_ids')->label(__('colaboradores.fields.collaborator'))->multiple()->required()->native(false)
                            ->options(fn (): array => CollaboratorUi::collaboratorOptions()),
                        ...PropertyCollaborationTab::assignFields($property),
                    ])
                    ->action(function (array $data): void {
                        $property = $this->property();
                        foreach (Collaborator::query()->where('team_id', $property->team_id)->whereKey($data['collaborator_ids'])->get() as $collaborator) {
                            CollaboratorUi::attempt(fn () => Collaborations::assign($property, $collaborator, $data['relation'], $data['authorised_price'] ?? null, (bool) ($data['announce'] ?? false), $data['summary'] ?? null),
                                __('colaboradores.messages.assigned', ['name' => $collaborator->name]));
                        }
                        $this->dispatch('clarto-colaboradores-cambio');
                    }),
            ])
            ->recordActions([PropertyCollaborationTab::revokeAction()]);
    }

    public function render(): View
    {
        return view('filament.collaborators.table');
    }
}
