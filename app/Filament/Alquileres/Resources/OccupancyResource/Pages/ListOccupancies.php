<?php

declare(strict_types=1);

namespace App\Filament\Alquileres\Resources\OccupancyResource\Pages;

use App\Filament\Alquileres\Resources\OccupancyResource;
use App\Rentals\Models\Occupancy;
use App\Rentals\RentalModules;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

/** One tab per module; a switched-off module keeps its tab (history) without a «new» button for it. */
final class ListOccupancies extends ListRecords
{
    protected static string $resource = OccupancyResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->visible(fn (): bool => collect(array_keys(RentalModules::KEYS))->contains(fn (string $m): bool => RentalModules::enabled($m)))];
    }

    public function getTabs(): array
    {
        $tabs = [];
        foreach (Occupancy::MODULES as $module) {
            $tabs[$module] = Tab::make(__('clarto.alquileres.modules.'.$module).(RentalModules::enabled($module) ? '' : ' ⏸'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('module', $module));
        }

        return $tabs;
    }
}
