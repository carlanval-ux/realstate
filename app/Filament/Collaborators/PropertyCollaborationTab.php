<?php

declare(strict_types=1);

namespace App\Filament\Collaborators;

use App\Collaborators\Collaborations;
use App\Collaborators\CollaboratorProperty;
use App\Collaborators\Import\ImportedProperty;
use Filament\Schemas\Components\Text;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
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
                Section::make(__('importacion.cleanup.title'))->icon('heroicon-o-sparkles')->collapsible()
                    ->visible(fn (?Property $record): bool => self::imported($record) !== null
                        && (filled(self::imported($record)?->review['fotos'] ?? null) || \App\Collaborators\Import\PhotoCleanup::query()->where('property_id', $record?->getKey())->exists()))
                    ->schema([Livewire::make(PropertyPhotoCleanups::class, fn (?Property $record): array => ['propertyId' => (int) $record?->getKey()])->key('clarto-marcas-agua')]),
                Section::make(__('importacion.extra.title'))->description(__('importacion.extra.help'))->icon('heroicon-o-arrow-down-tray')->collapsible()
                    ->visible(fn (?Property $record): bool => self::imported($record) !== null)
                    ->schema([Text::make(fn (?Property $record): HtmlString => self::importedData(self::imported($record)))]),
            ]);
    }

    private static function imported(?Property $record): ?ImportedProperty
    {
        return $record?->exists ? ImportedProperty::query()->where('property_id', $record->getKey())->where('team_id', $record->team_id)->with('collaborator')->first() : null;
    }

    /** «Otros datos» of an imported property as a two-column list (values escaped; lists and per-language texts expanded). */
    private static function importedData(?ImportedProperty $row): HtmlString
    {
        if ($row === null) {
            return new HtmlString('');
        }
        $rows = [__('importacion.extra.source') => ($row->collaborator?->name ?? '—').' · '.__('importacion.sources.'.$row->source).' · '.__('importacion.statuses.'.$row->status),
            __('importacion.extra.last_seen') => $row->last_seen_at?->timezone('Europe/Madrid')->format('d/m/Y H:i') ?? '—'] + ($row->extra ?? []);
        $html = '<dl class="grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-[14rem_1fr]">';
        foreach ($rows as $label => $value) {
            $value = is_array($value)
                ? implode('<br>', array_map(fn ($v, $k) => (is_string($k) ? '<strong>'.e(strtoupper($k)).':</strong> ' : '').nl2br(e(Str::limit((string) $v, 2000))), $value, array_keys($value)))
                : (preg_match('~^https?://\S+$~', (string) $value) ? '<a class="underline" href="'.e($value).'" target="_blank" rel="noopener noreferrer">'.e($value).'</a>' : e((string) $value));
            $html .= '<dt class="font-medium text-gray-500">'.e((string) $label).'</dt><dd>'.$value.'</dd>';
        }

        return new HtmlString($html.'</dl>');
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
