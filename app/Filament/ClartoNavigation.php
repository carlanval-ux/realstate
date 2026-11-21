<?php

declare(strict_types=1);

namespace App\Filament;

use App\Filament\Pages\UserManual;
use Closure;
use Filament\Panel;
use Filament\Resources\Resource;
use ReflectionProperty;

/**
 * CLARTO's menu, applied on every panel request after the visitor's language is set (called from the
 * application's NavigationGroups::configure):
 *
 * - labels the modules hard-code in English get translated;
 * - resource labels in sentence case ("Seguimiento de ventas", not "Seguimiento De Ventas");
 * - "Start" group on top with the overview and the user manual.
 *
 * Filament keeps navigation settings in static properties: a page or resource that does not declare one shares
 * its parent's, so setting it would change every such class. Only properties a class declares itself are set.
 * Technical screens are hidden for non-super-administrators by technicalNavigationCss() instead.
 */
final class ClartoNavigation
{
    /** Menu entries (by URL path ending) only super administrators need: diagnostics, integrations, installer, modules, UK portals. */
    public const TECHNICAL_PATHS = [
        '/installation-setup', '/modules', '/modulos', '/activity-logs',
        '/rightmove-syncs', '/zoopla-syncs', '/on-the-market-syncs', '/onthemarket-syncs', '/portal-reports',
    ];

    public static function configure(Panel $panel): void
    {
        UserManual::navigationGroup(__('Start'));
        UserManual::navigationSort(2);

        if ($panel->getId() !== 'admin') {
            return;
        }

        $dashboard = 'Liberu\\RealEstate\\Application\\Filament\\Pages\\Dashboard';
        self::set($dashboard, 'navigationGroup', __('Start'));
        self::set($dashboard, 'navigationSort', 1);
        self::set($dashboard, 'title', __('Overview'));

        foreach ([
            'Liberu\\RealEstate\\Application\\Filament\\Pages\\InstallationSetupWizard' => ['navigationLabel', 'Installation setup'],
            'Liberu\\Foundation\\ModuleManagerFilament\\Pages\\ModuleManagement' => ['title', 'Modules'],
            'Liberu\\Foundation\\IdentityFilament\\Resources\\UserResource' => ['navigationLabel', 'Users'],
            'Liberu\\Foundation\\OrganizationsFilament\\Resources\\TeamResource' => ['navigationLabel', 'Teams'],
        ] as $class => [$property, $label]) {
            self::set($class, $property, __($label));
        }

        // Meant to be global: every resource label in sentence case.
        self::set(Resource::class, 'hasTitleCaseModelLabel', false);
    }

    /**
     * Package pages replaced by a CLARTO page, hidden from everyone's menu: the package's «Modules» (…/modules) is
     * superseded by App\Filament\Pages\Modules (…/modulos), which lists the same official modules plus CLARTO's.
     * Its ability is denied too, but Shield's super-administrator intercept grants every ability, so the menu entry
     * is hidden here as well.
     */
    public const REPLACED_PATHS = ['/modules'];

    /** CSS that hides the technical entries (and the groups made only of them) for everyone but super administrators. */
    public static function technicalNavigationCss(): string
    {
        $replaced = implode(",\n", array_map(fn (string $p): string => ".fi-sidebar-item:has(> a[href$=\"{$p}\"])", self::REPLACED_PATHS));
        $replacedCss = "<style>\n{$replaced} { display: none !important; }\n</style>";

        $user = auth()->user();
        if ($user === null || (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin())) {
            return $replacedCss;
        }

        $items = implode(",\n", array_map(fn (string $p): string => ".fi-sidebar-item:has(> a[href$=\"{$p}\"])", self::TECHNICAL_PATHS));

        return $replacedCss."<style>\n{$items},\n.fi-sidebar-item:has(> a[href*=\"/overview-\"]),\n"
            .'.fi-sidebar-group:has(a[href*="/overview-"]):not(:has(a:not([href*="/overview-"]):not([href$="/activity-logs"]))) { display: none !important; }'
            ."\n</style>";
    }

    /** Sets a static property only on a class that declares it itself (never on a shared parent). */
    private static function set(string $class, string $property, mixed $value): void
    {
        if (! class_exists($class) || ! property_exists($class, $property)) {
            return;
        }
        if ((new ReflectionProperty($class, $property))->getDeclaringClass()->getName() !== ltrim($class, '\\')) {
            return;
        }
        Closure::bind(static function () use ($property, $value): void {
            static::${$property} = $value;
        }, null, $class)();
    }
}
