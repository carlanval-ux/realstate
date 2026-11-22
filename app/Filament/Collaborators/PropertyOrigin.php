<?php

declare(strict_types=1);

namespace App\Filament\Collaborators;

use App\Collaborators\Collaborator;
use App\Collaborators\CollaboratorProperty;
use App\Collaborators\Import\ImportedProperty;
use App\Filament\Resources\CollaboratorResource;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Callout;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Liberu\RealEstate\Properties\Models\Property;

/**
 * Staff side of collaborator-owned properties (imported, or contributed by a collaborator: active «aportado"
 * assignment): the notice at the top of the property record, the «Colaborador» column and the filters of the property
 * list. Three one-line hooks in the properties module's PropertyResource. Never used by anything public (white label).
 */
final class PropertyOrigin
{
    /** @var array{key: string, map: array<int, array{name: string, origin: string}>}|null */
    private static ?array $listCache = null;

    /**
     * @return ?array{collaborator: Collaborator, origin: string, reference: ?string, synced: ?\Illuminate\Support\Carbon, row: ?ImportedProperty}
     */
    public static function of(?Property $property): ?array
    {
        if ($property?->exists !== true) {
            return null;
        }
        $key = spl_object_id(request()).':'.$property->getKey();   // the notice asks several times per render

        if (! array_key_exists($key, self::$ofCache)) {
            self::$ofCache = count(self::$ofCache) > 200 ? [] : self::$ofCache;   // Octane: never grows
            self::$ofCache[$key] = self::find($property);
        }

        return self::$ofCache[$key];
    }

    /** @var array<string, ?array<string, mixed>> */
    private static array $ofCache = [];

    private static function find(Property $property): ?array
    {
        $row = ImportedProperty::query()->where('property_id', $property->getKey())->where('team_id', $property->team_id)->with('collaborator')->latest('id')->first();
        if ($row?->collaborator !== null) {
            $label = fn (string $locale): string => __('importacion.extra.reference', [], $locale);
            $reference = $row->source === 'feed' ? $row->external_ref : ($row->extra[$label('es')] ?? $row->extra[$label('en')] ?? null);

            return ['collaborator' => $row->collaborator, 'origin' => $row->source, 'reference' => $reference, 'synced' => $row->last_seen_at, 'row' => $row];
        }
        $assignment = CollaboratorProperty::query()->active()->where('property_id', $property->getKey())->where('team_id', $property->team_id)
            ->where('relation', 'aportado')->with('collaborator')->latest('id')->first();

        return $assignment?->collaborator !== null
            ? ['collaborator' => $assignment->collaborator, 'origin' => 'manual', 'reference' => null, 'synced' => null, 'row' => null]
            : null;
    }

    /** Prominent notice above the property form: whose property it is, its origin and pending review flags. */
    public static function notice(): Callout
    {
        return Callout::make(fn (?Property $record): string => __('importacion.origin.heading', ['name' => self::of($record)['collaborator']->name ?? '']))
            ->icon('heroicon-o-user-group')->columnSpanFull()
            ->color(fn (?Property $record): string => filled(self::of($record)['row']?->review ?? null) ? 'warning' : 'info')
            ->visible(fn (?Property $record): bool => self::of($record) !== null)
            ->description(fn (?Property $record): HtmlString => self::describe(self::of($record)))
            ->actions([
                Action::make('clarto_import_reviewed')->label(__('importacion.origin.reviewed'))->icon('heroicon-o-check')->color('gray')->size('sm')
                    ->visible(fn (?Property $record): bool => filled(self::of($record)['row']?->review ?? null))
                    ->requiresConfirmation()->modalDescription(__('importacion.origin.reviewed_help'))
                    ->action(function (?Property $record): void {
                        self::markReviewed($record);
                        Notification::make()->success()->title(__('importacion.origin.reviewed_done'))->send();
                    }),
            ]);
    }

    /** Clears the review flags (staff checked texts, tour and photos). */
    public static function markReviewed(?Property $property): bool
    {
        $row = self::of($property)['row'] ?? null;
        abort_unless($row === null || (int) $row->team_id === CollaboratorUi::teamId(), 404);
        if ($row === null || blank($row->review)) {
            return false;
        }
        Collaborator::log($property, 'importacion_revisado', ['colaborador' => $row->collaborator_id, 'motivos' => array_keys($row->review)]);
        $row->forceFill(['review' => null])->save();
        self::$ofCache = [];

        return true;
    }

    public static function column(): TextColumn
    {
        return TextColumn::make('clarto_colaborador')->label(__('importacion.origin.column'))->badge()->color('warning')->placeholder('—')
            ->state(fn (Property $record): ?string => self::listMap()[$record->getKey()]['name'] ?? null)
            ->description(fn (Property $record): ?string => isset(self::listMap()[$record->getKey()]) ? __('importacion.origin.origins.'.self::listMap()[$record->getKey()]['origin']) : null)
            ->toggleable();
    }

