<?php

declare(strict_types=1);

namespace App\Filament;

use App\Filament\Pages\UserManual;
use Filament\Facades\Filament;
use Filament\GlobalSearch\GlobalSearchResult;
use Filament\GlobalSearch\GlobalSearchResults;
use Filament\GlobalSearch\Providers\DefaultGlobalSearchProvider;

/**
 * The panel's «Buscar»: the usual records, plus the sections of THIS panel's manual (staff or collaborators) that
 * answer the query (last category).
 */
final class ManualGlobalSearchProvider extends DefaultGlobalSearchProvider
{
    public function getResults(string $query): ?GlobalSearchResults
    {
        $results = parent::getResults($query) ?? GlobalSearchResults::make();
        $manual = self::manualPage();
        if ($manual === null) {
            return $results;
        }

        $entries = array_map(fn (array $entry): GlobalSearchResult => new GlobalSearchResult(
            title: $entry['title'],
            url: $manual::getUrl(['buscar' => $query]).'#'.$entry['anchor'],
            details: array_values(array_filter([$entry['section'] !== $entry['title'] ? $entry['section'] : null, $entry['excerpt']])),
        ), $manual::search($query));

        return $entries === [] ? $results : $results->category(__('User manual'), $entries);
    }

    /** @return class-string<UserManual>|null the manual page of the current panel */
    public static function manualPage(): ?string
    {
        return collect(Filament::getCurrentPanel()?->getPages() ?? [])->first(fn (string $page): bool => is_a($page, UserManual::class, true));
    }
}
