<?php

declare(strict_types=1);

namespace App\Processes;

use App\Models\ImprovementRequest;
use App\Models\User;
use App\Processes\Models\Area;
use App\Processes\Models\PositionAssignment;
use App\Processes\Models\Process;
use App\Processes\Models\ProcessAttachment;
use App\Processes\Models\ProcessSettings;
use App\Processes\Models\ProcessVersion;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * The only place that decides who sees what (diseno.md §7). No cache: an assignment ended yesterday, a disabled account
 * or a change of audience apply on the next call. Permissions are checked against the given team (model_has_roles /
 * model_has_permissions filtered by team_id), never through hasPermissionTo (bound to the active team).
 */
final class ProcessAccess
{
    public const VIEW = 'procesos.ver';

    public const AUTHOR = 'procesos.redactar';

    public const REVIEW = 'procesos.revisar';

    public const APPROVE = 'procesos.aprobar';

    public const ADMIN = 'procesos.administrar';

    public const PERMISSIONS = [self::VIEW, self::AUTHOR, self::REVIEW, self::APPROVE, self::ADMIN];

    /**
     * CURRENT PUBLISHED versions $user may read in $teamId (default: Filament tenant or current_team_id). No management
     * pass. Null user, no company or not staff → empty query.
     *
     * @return Builder<ProcessVersion>
     */
    public static function visibleQuery(?User $user, int|string|null $teamId = null): Builder
    {
        $teamId ??= rescue(fn () => Filament::getTenant()?->getKey(), report: false) ?? $user?->current_team_id;
        $query = ProcessVersion::query()->select('clarto_process_versions.*')
            ->join('clarto_processes', 'clarto_processes.id', '=', 'clarto_process_versions.process_id');
        if ($user === null || $teamId === null || ! self::isStaff($user, $teamId)) {
            return $query->whereRaw('1 = 0');
        }
        $positionIds = self::positionIds($user, $teamId);
        $areaIds = self::areaIds($user, $teamId);
        $userId = $user->getKey();

        return $query
            ->where('clarto_process_versions.team_id', $teamId)->where('clarto_processes.team_id', $teamId)
            ->where('clarto_processes.status', 'publicado')->where('clarto_process_versions.status', 'publicada')
            ->whereColumn('clarto_processes.current_version_id', 'clarto_process_versions.id')
            ->where(fn (Builder $q) => $q->where('clarto_processes.visibility', 'empresa')->orWhere(fn (Builder $r) => $r
                ->where('clarto_processes.visibility', 'restringido')
                ->whereExists(fn (QueryBuilder $a) => $a->select(DB::raw(1))->from('clarto_process_audiences as aud')
                    ->whereColumn('aud.process_id', 'clarto_processes.id')->where('aud.effect', 'allow')
                    ->where(fn (QueryBuilder $m) => $m->where(fn ($x) => $x->where('aud.type', 'user')->where('aud.target_id', $userId))
                        ->orWhere(fn ($x) => $x->where('aud.type', 'position')->whereIn('aud.target_id', $positionIds ?: [0]))
                        ->orWhere(fn ($x) => $x->where('aud.type', 'area')->whereIn('aud.target_id', $areaIds ?: [0]))))
                ->whereNotExists(fn (QueryBuilder $d) => $d->select(DB::raw(1))->from('clarto_process_audiences as den')
                    ->whereColumn('den.process_id', 'clarto_processes.id')->where('den.effect', 'deny')
                    ->where('den.type', 'user')->where('den.target_id', $userId))));
    }

