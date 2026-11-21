<?php

declare(strict_types=1);

namespace App\Collaborators;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Liberu\RealEstate\Properties\Models\Property;

/**
 * THE server-side access rule for external collaborators (contract: orquestacion/colaboradores/contrato.md).
 *
 * - A collaborator is a user linked to a Collaborator record, or holding the «colaborador» role, without a staff
 *   role (super_admin/admin/staff). The role grants nothing by itself: a collaborator sees only properties assigned
 *   to their Collaborator record (active CollaboratorProperty) and what other modules grant through a registered
 *   CollaboratorScope. Everything else is denied: the collaborator user belongs to no team, cannot open /admin or
 *   /app, and the CollaboratorFence middleware closes every web/API route outside the collaborator area.
 * - A deactivated collaborator («baja») sees nothing.
 * - Staff are not answered here (canSee and scope answer false or empty for them): staff keep their team checks.
 */
final class CollaboratorAccess
{
    /** Spatie role (one per team, no permissions). */
    public const ROLE = 'colaborador';

    /** Filament panel id and path of the collaborator area. */
    public const PANEL = 'colaboradores';

    /** @var array<class-string<Model>, CollaboratorScope> set once at boot */
    private static array $scopes = [];

    /** Registers what collaborators may see of $model (requests, responses…). Same class again replaces. */
    public static function register(string $model, CollaboratorScope $scope): void
    {
        self::$scopes[$model] = $scope;
    }

    public static function isStaff(?object $user): bool
    {
        return $user !== null && method_exists($user, 'hasAdminAccess') && $user->hasAdminAccess();
    }

    /** A signed-in external collaborator (fenced into the collaborator area), whether or not still enabled. */
    public static function isCollaborator(?object $user): bool
    {
        if ($user === null || ! isset($user->id) || self::isStaff($user)) {
            return false;
        }

        return Collaborator::query()->where('user_id', $user->id)->exists()
            || (method_exists($user, 'hasRoleInAnyTeam') && $user->hasRoleInAnyTeam([self::ROLE]));
    }

    /** The enabled Collaborator record of this user (null: staff, unlinked, or deactivated). */
    public static function collaboratorFor(?object $user): ?Collaborator
    {
        if ($user instanceof Collaborator) {
            return $user->isEnabled() ? $user : null;
        }
        if ($user === null || ! isset($user->id) || self::isStaff($user)) {
            return null;
        }

        return Collaborator::query()->where('user_id', $user->id)->where('status', '!=', Collaborator::INACTIVE)->first();
    }

    /** The active assignment of this property to this collaborator (null = no access). */
    public static function assignment(object|null $user, Property|int $property): ?CollaboratorProperty
    {
        $collaborator = self::collaboratorFor($user);
        if ($collaborator === null) {
            return null;
        }

        return CollaboratorProperty::query()->active()
            ->where('collaborator_id', $collaborator->id)
            ->where('team_id', $collaborator->team_id)
            ->where('property_id', $property instanceof Property ? $property->getKey() : $property)
            ->first();
    }

    public static function canSeeProperty(?object $user, Property|int $property): bool
    {
        return self::assignment($user, $property) !== null;
    }

    /** Restricts a Property query to the properties assigned to this collaborator (empty for anyone else). */
    public static function scopeProperties(Builder $query, ?object $user): Builder
    {
        $collaborator = self::collaboratorFor($user);
        if ($collaborator === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where($query->getModel()->qualifyColumn('team_id'), $collaborator->team_id)
            ->whereIn($query->getModel()->qualifyColumn('id'), CollaboratorProperty::query()->active()
                ->where('collaborator_id', $collaborator->id)->select('property_id'));
    }

    /** Whether this collaborator (User or Collaborator) may see $record. Unknown models: denied. */
    public static function canSee(?object $user, Model $record): bool
    {
        $collaborator = self::collaboratorFor($user);
        if ($collaborator === null || (string) $record->getAttribute('team_id') !== (string) $collaborator->team_id) {
            return false;
        }

        return match (true) {
            $record instanceof Property => self::canSeeProperty($collaborator, $record),
            $record instanceof PropertyUpdate => self::canSeeUpdate($collaborator, $record),
            isset(self::$scopes[$record::class]) => self::$scopes[$record::class]->canSee($collaborator, $record),
            default => false,
        };
    }

    /** Restricts any query to what this collaborator may see. Unknown models: empty. */
    public static function scope(Builder $query, ?object $user): Builder
    {
        $collaborator = self::collaboratorFor($user);
        $model = $query->getModel();
        if ($collaborator === null) {
            return $query->whereRaw('1 = 0');
        }

        return match (true) {
            $model instanceof Property => self::scopeProperties($query, $collaborator),
            $model instanceof PropertyUpdate => self::scopeUpdates($query, $collaborator),
            isset(self::$scopes[$model::class]) => self::$scopes[$model::class]->scope($query->where($model->qualifyColumn('team_id'), $collaborator->team_id), $collaborator),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /** Shared updates of the collaborator's assigned properties that were addressed to them. */
    public static function scopeUpdates(Builder $query, ?object $user): Builder
    {
        $collaborator = self::collaboratorFor($user);
        if ($collaborator === null) {
            return $query->whereRaw('1 = 0');
        }
        $table = $query->getModel()->getTable();

        return $query->where("{$table}.team_id", $collaborator->team_id)
            ->where("{$table}.visibility", PropertyUpdate::SHARED)->where("{$table}.status", PropertyUpdate::SENT)
            ->whereIn("{$table}.property_id", CollaboratorProperty::query()->active()->where('collaborator_id', $collaborator->id)->select('property_id'))
            ->whereIn("{$table}.id", CollaboratorNotice::query()->where('collaborator_id', $collaborator->id)
                ->where('subject_type', (new PropertyUpdate)->getMorphClass())->where('status', '!=', CollaboratorNotice::CANCELLED)->select('subject_id'));
    }

    private static function canSeeUpdate(Collaborator $collaborator, PropertyUpdate $update): bool
    {
        return self::scopeUpdates(PropertyUpdate::query()->whereKey($update->getKey()), $collaborator)->exists();
    }
}
