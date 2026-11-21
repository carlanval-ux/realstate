<?php

declare(strict_types=1);

namespace App\Processes\Assistant;

use App\Processes\Models\Process;
use App\Processes\Models\ProcessVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The searchable fragments of the procedures (clarto_process_chunks + FTS5). Only the CURRENT PUBLISHED version of a
 * published process is ever indexed, already masked; anything else of that process is removed. Access is NOT stored
 * here: retrieval always filters by ProcessAccess::visibleQuery at query time, so a missed reindex can never expose a
 * replaced, archived or restricted version.
 */
final class ProcessIndex
{
    public const TABLE = 'clarto_process_chunks';

    public const FTS = 'clarto_process_chunks_fts';

    /** Language of the procedures' content (and of the section labels indexed with it). */
    public const LOCALE = 'es';

    /** A fragment longer than this is split at sentence boundaries. */
    private const MAX_CHARS = 1200;

    /** Indexes $version if it is the current published version of its process; otherwise only forgets the process. */
    public static function index(ProcessVersion $version): int
    {
        $version = ProcessVersion::query()->find($version->getKey());
        $process = $version === null ? null : Process::query()->find($version->process_id);
        if ($process === null) {
            return 0;
        }

        return DB::transaction(function () use ($version, $process): int {
            self::forgetProcess((int) $process->getKey());
            if ($process->status !== 'publicado' || $version->status !== 'publicada' || (int) $process->current_version_id !== (int) $version->getKey()) {
                return 0;
            }

            $title = (string) $version->title;
            $rows = [];
            // Section labels («Paso 2», «Plazo») in the language of the content, whatever the request's language.
            $locale = app()->getLocale();
            app()->setLocale(self::LOCALE);
            try {
                $sections = $version->sections();
            } finally {
                app()->setLocale($locale);
            }
            foreach ($sections as $section) {
                foreach (self::split(Text::mask((string) $section['text'])) as $body) {
                    $rows[] = [
                        'team_id' => $version->team_id, 'process_id' => $process->getKey(), 'process_version_id' => $version->getKey(),
                        'anchor' => mb_substr((string) $section['anchor'], 0, 40),
                        'heading' => mb_substr(Text::mask($title.' · '.$section['heading']), 0, 200),
                        'body' => $body, 'position' => count($rows), 'chars' => mb_strlen($body), 'created_at' => now(),
                    ];
                }
            }
            foreach (array_chunk($rows, 100) as $chunk) {
                DB::table(self::TABLE)->insert($chunk);   // the FTS triggers fire for each row
            }

            return count($rows);
        });
    }

    public static function forgetProcess(int $processId): void
    {
        DB::table(self::TABLE)->where('process_id', $processId)->delete();
    }

    /** Rebuilds the index of one company (or all) from the published processes. Idempotent. @return int fragments */
    public static function rebuild(int|string|null $teamId = null): int
    {
        $processes = Process::query()->when($teamId !== null, fn ($q) => $q->where('team_id', $teamId));
        DB::table(self::TABLE)->when($teamId !== null, fn ($q) => $q->where('team_id', $teamId))
            ->whereNotIn('process_id', (clone $processes)->where('status', 'publicado')->select('id'))->delete();

        $count = 0;
        foreach ((clone $processes)->where('status', 'publicado')->whereNotNull('current_version_id')->get() as $process) {
            $version = ProcessVersion::query()->find($process->current_version_id);
            $count += $version === null ? 0 : self::index($version);
        }

        return $count;
    }

    public static function hasFts(): bool
    {
        return DB::connection()->getDriverName() === 'sqlite' && Schema::hasTable(self::FTS);
    }

    /** @return list<string> */
    private static function split(string $text): array
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($text === '') {
            return [];
        }
        $parts = [];
        $current = '';
        foreach (preg_split('/(?<=[.!?;])\s+/u', $text) ?: [$text] as $sentence) {
            if ($current !== '' && mb_strlen($current) + mb_strlen($sentence) + 1 > self::MAX_CHARS) {
                $parts[] = $current;
                $current = '';
            }
            $current = trim($current.' '.$sentence);
            while (mb_strlen($current) > self::MAX_CHARS) {
                $parts[] = mb_substr($current, 0, self::MAX_CHARS);
                $current = mb_substr($current, self::MAX_CHARS);
            }
        }

        return $current === '' ? $parts : [...$parts, $current];
    }
}
