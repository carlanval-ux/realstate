<?php

declare(strict_types=1);

namespace App\Filament\Resources\SearchRequestResource\Pages;

use App\Filament\Pages\SearchRequestInbox;
use App\Filament\Resources\SearchRequestResource;
use App\SearchRequests\SearchRequest;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

/** The team's inbox of requests: active first (Open, Under evaluation), then the rest by status. */
final class ListSearchRequests extends ListRecords
{
    protected static string $resource = SearchRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('inbox')->label(fn () => __('solicitudes.menu_pendientes').(($n = SearchRequestInbox::getNavigationBadge()) ? " ({$n})" : ''))
                ->icon('heroicon-o-inbox-arrow-down')->color('gray')->url(fn () => SearchRequestInbox::getUrl()),
            CreateAction::make(),
        ];
    }

    public function getTabs(): array
    {
        return [
            'activas' => Tab::make(__('solicitudes.estado.abierta').' / '.__('solicitudes.estado.en_evaluacion'))
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', SearchRequest::ACTIVE)),
            'borrador' => Tab::make(__('solicitudes.estado.borrador'))->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'borrador')),
            'cerradas' => Tab::make(__('solicitudes.estado.cubierta').' / '.__('solicitudes.estado.cerrada'))
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', ['cubierta', 'cerrada'])),
            'todas' => Tab::make(__('solicitudes.todas')),
        ];
    }
}
