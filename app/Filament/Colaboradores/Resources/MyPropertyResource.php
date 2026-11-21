<?php

declare(strict_types=1);

namespace App\Filament\Colaboradores\Resources;

use App\Collaborators\CollaboratorAccess;
use App\Collaborators\CollaboratorProperty;
use App\Collaborators\CollaboratorView;
use App\Collaborators\PropertyUpdate;
use App\Collaborators\UpdateMessage;
use App\Filament\Colaboradores\Resources\MyPropertyResource\Pages\ListMyProperties;
use App\Filament\Colaboradores\Resources\MyPropertyResource\Pages\ViewMyProperty;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Liberu\RealEstate\Properties\Models\Property;

/**
 * «Mis inmuebles» of the collaborator panel: only properties assigned to the signed-in collaborator
 * (CollaboratorAccess::scopeProperties) — list, global search, record page and export share that one query.
 */
final class MyPropertyResource extends Resource
{
    protected static ?string $model = Property::class;

    protected static ?string $slug = 'inmuebles';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'title';

    public static function getNavigationLabel(): string
    {
        return __('colaboradores.panel.my_properties');
    }

    public static function getModelLabel(): string
    {
        return __('colaboradores.panel.my_property');
    }

    public static function getPluralModelLabel(): string
    {
        return __('colaboradores.panel.my_properties');
    }

    public static function getEloquentQuery(): Builder
    {
        return CollaboratorAccess::scopeProperties(Property::query()->with(['city', 'district']), auth()->user());
    }

    /**
     * Only CollaboratorAccess decides here (the staff Property policy and any permission given to the «colaborador»
     * role are ignored): list = an enabled collaborator, record = an active assignment, anything else = denied.
     */
    public static function getAuthorizationResponse(string|\UnitEnum $action, ?Model $record = null): \Illuminate\Auth\Access\Response
    {
        $action = $action instanceof \BackedEnum ? (string) $action->value : ($action instanceof \UnitEnum ? $action->name : $action);
        $allowed = match ($action) {
            'viewAny' => CollaboratorAccess::collaboratorFor(auth()->user()) !== null,
            'view' => $record instanceof Property && CollaboratorAccess::canSeeProperty(auth()->user(), $record),
            default => false,
        };

        return $allowed ? \Illuminate\Auth\Access\Response::allow() : \Illuminate\Auth\Access\Response::denyAsNotFound();
    }

    /** @return list<string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['title', 'postal_code'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return $record->reference().' · '.($record->title ?: UpdateMessage::place($record));
    }

    public static function assignment(Property $record): ?CollaboratorProperty
    {
        return CollaboratorAccess::assignment(auth()->user(), $record);
    }

    public static function table(Table $table): Table
    {
        $label = fn (string $group): \Closure => fn (?string $state): string => $state ? __('colaboradores.'.$group.'.'.$state) : '—';

        return $table
            ->emptyStateHeading(__('colaboradores.panel.empty'))
            ->columns([
                TextColumn::make('referencia')->label(__('colaboradores.fields.reference'))->state(fn (Property $record): string => $record->reference()),
                TextColumn::make('title')->label(__('colaboradores.fields.property'))->searchable()->wrap()
                    ->description(fn (Property $record): string => UpdateMessage::place($record)),
                TextColumn::make('relacion')->label(__('colaboradores.fields.relation'))->state(fn (Property $record): ?string => self::assignment($record)?->relation)->formatStateUsing($label('relations')),
                TextColumn::make('precio')->label(__('colaboradores.fields.authorised_price'))->state(fn (Property $record): string => UpdateMessage::eur(self::assignment($record)?->authorised_price)),
                TextColumn::make('disponibilidad')->label(__('colaboradores.fields.availability'))->badge()
                    ->state(fn (Property $record): ?string => self::assignment($record)?->availability)->formatStateUsing($label('availability'))
                    ->color(fn (?string $state): string => ['disponible' => 'success', 'reservado' => 'warning'][$state] ?? 'gray'),
                TextColumn::make('estado')->label(__('colaboradores.fields.commercial_status'))->badge()
                    ->state(fn (Property $record): ?string => self::assignment($record)?->commercial_status)->formatStateUsing($label('commercial')),
            ])
            ->recordActions([ViewAction::make()])
            ->defaultSort('id', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        $row = fn (?Property $record): array => $record && ($a = self::assignment($record)) ? CollaboratorView::row($record, $a) : [];
        $entry = fn (string $name, string $label, ?\Closure $format = null): TextEntry => TextEntry::make($name)->label(__($label))
            ->state(fn (?Property $record): mixed => ($v = $row($record)[$name] ?? null) === null || $v === '' ? '—' : ($format ? $format($v) : $v));

        return $schema->components([
            Section::make(__('colaboradores.panel.collaboration'))->columns(4)->schema([
                $entry('relacion', 'colaboradores.fields.relation', fn ($v) => __('colaboradores.relations.'.$v)),
                $entry('precio_autorizado', 'colaboradores.fields.authorised_price', fn ($v) => UpdateMessage::eur($v)),
                $entry('disponibilidad', 'colaboradores.fields.availability', fn ($v) => __('colaboradores.availability.'.$v)),
                $entry('estado_comercial', 'colaboradores.fields.commercial_status', fn ($v) => __('colaboradores.commercial.'.$v)),
            ]),
            Section::make(__('colaboradores.panel.data'))->columns(4)->schema([
                $entry('referencia', 'colaboradores.fields.reference'),
                $entry('titulo', 'colaboradores.fields.property'),
                $entry('tipo', 'colaboradores.fields.type'),
                $entry('ubicacion', 'colaboradores.fields.location'),
                $entry('superficie_m2', 'colaboradores.fields.area', fn ($v) => $v.' m²'),
                $entry('habitaciones', 'colaboradores.fields.rooms'),
                $entry('banos', 'colaboradores.fields.bathrooms'),
            ]),
            Section::make(__('colaboradores.panel.visits'))
                ->visible(fn (?Property $record): bool => self::updatesOf($record)->contains(fn (PropertyUpdate $u): bool => $u->visit_at !== null || $u->visit_status !== null))
                ->schema([Html::make(fn (?Property $record): string => self::visitsHtml(self::updatesOf($record)))]),
            Section::make(__('colaboradores.panel.follow_up'))
                ->schema([Html::make(fn (?Property $record): string => self::feedHtml(self::updatesOf($record)))]),
        ]);
    }

    /** @return \Illuminate\Support\Collection<int, PropertyUpdate> */
    public static function updatesOf(?Property $record): \Illuminate\Support\Collection
    {
        $assignment = $record ? self::assignment($record) : null;

        return $assignment ? CollaboratorView::updates($assignment) : collect();
    }

