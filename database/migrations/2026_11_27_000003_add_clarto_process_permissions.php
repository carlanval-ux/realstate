<?php

declare(strict_types=1);

use App\Processes\ProcessAccess;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * «Procesos y conocimiento» permissions (Spatie). Company administrators hold them all; «procesos.ver» is given to the
 * «staff» role of every company (routine and reversible from «Roles»); the rest are assigned from «Roles».
 */
return new class() extends Migration
{
    public function up(): void
    {
        $permissions = config('permission.table_names.permissions', 'permissions');
        foreach (ProcessAccess::PERMISSIONS as $name) {
            if (! DB::table($permissions)->where('name', $name)->where('guard_name', 'web')->exists()) {
                DB::table($permissions)->insert(['name' => $name, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        $viewId = DB::table($permissions)->where('name', ProcessAccess::VIEW)->where('guard_name', 'web')->value('id');
        $pivot = config('permission.table_names.role_has_permissions', 'role_has_permissions');
        $roleKey = (string) (config('permission.column_names.role_pivot_key') ?: 'role_id');
        $permissionKey = (string) (config('permission.column_names.permission_pivot_key') ?: 'permission_id');
        foreach (DB::table(config('permission.table_names.roles', 'roles'))->where('name', 'staff')->where('guard_name', 'web')->pluck('id') as $roleId) {
            DB::table($pivot)->insertOrIgnore([$roleKey => $roleId, $permissionKey => $viewId]);
        }
        rescue(fn () => app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions(), report: false);
    }

    public function down(): void
    {
        DB::table(config('permission.table_names.permissions', 'permissions'))->whereIn('name', ProcessAccess::PERMISSIONS)->delete();
    }
};
