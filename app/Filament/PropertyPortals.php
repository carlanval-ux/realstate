<?php

declare(strict_types=1);

namespace App\Filament;

use App\Filament\Collaborators\CollaboratorUi;
use App\Models\PortalListing;
use App\Portals\Portals;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Liberu\RealEstate\Properties\Models\Property;

/**
 * External portals (registry: App\Portals\Portals, config/portales.php) in the property form («Portales» tab, one
 * section per portal) and in the property list (column, filters «Publicado / No publicado / Con error en …», bulk
 * «Enviar a … / Retirar de …»). Nothing is sent automatically: only what staff choose, through Portals::run().
 */
final class PropertyPortals
{
    private const STATUS = [
        'no_enviado' => ['Not sent', 'gray'],
        PortalListing::SENT => ['Sent (not confirmed)', 'warning'],
        PortalListing::ACCEPTED => ['Accepted by the portal', 'info'],
        PortalListing::PUBLISHED => ['Published', 'success'],
        PortalListing::REJECTED => ['Rejected', 'danger'],
        PortalListing::WITHDRAWN => ['Withdrawn from the portal', 'gray'],
        PortalListing::ERROR => ['Error', 'danger'],
    ];

    /** On the portal (or on its way): what «Publicado en …» means. */
    public const LIVE = [PortalListing::SENT, PortalListing::ACCEPTED, PortalListing::PUBLISHED];

    /** @var array{key: string, map: array<int, array<string, string>>}|null */
    private static ?array $listCache = null;

    public static function tab(): Tab
    {
        return Tab::make(__('Portals'))
            ->icon('heroicon-o-globe-alt')
            ->visible(fn (?Property $record): bool => $record !== null)
            ->schema(array_map(fn (string $key): Section => self::section($key), array_keys(Portals::names())));
    }

    private static function section(string $key): Section
    {
        $name = Portals::name($key);
        $listing = fn (?Property $record): ?PortalListing => $record ? PortalListing::query()->where('property_id', $record->getKey())->where('portal', $key)->first() : null;
        $refusal = fn (?Property $record): ?string => $record ? Portals::refusal($key, $record) : null;

        return Section::make($name)
            ->description(fn (): string => Portals::enabled($key) ? (config("{$key}.driver") === 'api' && config("{$key}.enabled")
                ? __('Real sends are ON.')
                : __('portales.simulation_off', ['portal' => $name]))
                : (string) Portals::refusal($key, new Property))
            ->schema([
                Text::make(fn (?Property $record): string => self::summary($key, $listing($record)))->weight('medium'),
                Text::make(fn (?Property $record): ?string => filled($listing($record)?->last_error) ? __('Reason / last message: :m', ['m' => $listing($record)->last_error]) : null)
                    ->color('danger')->visible(fn (?Property $record): bool => filled($listing($record)?->last_error)),
                Text::make(fn (?Property $record): ?string => $refusal($record))->color('warning')->visible(fn (?Property $record): bool => filled($refusal($record))),
                Text::make(fn (?Property $record): string => __('Missing before sending: :m', ['m' => implode(' · ', $record ? Portals::connector($key)->missing($record) : [])]))
                    ->color('warning')->visible(fn (?Property $record): bool => $record !== null && Portals::connector($key)->missing($record) !== []),
                Actions::make([
                    self::action($key, 'publish', __('portales.actions.publish', ['portal' => $name]), 'heroicon-m-paper-airplane')
                        ->visible(fn (?Property $record): bool => Portals::enabled($key) && blank($listing($record)?->external_id))
                        ->disabled(fn (?Property $record): bool => filled($refusal($record)))->tooltip(fn (?Property $record): ?string => $refusal($record)),
                    self::action($key, 'update', __('Update'), 'heroicon-m-arrow-path')
                        ->visible(fn (?Property $record): bool => Portals::enabled($key) && filled($listing($record)?->external_id) && $listing($record)?->status !== PortalListing::WITHDRAWN)
                        ->disabled(fn (?Property $record): bool => filled($refusal($record)))->tooltip(fn (?Property $record): ?string => $refusal($record)),
                    self::action($key, 'withdraw', __('Withdraw'), 'heroicon-m-archive-box-x-mark')
                        ->color('danger')->requiresConfirmation()
                        ->modalDescription(__('portales.withdraw_help', ['portal' => $name]))
                        ->visible(fn (?Property $record): bool => filled($listing($record)?->external_id) && $listing($record)?->status !== PortalListing::WITHDRAWN),
                    self::action($key, 'refresh', __('Check status'), 'heroicon-m-magnifying-glass')
                        ->color('gray')->visible(fn (?Property $record): bool => filled($listing($record)?->external_id)),
                ]),
            ]);
    }

    private static function summary(string $key, ?PortalListing $l): string
    {
        [$label] = self::STATUS[$l->status ?? 'no_enviado'] ?? [$l?->status];

        return trim(__('Status: :s', ['s' => __($label)])
            .($l?->external_id ? ' · '.__('portales.portal_id', ['portal' => Portals::name($key), 'id' => $l->external_id]) : '')
            .($l?->last_synced_at ? ' · '.__('last sync: :d', ['d' => $l->last_synced_at->timezone('Europe/Madrid')->format('d/m/Y H:i')]) : '')
            .($l?->driver === 'simulado' ? ' · '.__('simulation') : ''));
    }