    /** @return list<SelectFilter> */
    public static function filters(): array
    {
        $o = fn (string $k): string => __('importacion.origin.'.$k);

        return [
            SelectFilter::make('clarto_origen')->label($o('filter_owner'))->options(['propio' => $o('own'), 'colaborador' => $o('collaborator')])
                ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                    'colaborador' => $query->whereIn($query->qualifyColumn('id'), self::owned()),
                    'propio' => $query->whereNotIn($query->qualifyColumn('id'), self::owned()),
                    default => $query,
                }),
            SelectFilter::make('clarto_colaborador')->label($o('filter_collaborator'))->searchable()
                ->options(fn (): array => Collaborator::query()->where('team_id', CollaboratorUi::teamId())->orderBy('name')->pluck('name', 'id')->all())
                ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null) ? $query->whereIn($query->qualifyColumn('id'), self::owned((int) $data['value'])) : $query),
            SelectFilter::make('clarto_importacion')->label($o('filter_source'))->options(['feed' => $o('origins.feed'), 'web' => $o('origins.web'), 'manual' => $o('origins.manual')])
                ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                    'feed', 'web' => $query->whereIn($query->qualifyColumn('id'), DB::table('clarto_imported_properties')->select('property_id')->where('source', $data['value'])),
                    'manual' => $query->whereIn($query->qualifyColumn('id'), self::assigned())
                        ->whereNotIn($query->qualifyColumn('id'), DB::table('clarto_imported_properties')->select('property_id')),
                    default => $query,
                }),
        ];
    }

    /** Property ids owned by a collaborator (imported or «aportado»), optionally by one. */
    private static function owned(?int $collaboratorId = null): QueryBuilder
    {
        return DB::table('clarto_imported_properties')->select('property_id')->when($collaboratorId, fn ($q) => $q->where('collaborator_id', $collaboratorId))
            ->union(self::assigned($collaboratorId));
    }

    private static function assigned(?int $collaboratorId = null): QueryBuilder
    {
        return DB::table('clarto_collaborator_properties')->select('property_id')->where('relation', 'aportado')->whereNull('revoked_at')
            ->when($collaboratorId, fn ($q) => $q->where('collaborator_id', $collaboratorId));
    }

    /** @return array<int, array{name: string, origin: string}> property id => collaborator, once per request and team */
    private static function listMap(): array
    {
        $teamId = CollaboratorUi::teamId();
        $key = spl_object_id(request()).':'.$teamId;
        if ((self::$listCache['key'] ?? null) !== $key) {   // Octane: a new request object → recomputed
            $map = [];
            foreach (ImportedProperty::query()->where('team_id', $teamId)->with('collaborator:id,name')->get() as $row) {
                $map[$row->property_id] = ['name' => (string) $row->collaborator?->name, 'origin' => $row->source];
            }
            foreach (CollaboratorProperty::query()->active()->where('team_id', $teamId)->where('relation', 'aportado')->with('collaborator:id,name')->get() as $a) {
                $map[$a->property_id] ??= ['name' => (string) $a->collaborator?->name, 'origin' => 'manual'];
            }
            self::$listCache = ['key' => $key, 'map' => $map];
        }

        return self::$listCache['map'];
    }

    /** @param ?array{collaborator: Collaborator, origin: string, reference: ?string, synced: ?\Illuminate\Support\Carbon, row: ?ImportedProperty} $origin */
    private static function describe(?array $origin): HtmlString
    {
        if ($origin === null) {
            return new HtmlString('');
        }
        $o = fn (string $k, array $r = []): string => __('importacion.origin.'.$k, $r);
        $parts = array_filter([
            $o('source', ['source' => $o('origins.'.$origin['origin'])]),
            $origin['reference'] ? $o('reference', ['ref' => $origin['reference']]) : null,
            $origin['synced'] ? $o('synced', ['date' => $origin['synced']->timezone('Europe/Madrid')->format('d/m/Y H:i')]) : null,
        ]);
        $url = CollaboratorResource::getUrl('edit', ['record' => $origin['collaborator']->getKey()]);
        $html = '('.e(implode(' · ', $parts)).') · <a class="underline font-medium" href="'.e($url).'">'.e($o('open')).'</a><br>'.e($o('white_label'));
        $review = $origin['row']?->review ?? [];
        $flags = array_filter([
            isset($review['textos']) ? $o('review_texts', ['items' => implode(', ', array_slice((array) $review['textos'], 0, 6))]) : null,
            isset($review['visita']) ? $o('review_tour') : null,
            isset($review['fotos']) ? $o('review_photos').' ('.$review['fotos'].')' : null,
        ]);
        if ($flags !== []) {
            $html .= '<ul class="mt-2 list-disc ps-5 font-medium">'.implode('', array_map(fn (string $f): string => '<li>'.e($f).'</li>', $flags)).'</ul>';
        }

        return new HtmlString($html);
    }
}
