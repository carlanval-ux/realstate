<?php

declare(strict_types=1);

namespace App\Filament\Owners\Pages;

use App\Filament\Owners\OwnerResource;
use App\Owners\OwnerProfile;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Liberu\RealEstate\Parties\Models\Party;

final class EditOwner extends EditRecord
{
    protected static string $resource = OwnerResource::class;

    protected function getHeaderActions(): array
    {
        return [ViewAction::make()];
    }

    protected function afterSave(): void
    {
        /** @var Party $owner */
        $owner = $this->record;
        $fields = array_values(array_intersect(array_keys($owner->getChanges()), ['name', 'email', 'phone']));
        if ($fields !== []) {
            OwnerProfile::log($owner, 'profile_updated', ['fields' => $fields]);
        }
    }

    protected function getRedirectUrl(): ?string
    {
        return OwnerResource::getUrl('view', ['record' => $this->record]);
    }
}