    /** A specific version, with the management pass of diseno.md §7.3 (drafts, review and history). */
    public static function canView(?User $user, ProcessVersion $version): bool
    {
        $process = $version->process;
        if ($user === null || $process === null || (string) $process->team_id !== (string) $version->team_id || ! self::isStaff($user, $process->team_id)) {
            return false;
        }
        if (self::manages($user, $process)) {
            return true;
        }
        $teamId = $process->team_id;
        if ($version->status === 'en_revision' && self::has($user, $teamId, [self::REVIEW])) {
            return true;
        }
        if ($version->isOpen() && (string) $version->created_by === (string) $user->getKey()) {
            return true;
        }
        if ($version->isOpen() && self::has($user, $teamId, [self::AUTHOR]) && self::canViewProcess($user, $process)) {
            return true;
        }

        return (string) $process->current_version_id === (string) $version->getKey() && self::canViewProcess($user, $process);
    }

    /** Reading the current version of a published process (no management pass). */
    public static function canViewProcess(?User $user, Process $process): bool
    {
        return $user !== null && self::visibleQuery($user, $process->team_id)->where('clarto_processes.id', $process->getKey())->exists();
    }

    public static function canDownload(?User $user, ProcessAttachment $attachment): bool
    {
        return $attachment->version !== null && self::canView($user, $attachment->version);
    }

    /** Create or edit the draft of $process. */
    public static function canEdit(?User $user, Process $process): bool
    {
        if ($user === null || ! self::isStaff($user, $process->team_id)) {
            return false;
        }
        if ($process->status === 'archivado') {
            return self::isAdmin($user, $process->team_id) || self::has($user, $process->team_id, [self::APPROVE]);
        }
        if (self::isAdmin($user, $process->team_id) || self::isOwner($user, $process)) {
            return true;
        }
        $open = $process->openVersion;
        if ($open !== null && (string) $open->created_by === (string) $user->getKey()) {
            return true;
        }

        return self::has($user, $process->team_id, [self::AUTHOR])
            && ($process->status === 'borrador' ? (string) $process->created_by === (string) $user->getKey() : self::canViewProcess($user, $process));
    }

    /** New processes: «procesos.redactar» or company administrator. */
    public static function canCreate(?User $user, int|string $teamId): bool
    {
        return $user !== null && self::isStaff($user, $teamId) && (self::isAdmin($user, $teamId) || self::has($user, $teamId, [self::AUTHOR, self::ADMIN]));
    }

    /** Send back with comments / give the OK. */
    public static function canReview(?User $user, ProcessVersion $version): bool
    {
        $teamId = $version->team_id;

        return $user !== null && $version->status === 'en_revision' && self::isStaff($user, $teamId)
            && (self::isAdmin($user, $teamId) || self::has($user, $teamId, [self::REVIEW, self::APPROVE]));
    }

    /** Approve and publish, with the four-eyes rule (the author only if company administrator, or four-eyes off + aprobar). */
    public static function canPublish(?User $user, ProcessVersion $version): bool
    {
        $teamId = $version->team_id;
        if ($user === null || $version->status !== 'en_revision' || ! self::isStaff($user, $teamId)) {
            return false;
        }
        $admin = self::isAdmin($user, $teamId);
        $approver = self::has($user, $teamId, [self::APPROVE]);
        if (! $admin && ! $approver) {
            return false;
        }

        return (string) $version->created_by !== (string) $user->getKey() || $admin || ! ProcessSettings::forTeam($teamId)->require_four_eyes;
    }

    /** Archive, confirm validity: owner, «procesos.aprobar» or administrator. */
    public static function canMaintain(?User $user, Process $process): bool
    {
        return $user !== null && self::isStaff($user, $process->team_id)
            && (self::isOwner($user, $process) || self::isAdmin($user, $process->team_id) || self::has($user, $process->team_id, [self::APPROVE]));
    }

    /** «procesos.administrar» or company administrator. */
    public static function canAdminister(?User $user, int|string $teamId): bool
    {
        return $user !== null && self::isMember($user, $teamId) && blank($user->disabled_at)
            && (self::isAdmin($user, $teamId) || self::has($user, $teamId, [self::ADMIN]));
    }

