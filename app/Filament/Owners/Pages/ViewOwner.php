<?php

declare(strict_types=1);

namespace App\Filament\Owners\Pages;

use App\Filament\Owners\OwnerResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/** The owner 360 page: tabs built by App\Filament\Owners\OwnerTabs, plus those registered in OwnerProfileSections. */
final class ViewOwner extends ViewRecord
{
    protected static string $resource = OwnerResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()->label(__('propietarios.edit')), ...\App\Filament\Communications\CommunicationsUi::actions()];
    }
}
