<?php

declare(strict_types=1);

namespace App\Processes;

use App\Processes\Models\Process;
use App\Processes\Models\ProcessAttachment;
use App\Processes\Models\ProcessVersion;

/** Panel links, always with the process's own tenant (route of App\Filament\Processes\Pages\ProcessView). */
final class ProcessLinks
{
    public const ROUTE = 'filament.admin.pages.procesos.ver';

    /** Current version. */
    public static function process(Process $process, ?string $anchor = null): string
    {
        return route(self::ROUTE, ['tenant' => $process->team_id, 'process' => $process->getKey()]).self::anchor($anchor);
    }

    /** A specific version (#paso-{key}…). */
    public static function version(ProcessVersion $version, ?string $anchor = null): string
    {
        return route(self::ROUTE, ['tenant' => $version->team_id, 'process' => $version->process_id, 'v' => $version->getKey()]).self::anchor($anchor);
    }

    public static function attachment(ProcessAttachment $attachment): string
    {
        return route('clarto.procesos.adjunto', ['attachment' => $attachment->getKey()]);
    }

    /** A Liberu path of the content («/admin/{tenant}/occupancies») with the tenant filled in; https URLs as they are. */
    public static function resolve(?string $url, int|string $teamId): ?string
    {
        $url = trim((string) $url);
        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return url(str_replace('{tenant}', (string) $teamId, $url));
        }

        return str_starts_with($url, 'https://') ? $url : null;
    }

    private static function anchor(?string $anchor): string
    {
        return blank($anchor) ? '' : '#'.ltrim((string) $anchor, '#');
    }
}
