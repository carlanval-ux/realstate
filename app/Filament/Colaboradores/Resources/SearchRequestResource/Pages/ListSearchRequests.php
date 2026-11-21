<?php

declare(strict_types=1);

namespace App\Filament\Colaboradores\Resources\SearchRequestResource\Pages;

use App\Filament\Colaboradores\Resources\SearchRequestResource;
use Filament\Resources\Pages\ListRecords;

final class ListSearchRequests extends ListRecords
{
    protected static string $resource = SearchRequestResource::class;
}
