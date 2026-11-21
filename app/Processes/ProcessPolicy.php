<?php

declare(strict_types=1);

namespace App\Processes;

use App\Models\User;
use App\Processes\Models\Process;
use App\Processes\Models\ProcessVersion;

/** Gate policies for Process and ProcessVersion: they only delegate to ProcessAccess. */
final class ProcessPolicy
{
    public function view(User $user, Process|ProcessVersion $subject): bool
    {
        return $subject instanceof Process
            ? ProcessAccess::canViewProcess($user, $subject) || ProcessAccess::manages($user, $subject)
            : ProcessAccess::canView($user, $subject);
    }

    public function update(User $user, Process|ProcessVersion $subject): bool
    {
        return ProcessAccess::canEdit($user, $subject instanceof Process ? $subject : $subject->process);
    }

    public function delete(User $user, Process|ProcessVersion $subject): bool
    {
        return false;
    }
}
