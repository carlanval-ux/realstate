<?php

declare(strict_types=1);

namespace App\Collaborators\Import;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/** One photo through IOPaint (queue «marcas-agua», after the default queue: one at a time, low priority). */
final class CleanWatermarkJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    /** Below the queue's retry_after (90 s), so a slow image is never picked up twice. */
    public int $timeout = 85;

    public int $tries = 1;   // staff retry from the photo review

    public function __construct(public readonly int $cleanupId) {}

    public function handle(): void
    {
        if (($cleanup = PhotoCleanup::query()->find($this->cleanupId)) !== null) {
            WatermarkRemoval::process($cleanup);
        }
    }
}