    /** Management screens (pending review, history): administer, approve or review. */
    public static function canManage(?User $user, int|string $teamId): bool
    {
        return self::canAdminister($user, $teamId) || ($user !== null && self::isStaff($user, $teamId) && self::has($user, $teamId, [self::APPROVE, self::REVIEW]));
    }

    /** Staff of the company: active member and (a procesos.* permission, an active position or administrator). §7.3 points 1–2. */
    public static function isStaff(?User $user, int|string $teamId): bool
    {
        if ($user === null || filled($user->disabled_at) || ! self::isMember($user, $teamId)) {
            return false;
        }

        return self::isAdmin($user, $teamId) || self::has($user, $teamId, self::PERMISSIONS) || self::positionIds($user, $teamId) !== [];
    }

    /** Active assignments. @return list<int> */
    public static function positionIds(User $user, int|string $teamId): array
    {
        return PositionAssignment::query()->active()->where('clarto_position_user.team_id', $teamId)->where('clarto_position_user.user_id', $user->getKey())
            ->join('clarto_positions', 'clarto_positions.id', '=', 'clarto_position_user.position_id')->where('clarto_positions.active', true)
            ->distinct()->pluck('clarto_position_user.position_id')->map(fn ($id): int => (int) $id)->values()->all();
    }

    /** @return list<int> */
    public static function areaIds(User $user, int|string $teamId): array
    {
        $positions = self::positionIds($user, $teamId);

        return $positions === [] ? [] : Area::query()->whereIn('id', DB::table('clarto_positions')->whereIn('id', $positions)->select('area_id'))
            ->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
    }

    /** Member of the team (owner or team_user), straight from the database. */
    public static function isMember(User $user, int|string $teamId): bool
    {
        return DB::table('teams')->where('id', $teamId)->where('user_id', $user->getKey())->exists()
            || DB::table('team_user')->where('team_id', $teamId)->where('user_id', $user->getKey())->exists();
    }

    /** Company administrator (admin / super_admin role in that team, or team owner) or super administrator. */
    public static function isAdmin(User $user, int|string $teamId): bool
    {
        return (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) || ImprovementRequest::isTeamAdmin($user, $teamId);
    }

    /** Management pass for any version of $process: owner, administrators, «aprobar» or «administrar». */
    public static function manages(User $user, Process $process): bool
    {
        return self::isOwner($user, $process) || self::isAdmin($user, $process->team_id) || self::has($user, $process->team_id, [self::APPROVE, self::ADMIN]);
    }

    public static function isOwner(User $user, Process $process): bool
    {
        return $process->owner_user_id !== null && (string) $process->owner_user_id === (string) $user->getKey();
    }

    /** Has any of $names in $teamId, through a role of that team or directly. @param list<string> $names */
    public static function has(User $user, int|string $teamId, array $names): bool
    {
        $t = fn (string $key, string $default): string => (string) (config("permission.table_names.{$key}") ?: $default);
        $teamKey = (string) (config('permission.column_names.team_foreign_key') ?: 'team_id');
        $modelKey = (string) (config('permission.column_names.model_morph_key') ?: 'model_id');
        $permissionIds = DB::table($t('permissions', 'permissions'))->whereIn('name', $names)->where('guard_name', 'web')->pluck('id');
        if ($permissionIds->isEmpty()) {
            return false;
        }

        return DB::table($t('model_has_roles', 'model_has_roles').' as mr')
            ->join($t('role_has_permissions', 'role_has_permissions').' as rp', 'rp.role_id', '=', 'mr.role_id')
            ->where("mr.{$modelKey}", $user->getKey())->where('mr.model_type', $user->getMorphClass())
            ->where("mr.{$teamKey}", $teamId)->whereIn('rp.permission_id', $permissionIds)->exists()
            || DB::table($t('model_has_permissions', 'model_has_permissions'))
                ->where($modelKey, $user->getKey())->where('model_type', $user->getMorphClass())
                ->where($teamKey, $teamId)->whereIn('permission_id', $permissionIds)->exists();
    }
}
