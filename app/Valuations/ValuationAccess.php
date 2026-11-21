<?php

declare(strict_types=1);

namespace App\Valuations;

use App\Models\ImprovementRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Who sees valuation requests and their reports: super administrators; administrators of the request's team; staff of
 * that team whose role (in that team) holds «valoraciones.ver». Recalculating and syncing need «valoraciones.gestionar»
 * (or administration). Another team never sees them. Permissions created by migration 2026_11_25_000001.
 */
final class ValuationAccess
{
    public const VIEW = 'valoraciones.ver';

    public const MANAGE = 'valoraciones.gestionar';

    public const PERMISSIONS = [self::VIEW, self::MANAGE];

    public static function allows(?object $user, int|string|null $teamId, string $permission = self::VIEW): bool
    {
        if (! $user instanceof User || $teamId === null) {
            return false;
        }
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }
        if (ImprovementRequest::isTeamAdmin($user, $teamId)) {
            return true;
        }

        $names = $permission === self::VIEW ? [self::VIEW, self::MANAGE] : [self::MANAGE];
        $t = fn (string $key, string $default): string => (string) (config("permission.table_names.{$key}") ?: $default);
        $teamKey = (string) (config('permission.column_names.team_foreign_key') ?: 'team_id');
        $modelKey = (string) (config('permission.column_names.model_morph_key') ?: 'model_id');
        $permissionIds = DB::table($t('permissions', 'permissions'))->whereIn('name', $names)->where('guard_name', 'web')->pluck('id');
        if ($permissionIds->isEmpty()) {
            return false;
        }

        $viaRole = DB::table($t('model_has_roles', 'model_has_roles').' as mr')
            ->join($t('role_has_permissions', 'role_has_permissions').' as rp', 'rp.role_id', '=', 'mr.role_id')
            ->where("mr.{$modelKey}", $user->getKey())->where('mr.model_type', $user->getMorphClass())
            ->where("mr.{$teamKey}", $teamId)->whereIn('rp.permission_id', $permissionIds)->exists();

        return $viaRole || DB::table($t('model_has_permissions', 'model_has_permissions'))
            ->where($modelKey, $user->getKey())->where('model_type', $user->getMorphClass())
            ->where($teamKey, $teamId)->whereIn('permission_id', $permissionIds)->exists();
    }

    public static function defineGates(): void
    {
        Gate::define('viewValuation', fn (?object $user, ValuationRequest $request): bool => self::allows($user, $request->team_id));
        Gate::define('manageValuation', fn (?object $user, ValuationRequest $request): bool => self::allows($user, $request->team_id, self::MANAGE));
    }
}
