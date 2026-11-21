<?php

declare(strict_types=1);

namespace App\Filament\Colaboradores\Resources\MyPropertyResource\Pages;

use App\Filament\Colaboradores\Resources\MyPropertyResource;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

/** Record page: resolved through MyPropertyResource::getEloquentQuery(), so another collaborator's property is a 404. */
final class ViewMyProperty extends ViewRecord
{
    protected static string $resource = MyPropertyResource::class;

    public function getTitle(): string|Htmlable
    {
        return $this->getRecord()->reference().' · '.($this->getRecord()->title ?: '');
    }
}
