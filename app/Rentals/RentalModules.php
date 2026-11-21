<?php

declare(strict_types=1);

namespace App\Rentals;

use Illuminate\Support\Facades\DB;

/**
 * «Alquiler tradicional», «Alquiler por habitaciones» and «Alquiler vacacional» switches. Manifests live in
 * app/Modules/manifests/clarto-alquiler-*. With the CLARTO module registry (App\Modules\ClartoModules, branch
 * feat/modulos-clarto) present, its answer rules; without it, the module manager's module_activations row
 * (default on). A switched-off module refuses NEW operations; its history stays readable.
 */
final class RentalModules
{
    public const KEYS = ['tradicional' => 'alquiler_tradicional', 'habitaciones' => 'alquiler_habitaciones', 'vacacional' => 'alquiler_vacacional'];

    public static function enabled(string $module): bool
    {
        $key = self::KEYS[$module] ?? throw new \InvalidArgumentException("Unknown rental module [{$module}].");
        $registry = 'App\Modules\ClartoModules';
        if (class_exists($registry) && in_array($key, $registry::keys(), true)) {
            return $registry::enabled($key);
        }
        $choice = rescue(fn () => DB::table('module_activations')->where('module', 'clarto-'.str_replace('_', '-', $key))->value('enabled'), null, report: false);

        return $choice === null || (bool) $choice;
    }

    public static function ensureEnabled(string $module): void
    {
        if (! self::enabled($module)) {
            throw new RentalRefused(__('clarto.alquileres.errors.module_off', ['module' => __('clarto.alquileres.modules.'.$module)]));
        }
    }
}
