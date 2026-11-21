<?php

declare(strict_types=1);

namespace App\Filament\Resources\AlbaranResource\Pages;

use App\Albaranes\Albaran;
use App\Filament\AlbaranActions;
use App\Filament\Resources\AlbaranResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

final class EditAlbaran extends EditRecord
{
    protected static string $resource = AlbaranResource::class;

    protected function getHeaderActions(): array
    {
        return [
            AlbaranActions::send(),
            AlbaranActions::refresh(),
            AlbaranActions::pdf(),
            AlbaranActions::requestConformity(),
            AlbaranActions::markConformity(),
            DeleteAction::make()->visible(fn (Albaran $record): bool => $record->status === Albaran::DRAFT && blank($record->external_id)),
        ];
    }

    protected function beforeSave(): void
    {
        abort_unless($this->getRecord()->isEditable(), 403);
    }

    /** Nothing changes once the billing program has the albarán. */
    protected function getFormActions(): array
    {
        return $this->getRecord()->isEditable() ? parent::getFormActions() : [];
    }
}
