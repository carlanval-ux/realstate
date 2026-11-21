<?php

declare(strict_types=1);

namespace App\InboundMail;

use App\Collaborators\CollaboratorAccess;
use App\Models\ImprovementRequest;
use Illuminate\Support\Facades\DB;

/**
 * Who sees «Correo entrante»: staff (never collaborators) of the mailbox's team who are super administrators, team
 * administrators, or hold «correo.ver» (list, sender, subject, category; review) in that team. Bodies and attachments
 * need «correo.contenido» (or administration). Another team never sees it. Permissions created by migration 2026_12_03_000001.
 */
final class InboundMailAccess
{
    public const VIEW = 'correo.ver';

    public const CONTENT = 'correo.contenido';

    public const PERMISSIONS = [self::VIEW, self::CONTENT];

    public static function canView(?object $user, int|string|null $teamId): bool
    {
        return self::allows($user, $teamId, self::PERMISSIONS);
    }

    public static function canReadContent(?object $user, int|string|null $teamId): bool
    {
        return self::allows($user, $teamId, [self::CONTENT]);
    }

    /** @param list<string> $names any of these permissions, through a role in that team or directly */
    private static function allows(?object $user, int|string|null $teamId, array $names): bool
    {
        if (! CollaboratorAccess::isStaff($user) || $teamId === null || ! $user instanceof \App\Models\User) {
            return false;
        }
        if ($user->isSuperAdmin() || ImprovementRequest::isTeamAdmin($user, $teamId)) {
            return true;
        }
        $t = fn (string $key, string $default): string => (string) (config("permission.table_names.{$key}") ?: $default);
        $teamKey = (string) (config('permission.column_names.team_foreign_key') ?: 'team_id');
        $modelKey = (string) (config('permission.column_names.model_morph_key') ?: 'model_id');
        $ids = DB::table($t('permissions', 'permissions'))->whereIn('name', $names)->where('guard_name', 'web')->pluck('id');
        if ($ids->isEmpty()) {
            return false;
        }

        return DB::table($t('model_has_roles', 'model_has_roles').' as mr')
            ->join($t('role_has_permissions', 'role_has_permissions').' as rp', 'rp.role_id', '=', 'mr.role_id')
            ->where("mr.{$modelKey}", $user->getKey())->where('mr.model_type', $user->getMorphClass())
            ->where("mr.{$teamKey}", $teamId)->whereIn('rp.permission_id', $ids)->exists()
            || DB::table($t('model_has_permissions', 'model_has_permissions'))
                ->where($modelKey, $user->getKey())->where('model_type', $user->getMorphClass())
                ->where($teamKey, $teamId)->whereIn('permission_id', $ids)->exists();
    }
}
