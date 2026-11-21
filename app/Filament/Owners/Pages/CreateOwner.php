<?php

declare(strict_types=1);

namespace App\Filament\Owners\Pages;

use App\Filament\Owners\OwnerResource;
use App\Owners\OwnerProfile;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Liberu\RealEstate\Parties\Application\CreateParty;
use Liberu\RealEstate\Parties\Models\Party;

final class CreateOwner extends CreateRecord
{
    protected static string $resource = OwnerResource::class;

    /** Like the module's own Party screen: created through CreateParty in the current team (type vendor). */
    protected function handleRecordCreation(array $data): Model
    {
        $user = auth()->user();
        $teamId = Filament::getTenant()?->getKey() ?? $user?->current_team_id;
        abort_if($teamId === null, 403);

        return app(CreateParty::class)->handle($teamId, $user->getAuthIdentifier(), [...$data, 'type' => 'vendor', 'metadata' => ['origen' => 'Panel']]);
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
