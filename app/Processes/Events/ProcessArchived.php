<?php

declare(strict_types=1);

namespace App\Processes\Events;

use App\Processes\Models\Process;
use App\Processes\Models\ProcessVersion;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** A process was archived ($version: the version that was current, now «archivada»). Dispatched after commit. */
final class ProcessArchived implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly Process $process, public readonly ?ProcessVersion $version) {}
}
