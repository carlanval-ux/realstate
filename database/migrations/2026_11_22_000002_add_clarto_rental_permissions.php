<?php

declare(strict_types=1);

use App\Rentals\RentalAccess;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Rental permissions (Spatie). Administrators have them all; other roles get them from «Roles». */
return new class() extends Migration
{
    public function up(): void
    {
        $table = config('permission.table_names.permissions', 'permissions');
        foreach (RentalAccess::PERMISSIONS as $name) {
            if (! DB::table($table)->where('name', $name)->where('guard_name', 'web')->exists()) {
                DB::table($table)->insert(['name' => $name, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        rescue(fn () => app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions(), report: false);
    }

    public function down(): void
    {
        DB::table(config('permission.table_names.permissions', 'permissions'))->whereIn('name', RentalAccess::PERMISSIONS)->delete();
    }
};