    private static function visitsHtml(\Illuminate\Support\Collection $updates): string
    {
        $rows = $updates->filter(fn (PropertyUpdate $u): bool => $u->visit_at !== null || $u->visit_status !== null)->map(fn (PropertyUpdate $u): string => '<tr>'
            .'<td style="padding:.4rem .6rem">'.e($u->visit_at?->timezone('Europe/Madrid')->format('d/m/Y H:i') ?? '—').'</td>'
            .'<td style="padding:.4rem .6rem">'.e($u->visit_status ? __('colaboradores.visit_statuses.'.$u->visit_status) : '—').'</td>'
            .'<td style="padding:.4rem .6rem">'.e((string) ($u->visit_result ?? '')).'</td>'
            .'<td style="padding:.4rem .6rem">'.e((string) ($u->next_steps ?? '')).'</td></tr>')->implode('');
        $th = collect(['date', 'visit_status', 'visit_result', 'next_steps'])->map(fn (string $f): string => '<th style="text-align:left;padding:.4rem .6rem;font-weight:600">'.e(__('colaboradores.fields.'.$f)).'</th>')->implode('');

        return '<div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:.875rem"><thead><tr>'.$th.'</tr></thead><tbody>'.$rows.'</tbody></table></div>';
    }

    private static function feedHtml(\Illuminate\Support\Collection $updates): string
    {
        if ($updates->isEmpty()) {
            return '<p style="opacity:.7">'.e(__('colaboradores.panel.no_updates')).'</p>';
        }

        return $updates->map(function (PropertyUpdate $u): string {
            $d = CollaboratorView::update($u);
            $lines = array_filter([
                $d['precio'] !== null ? __('colaboradores.fields.authorised_price').': '.UpdateMessage::eur($d['precio']) : null,
                $d['disponibilidad'] ? __('colaboradores.fields.availability').': '.__('colaboradores.availability.'.$d['disponibilidad']) : null,
                $u->visit_at || $u->visit_status ? __('colaboradores.update_kinds.visita').': '.($u->visit_at?->timezone('Europe/Madrid')->format('d/m/Y H:i') ?? '—').' · '.($u->visit_status ? __('colaboradores.visit_statuses.'.$u->visit_status) : '—') : null,
                $d['resultado_visita'] ? __('colaboradores.fields.visit_result').': '.$d['resultado_visita'] : null,
                $d['proximos_pasos'] ? __('colaboradores.fields.next_steps').': '.$d['proximos_pasos'] : null,
                $d['condiciones'] ? __('colaboradores.fields.conditions').': '.$d['condiciones'] : null,
            ]);
            $files = collect($d['adjuntos'])->map(fn (array $a): string => '<a href="'.e($a['url']).'" style="color:var(--primary-600);text-decoration:underline">'.e($a['nombre']).'</a>')->implode(' · ');

            return '<div style="padding:.75rem 0;border-bottom:1px solid rgba(128,128,128,.2)">'
                .'<div style="font-weight:600">'.e($u->title ?: __('colaboradores.update_kinds.'.$u->kind)).' <span style="font-weight:400;opacity:.7">· '.e($u->shared_at?->timezone('Europe/Madrid')->format('d/m/Y H:i') ?? '').'</span></div>'
                .($lines ? '<ul style="margin:.35rem 0 0 1rem;list-style:disc">'.implode('', array_map(fn (string $l): string => '<li>'.e($l).'</li>', $lines)).'</ul>' : '')
                .(filled($u->body) ? '<p style="margin-top:.35rem;white-space:pre-line">'.e($u->body).'</p>' : '')
                .($files !== '' ? '<p style="margin-top:.35rem">'.e(__('colaboradores.fields.attachments')).': '.$files.'</p>' : '')
                .'</div>';
        })->implode('');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMyProperties::route('/'),
            'view' => ViewMyProperty::route('/{record}'),
        ];
    }
}