    private static function action(string $key, string $operation, string $label, string $icon): Action
    {
        return Action::make("portal_{$key}_{$operation}")->label($label)->icon($icon)
            ->action(function (Property $record, $livewire) use ($key, $operation): void {
                $livewire->save(false, false);
                $result = Portals::run($key, $operation, $record->refresh());
                $state = $result['listing'] ? __(self::STATUS[$result['listing']->status][0] ?? $result['listing']->status) : __('Not sent');
                $n = Notification::make()->title(Portals::name($key).': '.$state);
                $result['ok'] ? $n->success() : $n->body($result['message'])->warning()->persistent();
                $n->send();
                $livewire->redirect($livewire->getResource()::getUrl('edit', ['record' => $record]));
            });
    }

    /** Property list: «Portales» badges («pisos.com: Publicado»). */
    public static function column(): TextColumn
    {
        return TextColumn::make('clarto_portales')->label(__('portales.column'))->badge()->placeholder('—')->toggleable()
            ->state(fn (Property $record): array => collect(self::listMap()[$record->getKey()] ?? [])
                ->map(fn (string $status, string $key): string => Portals::name($key).': '.__(self::STATUS[$status][0] ?? $status))->values()->all())
            ->color(fn (string $state): string => collect(self::STATUS)->first(fn (array $s): bool => str_ends_with($state, ': '.__($s[0])))[1] ?? 'gray');
    }

    /** @return list<SelectFilter> one per portal: published / not published / with error */
    public static function filters(): array
    {
        return array_map(fn (string $key, string $name): SelectFilter => SelectFilter::make("portal_{$key}")->label($name)
            ->options([
                'publicado' => __('portales.filters.published', ['portal' => $name]),
                'no_publicado' => __('portales.filters.not_published', ['portal' => $name]),
                'error' => __('portales.filters.error', ['portal' => $name]),
            ])
            ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                'publicado' => $query->whereIn($query->qualifyColumn('id'), PortalListing::query()->select('property_id')->where('portal', $key)->whereIn('status', self::LIVE)),
                'no_publicado' => $query->whereNotIn($query->qualifyColumn('id'), PortalListing::query()->select('property_id')->where('portal', $key)->whereIn('status', self::LIVE)),
                'error' => $query->whereIn($query->qualifyColumn('id'), PortalListing::query()->select('property_id')->where('portal', $key)
                    ->where(fn ($q) => $q->whereIn('status', [PortalListing::ERROR, PortalListing::REJECTED])->orWhereNotNull('last_error'))),
                default => $query,
            }), array_keys(Portals::names()), Portals::names());
    }

    /** @return list<BulkAction> «Enviar a …» / «Retirar de …» for the selected properties, with the count in the confirmation */
    public static function bulkActions(): array
    {
        $actions = [];
        foreach (Portals::names() as $key => $name) {
            $actions[] = BulkAction::make("portal_send_{$key}")->label(__('portales.bulk.send', ['portal' => $name]))->icon('heroicon-o-paper-airplane')
                ->visible(fn (): bool => Portals::enabled($key))
                ->requiresConfirmation()->modalHeading(__('portales.bulk.send', ['portal' => $name]))
                ->modalDescription(fn (Collection $records): string => self::confirmation($key, $records))
                ->action(fn (Collection $records) => self::notify($key, 'send', Portals::runMany($key, 'publish', $records)))
                ->deselectRecordsAfterCompletion();
            $actions[] = BulkAction::make("portal_withdraw_{$key}")->label(__('portales.bulk.withdraw', ['portal' => $name]))->icon('heroicon-o-archive-box-x-mark')->color('danger')
                ->requiresConfirmation()->modalHeading(__('portales.bulk.withdraw', ['portal' => $name]))
                ->modalDescription(fn (Collection $records): string => __('portales.bulk.withdraw_confirm', ['n' => $records->count(), 'portal' => $name]))
                ->action(fn (Collection $records) => self::notify($key, 'withdraw', Portals::runMany($key, 'withdraw', $records)))
                ->deselectRecordsAfterCompletion();
        }

        return $actions;
    }

    /** @param Collection<int, Property> $records */
    public static function confirmation(string $key, Collection $records): string
    {
        $refused = $records->filter(fn (Property $p): bool => Portals::collaboratorRefusal($p) !== null)->count();

        return __('portales.bulk.send_confirm', ['n' => $records->count(), 'portal' => Portals::name($key)])
            .($refused > 0 ? ' '.__('portales.bulk.send_refused', ['n' => $refused]) : '');
    }

    /** @param array{ok: int, failed: array<string, string>} $result */
    private static function notify(string $key, string $kind, array $result): void
    {
        $n = Notification::make()->title(__('portales.bulk.done_'.$kind, ['n' => $result['ok'], 'portal' => Portals::name($key)]));
        if ($result['failed'] === []) {
            $n->success()->send();

            return;
        }
        $lines = collect($result['failed'])->take(8)->map(fn (string $why, string $ref): string => "{$ref}: {$why}")->implode("\n");
        $n->warning()->persistent()->body(__('portales.bulk.not_done', ['n' => count($result['failed'])])."\n".$lines)->send();
    }

    /** @return array<int, array<string, string>> property id => [portal => status], once per request and team */
    private static function listMap(): array
    {
        $teamId = CollaboratorUi::teamId();
        $key = spl_object_id(request()).':'.$teamId;
        if ((self::$listCache['key'] ?? null) !== $key) {
            $map = [];
            foreach (PortalListing::query()->whereIn('property_id', Property::query()->forTeam($teamId)->select('id'))->where('status', '!=', 'no_enviado')->get(['property_id', 'portal', 'status']) as $l) {
                $map[$l->property_id][$l->portal] = $l->status;
            }
            self::$listCache = ['key' => $key, 'map' => $map];
        }

        return self::$listCache['map'];
    }
}
