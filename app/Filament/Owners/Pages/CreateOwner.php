<?php

declare(strict_types=1);

namespace App\Filament\Owners\Pages;

use App\Filament\Owners\OwnerResource;
use App\Owners\OwnerProfile;
use Filament\Resources\Pages\CreateRecord;
use Liberu\RealEstate\Parties\Models\Party;

final class CreateOwner extends CreateRecord
{
    protected static string $resource = OwnerResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return [...$data, 'type' => 'vendor', 'created_by' => auth()->id(), 'metadata' => ['origen' => 'Panel']];
    }

    protected function afterCreate(): void
    {
        /** @var Party $owner */
        $owner = $this->record;
        OwnerProfile::for($owner);   // also when the profile section was left empty
        OwnerProfile::log($owner, 'owner_created');
    }

    protected function getRedirectUrl(): string
    {
        return OwnerResource::getUrl('view', ['record' => $this->record]);
    }
}
