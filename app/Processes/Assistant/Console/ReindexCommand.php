<?php

declare(strict_types=1);

namespace App\Processes\Assistant\Console;

use App\Processes\Assistant\ProcessIndex;
use Illuminate\Console\Command;

/** Rebuilds the assistant's index from the published processes (all companies, or one). Idempotent. */
final class ReindexCommand extends Command
{
    protected $signature = 'clarto:asistente-reindexar {--team= : id de la empresa}';

    protected $description = 'Rebuild the «Preguntar a CLARTO» index from the published procedures';

    public function handle(): int
    {
        $team = $this->option('team');
        $count = ProcessIndex::rebuild(is_numeric($team) ? (int) $team : null);
        $this->info("Fragments indexed: {$count}".(ProcessIndex::hasFts() ? ' (FTS5)' : ' (LIKE)'));

        return self::SUCCESS;
    }
}
