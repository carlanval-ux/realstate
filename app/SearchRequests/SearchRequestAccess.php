<?php

declare(strict_types=1);

namespace App\SearchRequests;

use App\Collaborators\Collaborator;
use App\Collaborators\CollaboratorAccess;
use App\Collaborators\CollaboratorScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Liberu\Foundation\Organizations\Models\Team;

/**
 * What collaborators see of «Solicitudes» (registered in CollaboratorAccess for SearchRequest and
 * SearchRequestResponse; the contract adds the team filter and denies staff and deactivated collaborators):
 * - requests SENT to them (recipient «enviada» or «respondida»: not drafts, not pending, not withdrawn);
 * - their own answers.
 * The same rule decides whether a pending notice may still go out (CollaboratorNotices re-checks canSee()).
 * Staff: members of the request's team with staff access (isStaff).
 */
final class SearchRequestAccess implements CollaboratorScope
{
    public function scope(Builder $query, Collaborator $collaborator): Builder
    {
        $model = $query->getModel();

        return $model instanceof SearchRequestResponse
            ? $query->where($model->qualifyColumn('collaborator_id'), $collaborator->getKey())
                ->whereHas('recipient', fn (Builder $q) => $q->whereIn('status', ['enviada', 'respondida']))
            : $query->where($model->qualifyColumn('status'), '!=', 'borrador')
                ->whereHas('recipients', fn (Builder $q) => $q->where('collaborator_id', $collaborator->getKey())->whereIn('status', ['enviada', 'respondida']));
    }

    public function canSee(Collaborator $collaborator, Model $record): bool
    {
        return $this->scope($record->newQuery()->whereKey($record->getKey()), $collaborator)->exists();
    }

    /** The recipient row that lets this user (a collaborator) see and answer the request; null = blocked. */
    public static function recipientFor(?object $user, SearchRequest $request): ?SearchRequestRecipient
    {
        $collaborator = CollaboratorAccess::collaboratorFor($user);
        if ($collaborator === null || ! CollaboratorAccess::canSee($collaborator, $request)) {
            return null;
        }

        return $request->recipients()->where('collaborator_id', $collaborator->getKey())->first();
    }

    /** Team staff of the request's company (super administrators: any). */
    public static function isStaff(?object $user, int|string|null $teamId): bool
    {
        if (! CollaboratorAccess::isStaff($user) || $teamId === null) {
            return false;
        }
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }
        $team = Team::query()->find($teamId);

        return $team !== null && method_exists($user, 'belongsToTeam') && (bool) $user->belongsToTeam($team);
    }

    public static function canView(?object $user, SearchRequest $request): bool
    {
        return self::isStaff($user, $request->team_id) || CollaboratorAccess::canSee($user, $request);
    }
}
