<?php

declare(strict_types=1);

namespace App\Filament\Resources\ValuationRequestResource\Pages;

use App\Filament\Pages\ValuationSettings;
use App\Filament\Resources\ValuationRequestResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ManageRecords;

final class ManageValuationRequests extends ManageRecords
{
    protected static string $resource = ValuationRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('ajustes')->label(__('clarto.valoraciones.ajustes.titulo'))->icon('heroicon-o-cog-6-tooth')->color('gray')
                ->visible(fn (): bool => ValuationSettings::canAccess())
                ->url(fn (): string => ValuationSettings::getUrl()),
        ];
    }
}
