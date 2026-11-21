<?php

declare(strict_types=1);

namespace App\Filament\Resources\ImprovementRequestResource\Pages;

use App\Filament\Resources\ImprovementRequestResource;
use App\Models\ImprovementRequest;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ManageRecords;

final class ManageImprovementRequests extends ManageRecords
{
    protected static string $resource = ImprovementRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('clarto.mejoras.new'))->icon('heroicon-o-plus')
                ->createAnother(false)
                ->using(function (array $data): ImprovementRequest {
                    $user = auth()->user();
                    abort_if($user === null, 403);

                    return ImprovementRequest::submit(
                        $user,
                        Filament::getTenant()?->getKey() ?? $user->current_team_id,
                        (string) $data['title'],
                        (string) $data['description'],
                        array_values((array) ($data['attachments'] ?? [])),
                        (array) ($data['attachment_names'] ?? []),
                    );
                }),
        ];
    }
}
