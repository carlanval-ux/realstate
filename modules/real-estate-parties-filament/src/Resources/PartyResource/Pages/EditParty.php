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

    /** CLARTO: contact the client (WhatsApp, SMS, e-mail) and its communications (App\Filament\Communications\CommunicationsUi). */
    protected function getHeaderActions(): array
    {
        return [...parent::getHeaderActions(), ...\App\Filament\Communications\CommunicationsUi::actions()];
    }

    /** CLARTO: the client's «Communications» timeline below the form. */
    public function content(\Filament\Schemas\Schema $schema): \Filament\Schemas\Schema
    {
        return $schema->components([$this->getFormContentComponent(), $this->getRelationManagersContentComponent(), \App\Filament\Communications\CommunicationsUi::section($this->getRecord())]);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $user = auth()->user();
        abort_unless($user?->current_team_id === $record->team_id, 403);

        return app(UpdatePartyAction::class)->handle($record->team_id, $record->getKey(), $data);
    }
}
