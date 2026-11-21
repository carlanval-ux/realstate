<?php

declare(strict_types=1);

namespace App\Filament\Owners\Pages;

use App\Acquisition\GhlHandoff;
use App\Filament\Owners\OwnerResource;
use App\Filament\Pages\OwnerLinkingQueue;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;

final class ListOwners extends ListRecords
{
    protected static string $resource = OwnerResource::class;

    protected function getHeaderActions(): array
    {
        $pending = GhlHandoff::pendingForTeam(Filament::getTenant()?->getKey())->count();

        return [
            Action::make('pending_link')->label(__('propietarios.queue.title'))->icon('heroicon-o-link')
                ->color($pending > 0 ? 'warning' : 'gray')->badge($pending > 0 ? (string) $pending : null)
                ->url(OwnerLinkingQueue::getUrl()),
            CreateAction::make(),
        ];
    }
}
