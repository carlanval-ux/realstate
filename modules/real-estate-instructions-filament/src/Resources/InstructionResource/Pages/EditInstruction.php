<?php

declare(strict_types=1);

namespace Liberu\RealEstate\InstructionsFilament\Resources\InstructionResource\Pages;

use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Liberu\RealEstate\Instructions\Application\UpdateInstruction as UpdateInstructionAction;
use Liberu\RealEstate\InstructionsFilament\Resources\InstructionResource;

final class EditInstruction extends EditRecord
{
    protected static string $resource = InstructionResource::class;

    /** CLARTO: WhatsApp with the file's owner, and preparing the mandate document for signature. */
    protected function getHeaderActions(): array
    {
        $actions = [...parent::getHeaderActions(), ...\App\Filament\Communications\CommunicationsUi::actions()];
        if (class_exists(\App\Filament\Pages\SendForSignature::class) && \App\Filament\Pages\SendForSignature::canAccess()) {
            $actions[] = \Filament\Actions\Action::make('prepareSignature')->label(__('Prepare document for signature'))->icon('heroicon-m-paper-airplane')
                ->url(fn (): string => \App\Filament\Pages\SendForSignature::getUrl(['encargo' => $this->getRecord()->getKey()]));
        }

        return $actions;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $user = auth()->user();
        abort_unless($user?->current_team_id !== null && (string) $user->current_team_id === (string) $record->team_id, 403);

        return app(UpdateInstructionAction::class)->handle($record, $user->current_team_id, $data);
    }
}
