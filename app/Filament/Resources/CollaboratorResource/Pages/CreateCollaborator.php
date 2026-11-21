<?php

declare(strict_types=1);

namespace App\Filament\Resources\CollaboratorResource\Pages;

use App\Collaborators\Collaborations;
use App\Collaborators\Collaborator;
use App\Filament\Collaborators\CollaboratorUi;
use App\Filament\Resources\CollaboratorResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateCollaborator extends CreateRecord
{
    protected static string $resource = CollaboratorResource::class;

    protected static bool $canCreateAnother = false;

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): Model
    {
        Collaborations::ensureEnabled();
        $collaborator = Collaborator::query()->create($data + [
            'team_id' => CollaboratorUi::teamId(), 'status' => Collaborator::PENDING, 'created_by' => auth()->id(), 'updated_by' => auth()->id(),
        ]);
        Collaborator::log($collaborator, 'creado');

        return $collaborator;
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return __('colaboradores.messages.created');
    }
}
