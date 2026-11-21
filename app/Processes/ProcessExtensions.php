<?php

declare(strict_types=1);

namespace App\Processes;

use App\Processes\Models\ProcessVersion;
use Closure;
use Filament\Schemas\Components\Tabs\Tab;
use Throwable;

/** Extension points used by the assistant («Preguntar a CLARTO», recommendations). Registered once at boot. */
final class ProcessExtensions
{
    /** @var array<string, array{tab: Closure, sort: int}> */
    private static array $tabs = [];

    private static ?Closure $assistant = null;

    /** Tab of «Gestión de procesos». $tab: fn (): Tab. The same key replaces. */
    public static function adminTab(string $key, Closure $tab, int $sort = 100): void
    {
        self::$tabs[$key] = ['tab' => $tab, 'sort' => $sort];
    }

    /** «Preguntar a CLARTO» button. $url: fn (?string $question, ?ProcessVersion $context): ?string; null hides it. */
    public static function assistant(Closure $url): void
    {
        self::$assistant = $url;
    }

    /** @return array<string, Tab> by key, sorted */
    public static function adminTabs(): array
    {
        $tabs = self::$tabs;
        uasort($tabs, fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);
        $built = [];
        foreach ($tabs as $key => $entry) {
            try {
                if (($tab = ($entry['tab'])()) instanceof Tab) {
                    $built[$key] = $tab;
                }
            } catch (Throwable $e) {
                report($e);   // an extension must never break the management page
            }
        }

        return $built;
    }

    public static function assistantUrl(?string $question = null, ?ProcessVersion $context = null): ?string
    {
        try {
            return self::$assistant !== null ? (self::$assistant)($question, $context) : null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
