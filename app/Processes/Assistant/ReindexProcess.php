<?php

declare(strict_types=1);

namespace App\Processes\Assistant;

use App\Processes\Events\ProcessArchived;
use App\Processes\Events\ProcessVersionPublished;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Keeps the assistant's index in step with the lifecycle (modelo-datos.md §5): publishing replaces every fragment of
 * the process by those of the new version; archiving removes them all. Retrieval filters by access anyway, so a delayed
 * or failed job never exposes a replaced or archived version (the daily rebuild repairs the index).
 */
final class ReindexProcess implements ShouldQueue
{
    public function handle(ProcessVersionPublished|ProcessArchived $event): void
    {
        if ($event instanceof ProcessArchived) {
            ProcessIndex::forgetProcess((int) $event->process->getKey());

            return;
        }
        ProcessIndex::index($event->version);
    }
}
