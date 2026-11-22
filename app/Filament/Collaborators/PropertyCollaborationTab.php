<?php

declare(strict_types=1);

namespace App\Filament\Collaborators;

use App\Collaborators\Collaborations;
use App\Collaborators\CollaboratorProperty;
use App\Collaborators\UpdateMessage;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Tables\Columns\TextColumn;
use Liberu\RealEstate\Properties\Models\Property;

/** «Colaboradores» tab of the property form: who collaborates on it, and its follow-up (internal notes and shared updates). */
final class PropertyCollaborationTab
{
    public static function tab(): Tab
    {
        return Tab::make(__('colaboradores.tab'))->icon('heroicon-o-user-group')
            ->visible(fn (?Property $record): bool => $record?->exists === true && CollaboratorUi::staff())
            ->schema([
                Section::make(__('colaboradores.sections.assignments'))->description(__('colaboradores.sections.assignments_help'))
                    ->schema([Livewire::make(PropertyCollaborators::class, fn (?Property $record): array => ['propertyId' => (int) $record?->getKey()])->key('clarto-colaboradores-asignaciones')]),
                Section::make(__('colaboradores.sections.updates'))->description(__('colaboradores.sections.updates_help'))
                    ->schema([Livewire::make(PropertyUpdates::class, fn (?Property $record): array => ['propertyId' => (int) $record?->getKey()])->key('clarto-colaboradores-seguimiento')]),
            ]);
    }

    /** @return list<TextColumn> */
    public static function assignmentColumns(bool $withProperty = false): array
    {
        $f = fn (string $k): string => __('colaboradores.fields.'.$k);

        return array_values(array_filter([
            $withProperty
                ? TextColumn::make('property.title')->label($f('property'))->wrap()
                    ->description(fn (CollaboratorProperty $record): string => ($record->property?->reference() ?? '').' · '.($record->property ? UpdateMessage::place($record->property) : ''))
                : TextColumn::make('collaborator.name')->label($f('collaborator'))->description(fn (CollaboratorProperty $record): string => __('colaboradores.kinds.'.$record->collaborator?->kind)),
            TextColumn::make('relation')->label($f('relation'))->formatStateUsing(CollaboratorUi::label('relations')),
            TextColumn::make('authorised_price')->label($f('authorised_price'))->formatStateUsing(fn ($state): string => UpdateMessage::eur($state))->placeholder('—'),
            TextColumn::make('availability')->label($f('availability'))->badge()->formatStateUsing(CollaboratorUi::label('availability'))->placeholder('—'),
            TextColumn::make('commercial_status')->label($f('commercial_status'))->badge()->formatStateUsing(CollaboratorUi::label('commercial'))->placeholder('—'),
            TextColumn::make('assigned_at')->label($f('assigned'))->dateTime('d/m/Y', 'Europe/Madrid')
                ->description(fn (CollaboratorProperty $record): ?string => $record->assignedBy?->name),
            TextColumn::make('revoked_at')->label($f('revoked'))->dateTime('d/m/Y', 'Europe/Madrid')->placeholder('—')->color('danger')
                ->description(fn (CollaboratorProperty $record): ?string => $record->revoke_reason),
        ]));
    }

    /** @return list<\Filament\Forms\Components\Field> */
    public static function assignFields(?Property $property = null): array
    {
        $f = fn (string $k): string => __('colaboradores.fields.'.$k);

        return [
            Select::make('relation')->label($f('relation'))->options(CollaboratorUi::options('relations', CollaboratorProperty::RELATIONS))->default('aportado')->required()->native(false),
            TextInput::make('authorised_price')->label($f('authorised_price'))->helperText($f('authorised_price_help'))->numeric()->minValue(0)->suffix('€')
                ->default(fn (): ?string => $property?->price !== null ? (string) $property->price : null),
            Toggle::make('announce')->label($f('announce'))->default(true)->live(),
            Textarea::make('summary')->label($f('summary'))->rows(3)->visible(fn ($get): bool => (bool) $get('announce')),
        ];
    }

    public static function revokeAction(): Action
    {
        return Action::make('revoke')->label(__('colaboradores.actions.revoke'))->icon('heroicon-o-x-circle')->color('danger')
            ->visible(fn (CollaboratorProperty $record): bool => $record->isActive())
            ->requiresConfirmation()->modalDescription(__('colaboradores.actions.revoke_help'))
            ->schema([TextInput::make('reason')->label(__('colaboradores.fields.reason'))->maxLength(190)])
            ->action(function (CollaboratorProperty $record, array $data): void {
                abort_unless((int) $record->team_id === CollaboratorUi::teamId(), 404);
                $n = Collaborations::revoke($record, $data['reason'] ?? null);
                \Filament\Notifications\Notification::make()->success()->title(__('colaboradores.messages.revoked', ['n' => $n]))->send();
            });
    }
}
