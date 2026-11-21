<?php

declare(strict_types=1);

namespace Liberu\RealEstate\PartiesFilament\Resources\PartyResource\Pages;

use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Liberu\RealEstate\Parties\Application\UpdateParty as UpdatePartyAction;
use Liberu\RealEstate\PartiesFilament\Resources\PartyResource;

final class EditParty extends EditRecord
{
    protected static string $resource = PartyResource::class;

    /** CLARTO: WhatsApp with the contact (App\Filament\WhatsAppConversation). */
    protected function getHeaderActions(): array
    {
        return [...parent::getHeaderActions(), \App\Filament\WhatsAppConversation::action()];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $user = auth()->user();
        abort_unless($user?->current_team_id === $record->team_id, 403);

        return app(UpdatePartyAction::class)->handle($record->team_id, $record->getKey(), $data);
    }
}
