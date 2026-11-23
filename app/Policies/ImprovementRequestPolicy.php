<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ImprovementRequest;
use App\Models\User;

/**
 * «Incidencias y mejoras»: every member of the thread's team sees it and replies (process doubts: only the author,
 * the process owner and the administrators); only administrators of the team change the status. Nobody deletes.
 */
final class ImprovementRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ImprovementRequest $request): bool
    {
        return (string) $request->user_id === (string) $user->getKey() || ImprovementRequest::isTeamAdmin($user, $request->team_id) || $this->ownsLinkedProcess($user, $request)
            || ($request->team !== null && $user->belongsToTeam($request->team) && ! \App\Collaborators\CollaboratorAccess::isCollaborator($user) && ! $request->isProcessDoubt());
    }

    public function reply(User $user, ImprovementRequest $request): bool
    {
        return $this->view($user, $request);
    }

    /** ── Procesos (begin) ── the owner of the process a doubt is about («Comunicar una duda o mejora») sees and answers it. */
    private function ownsLinkedProcess(User $user, ImprovementRequest $request): bool
    {
        return \Illuminate\Support\Facades\Schema::hasTable('clarto_process_feedback')
            && \Illuminate\Support\Facades\DB::table('clarto_process_feedback')->join('clarto_processes', 'clarto_processes.id', '=', 'clarto_process_feedback.process_id')
                ->where('clarto_process_feedback.improvement_request_id', $request->getKey())->where('clarto_processes.team_id', $request->team_id)
                ->where('clarto_processes.owner_user_id', $user->getKey())->exists();
    }
    // ── Procesos (end) ──

    public function create(User $user): bool
    {
        return $user->current_team_id !== null;
    }

    public function update(User $user, ImprovementRequest $request): bool
    {
        return ImprovementRequest::isTeamAdmin($user, $request->team_id) || $this->ownsLinkedProcess($user, $request);
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
