<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ImprovementRequest;
use App\Models\User;

/** The author sees their request; administrators of its team see and answer it. Nobody deletes (history). */
final class ImprovementRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ImprovementRequest $request): bool
    {
        return (string) $request->user_id === (string) $user->getKey() || ImprovementRequest::isTeamAdmin($user, $request->team_id);
    }

    public function create(User $user): bool
    {
        return $user->current_team_id !== null;
    }

    public function update(User $user, ImprovementRequest $request): bool
    {
        return ImprovementRequest::isTeamAdmin($user, $request->team_id);
    }

    public function delete(User $user, ImprovementRequest $request): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
