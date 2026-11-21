<?php

declare(strict_types=1);

namespace App\Filament\Resources\SignatureRequestResource\Pages;

use App\Filament\Pages\SendForSignature;
use App\Filament\Resources\SignatureRequestResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

final class ListSignatureRequests extends ListRecords
{
    protected static string $resource = SignatureRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [Action::make('new')->label(__('Prepare document for signature'))->icon('heroicon-m-plus')->url(SendForSignature::getUrl())];
    }
}
