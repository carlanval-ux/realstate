<?php

declare(strict_types=1);

namespace App\Rentals;

use Illuminate\Support\Facades\Gate;

/**
 * Rental permissions (Spatie, created by migration 2026_11_22_000002). Administrators hold them all; other staff get
 * them through «Roles». Identity, solvency, contracts and traveller data each have their own permission.
 */
final class RentalAccess
{
    public const VIEW = 'alquileres.ver';

    public const MANAGE = 'alquileres.gestionar';

    public const IDENTITY = 'alquileres.identidad';

    public const SOLVENCY = 'alquileres.solvencia';

    public const CONTRACTS = 'alquileres.contratos';

    public const TRAVELLERS = 'alquileres.viajeros';

    public const PERMISSIONS = [self::VIEW, self::MANAGE, self::IDENTITY, self::SOLVENCY, self::CONTRACTS, self::TRAVELLERS];

    public static function allows(?object $user, string $permission): bool
    {
        if ($user === null) {
            return false;
        }
        if ((method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) || (method_exists($user, 'isAdmin') && $user->isAdmin())) {
            return true;
        }

        return method_exists($user, 'hasPermissionTo') && rescue(fn (): bool => (bool) $user->hasPermissionTo($permission), false, report: false);
    }

    public static function ensure(?object $user, string $permission): void
    {
        if (! self::allows($user, $permission)) {
            throw new RentalRefused(__('clarto.alquileres.errors.forbidden'));
        }
    }

    /** Gate abilities with the same names, so `can('alquileres.identidad')` works in the panel. */
    public static function defineGates(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            Gate::define($permission, fn (?object $user): bool => self::allows($user, $permission));
        }
    }
}
