<?php

declare(strict_types=1);

namespace App\Processes\Events;

use App\Processes\Models\ProcessVersion;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** A version was published (v1, a new version, or the restoration of an archived process). Dispatched after commit. */
final class ProcessVersionPublished implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly ProcessVersion $version, public readonly ?ProcessVersion $replaced) {}
}
