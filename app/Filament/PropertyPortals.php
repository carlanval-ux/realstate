<?php

declare(strict_types=1);

namespace App\Filament;

use App\Models\PortalListing;
use App\Modules\ClartoModules;
use App\Portals\Pisos\PisosConnector;
use App\Portals\Pisos\PisosPayload;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Liberu\RealEstate\Properties\Models\Property;

/** "Portals" tab of the property form: choose whether to send the property to pisos.com and see its state. */
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

    public static function tab(): Tab
    {
        return Tab::make(__('Portals'))
            ->icon('heroicon-o-globe-alt')
            ->visible(fn (?Property $record): bool => $record !== null)
            ->schema([
                Section::make('pisos.com')
                    ->description(fn (): string => ClartoModules::refusal(ClartoModules::PISOS) ?? (config('pisos.driver') === 'api' && config('pisos.enabled')
                        ? __('Real sends are ON.')
                        : __('Real sends are OFF: actions run against a simulation until pisos.com credentials are configured on the server.')))
                    ->schema([
                        Text::make(fn (?Property $record): string => self::summary($record))->weight('medium'),
                        Text::make(fn (?Property $record): ?string => ($l = self::listing($record)) && filled($l->last_error) ? __('Reason / last message: :m', ['m' => $l->last_error]) : null)
                            ->color('danger')->visible(fn (?Property $record): bool => filled(self::listing($record)?->last_error)),
                        Text::make(fn (?Property $record): string => __('Missing before sending: :m', ['m' => implode(' · ', $record ? PisosPayload::missing($record) : [])]))
                            ->color('warning')->visible(fn (?Property $record): bool => $record !== null && PisosPayload::missing($record) !== []),
                        Actions::make([
                            self::action('pisos_publish', __('Publish on pisos.com'), 'heroicon-m-paper-airplane', fn (PisosConnector $c, Property $p) => $c->publish($p))
                                ->visible(fn (?Property $record): bool => ClartoModules::enabled(ClartoModules::PISOS) && blank(self::listing($record)?->external_id)),
                            self::action('pisos_update', __('Update'), 'heroicon-m-arrow-path', fn (PisosConnector $c, Property $p) => $c->update($p))
                                ->visible(fn (?Property $record): bool => ClartoModules::enabled(ClartoModules::PISOS) && filled(self::listing($record)?->external_id) && self::listing($record)?->status !== PortalListing::WITHDRAWN),
                            self::action('pisos_withdraw', __('Withdraw'), 'heroicon-m-archive-box-x-mark', fn (PisosConnector $c, Property $p) => $c->withdraw($p))
                                ->color('danger')->requiresConfirmation()
                                ->modalDescription(__('The listing is deactivated on pisos.com. The property is kept in the panel unchanged.'))
                                ->visible(fn (?Property $record): bool => filled(self::listing($record)?->external_id) && self::listing($record)?->status !== PortalListing::WITHDRAWN),
                            self::action('pisos_status', __('Check status'), 'heroicon-m-magnifying-glass', fn (PisosConnector $c, Property $p) => $c->refresh($p))
                                ->color('gray')->visible(fn (?Property $record): bool => filled(self::listing($record)?->external_id)),
                        ]),
                    ]),
            ]);
    }

    private static function listing(?Property $record): ?PortalListing
    {
        return $record ? PortalListing::query()->where('property_id', $record->getKey())->where('portal', PisosConnector::PORTAL)->first() : null;
    }

    private static function summary(?Property $record): string
    {
        $l = self::listing($record);
        [$label] = self::STATUS[$l->status ?? 'no_enviado'] ?? [$l?->status];

        return trim(__('Status: :s', ['s' => __($label)])
            .($l?->external_id ? ' · '.__('pisos.com id: :id', ['id' => $l->external_id]) : '')
            .($l?->last_synced_at ? ' · '.__('last sync: :d', ['d' => $l->last_synced_at->timezone('Europe/Madrid')->format('d/m/Y H:i')]) : '')
            .($l?->driver === 'simulado' ? ' · '.__('simulation') : ''));
    }

    /** @param callable(PisosConnector, Property): PortalListing $work */
    private static function action(string $name, string $label, string $icon, callable $work): Action
    {
        return Action::make($name)->label($label)->icon($icon)
            ->action(function (Property $record, $livewire) use ($work): void {
                $livewire->save(false, false);
                $listing = $work(PisosConnector::make(), $record->refresh());
                [$state, $color] = self::STATUS[$listing->status] ?? [$listing->status, 'gray'];
                $n = Notification::make()->title('pisos.com: '.__($state));
                $listing->last_error ? $n->body($listing->last_error)->color($color)->warning()->persistent() : $n->success();
                $n->send();
                $livewire->redirect($livewire->getResource()::getUrl('edit', ['record' => $record]));
            });
    }
}
