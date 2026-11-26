<?php

declare(strict_types=1);

namespace App\Filament;

use App\Filament\Pages\UserManual;
use Filament\Facades\Filament;
use Filament\GlobalSearch\GlobalSearchResult;
use Filament\GlobalSearch\GlobalSearchResults;
use Filament\GlobalSearch\Providers\DefaultGlobalSearchProvider;

/** The panel's «Buscar»: the usual records, plus the user-manual sections that answer the query (last category). */
final class ManualGlobalSearchProvider extends DefaultGlobalSearchProvider
{
    public function getResults(string $query): ?GlobalSearchResults
    {
        $results = parent::getResults($query) ?? GlobalSearchResults::make();
        if (! in_array(UserManual::class, Filament::getCurrentPanel()?->getPages() ?? [], true)) {
            return $results;
        }

        $entries = array_map(fn (array $entry): GlobalSearchResult => new GlobalSearchResult(
            title: $entry['title'],
            url: UserManual::getUrl(['buscar' => $query]).'#'.$entry['anchor'],
            details: array_values(array_filter([$entry['section'] !== $entry['title'] ? $entry['section'] : null, $entry['excerpt']])),
        ), UserManual::search($query));

        return $entries === [] ? $results : $results->category(__('User manual'), $entries);
    }
}
