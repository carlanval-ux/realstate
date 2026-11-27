<?php

declare(strict_types=1);

namespace App\Filament\Resources\BillingArticleResource\Pages;

use App\Filament\Resources\BillingArticleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

final class ManageBillingArticles extends ManageRecords
{
    protected static string $resource = BillingArticleResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
