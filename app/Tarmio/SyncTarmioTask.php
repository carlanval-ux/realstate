<?php

declare(strict_types=1);

namespace App\Tarmio;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Pushes one clarto_tarmio_tasks row to TARMIO; retried with backoff if TARMIO is down. */
final class SyncTarmioTask implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 300, 900, 3600];

    public function __construct(public int $taskId) {}

    public function handle(): void
    {
        TarmioTask::query()->find($this->taskId)?->push();
    }
}
