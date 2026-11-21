<?php

declare(strict_types=1);

namespace App\Modules;

use Closure;
use Composer\InstalledVersions;
use Composer\Semver\Semver;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Liberu\Foundation\ModuleManager\Exceptions\ModuleActivationRefused;
use Liberu\Foundation\ModuleManager\Manifest;
use Liberu\Foundation\ModuleManager\ModuleActivations;
use Liberu\Foundation\ModuleManager\ModuleInventory;
use Throwable;

/**
 * CLARTO's feature modules: «Firma electrónica», «Documentos y formularios», «WhatsApp», «Idiomas y traducción»,
 * «Portal pisos.com» and «Captación GoHighLevel».
 *
 * Each one is a Liberu manifest (app/Modules/manifests/<name>/module.json, read and validated by the module manager's
 * own Manifest class, "module_type": "feature") whose administrator choice is stored in the module manager's own
 * module_activations table under its manifest name (clarto-whatsapp…). MODULES_ENABLED / MODULES_DISABLED apply to
 * them exactly as to official modules, and MODULES_DISABLED wins and locks the switch.
 *
 * Their code stays in the host (app/), so switching one off does not unload classes: it stops NEW operations.
 * Every feature asks before acting:
 *
 *     ClartoModules::enabled('whatsapp')         // bool: may a new operation start?
 *     ClartoModules::status('whatsapp')          // ModuleStatus, what «Módulos» shows
 *     ClartoModules::ensureEnabled('whatsapp')   // throws ModuleDisabled (HTTP 403 with a clear message)
 *     ClartoModules::refusal('whatsapp')         // ?string, the same message for a notification or a JSON answer
 *
 * and an implementation announces itself (once, from a service provider) with what it needs to be usable:
 *
 *     ClartoModules::implement('whatsapp',
 *         missingConfiguration: fn (): ?string => $session->ok() ? null : __('…'),
 *         settingsUrl: fn (): ?string => WhatsAppSettings::getUrl());
 *
 * A module nobody implements is listed as «Pendiente de implementar»: being listed is not being implemented.
 */
final class ClartoModules
{
    public const FIRMA = 'firma';

    public const DOCUMENTOS = 'documentos';

    public const WHATSAPP = 'whatsapp';

    public const IDIOMAS = 'idiomas';

    public const PISOS = 'pisos';

    public const CAPTACION = 'captacion';

    /** Who manages modules on the CLARTO «Módulos» page (administrators, as the package page's own ability). */
    public const ABILITY = 'manageClartoModules';

    /** Per-request copy of the administrators' choices (scoped: flushed between Octane requests). */
    public const CHOICES = 'clarto.modules.choices';

    private const PREFIX = 'clarto-';

    /** Order on the «Módulos» page. */
    private const ORDER = [self::IDIOMAS, self::DOCUMENTOS, self::FIRMA, self::WHATSAPP, self::PISOS, self::CAPTACION];

    /** @var array<string, array{missing: ?Closure, settings: ?Closure}> set once at boot by each implementation */
    private static array $implementations = [];

    /** @var array<string, Manifest>|null */
    private static ?array $manifests = null;

    /** @var array<string, string>|null Composer package => Liberu module name */
    private static ?array $packageModules = null;

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::manifests());
    }

    /** @return array<string, Manifest> by key, in page order */
    public static function manifests(): array
    {
        if (self::$manifests === null) {
            $found = [];
            foreach (glob(__DIR__.'/manifests/*/module.json') ?: [] as $path) {
                $manifest = Manifest::fromFile($path);
                $found[self::keyOf($manifest)] = $manifest;
            }
            $position = fn (string $key): int => ($i = array_search($key, self::ORDER, true)) === false ? 99 : (int) $i;
            uksort($found, fn (string $a, string $b): int => $position($a) <=> $position($b));
            self::$manifests = $found;
        }

        return self::$manifests;
    }

    public static function manifest(string $key): Manifest
    {
        return self::manifests()[$key] ?? throw new InvalidArgumentException("Unknown CLARTO module [{$key}].");
    }

    /** Manifest name, as stored in module_activations and named in MODULES_ENABLED / MODULES_DISABLED. */
    public static function moduleName(string $key): string
    {
        return self::manifest($key)->name();
    }

    public static function displayName(string $key): string
    {
        return __(self::manifest($key)->displayName());
    }

    /**
     * Called by the code that implements a module, from a service provider.
     *
     * @param  (Closure(): ?string)|null  $missingConfiguration  what is missing to use it (credentials, a session), or null
     * @param  (Closure(): ?string)|null  $settingsUrl  where an administrator completes that configuration
     */
    public static function implement(string $key, ?Closure $missingConfiguration = null, ?Closure $settingsUrl = null): void
    {
        self::manifest($key);
        self::$implementations[$key] = ['missing' => $missingConfiguration, 'settings' => $settingsUrl];
    }

    public static function isImplemented(string $key): bool
    {
        self::manifest($key);

        return isset(self::$implementations[$key]);
    }

    /** The administrator's switch (or the manifest default), with MODULES_DISABLED / MODULES_ENABLED applied. */
    public static function switchedOn(string $key): bool
    {
        $name = self::moduleName($key);

        if (in_array($name, (array) config('modules.disabled', []), true)) {
            return false;
        }

        return self::choices()[$name] ?? (self::manifest($key)->defaultEnabled() || in_array($name, (array) config('modules.enabled', []), true));
    }

    /** May a NEW operation of this module start? Existing records stay readable either way. */
    public static function enabled(string $key): bool
    {
        return self::switchedOn($key) && self::problems($key) === [];
    }

    public static function status(string $key): ModuleStatus
    {
        return match (true) {
            ! self::switchedOn($key) => ModuleStatus::Disabled,
            self::problems($key) !== [] => ModuleStatus::Failed,
            ! self::isImplemented($key) => ModuleStatus::NotImplemented,
            self::missingConfiguration($key) !== null => ModuleStatus::PendingConfiguration,
            default => ModuleStatus::Enabled,
        };
    }

    /** Why a new operation is refused, or null when it may start. */
    public static function refusal(string $key): ?string
    {
        if (! self::switchedOn($key)) {
            return __('The «:module» module is disabled: no new operations are allowed. Existing records and documents are kept. An administrator can enable it in Platform settings → Modules.', ['module' => self::displayName($key)]);
        }

        if (($problems = self::problems($key)) !== []) {
            return __('The «:module» module cannot run: :problems', ['module' => self::displayName($key), 'problems' => implode(' ', $problems)]);
        }

        return null;
    }

    /** @throws ModuleDisabled */
    public static function ensureEnabled(string $key): void
    {
        if (($refusal = self::refusal($key)) !== null) {
            throw new ModuleDisabled($key, $refusal);
        }
    }

    /** What is missing before the module can be used (credentials, a session), or null. */
    public static function missingConfiguration(string $key): ?string
    {
        $probe = self::$implementations[$key]['missing'] ?? null;
        if ($probe === null) {
            return null;
        }

        try {
            return $probe();
        } catch (Throwable $e) {
            report($e);

            return __('The configuration could not be checked.');
        }
    }

    public static function settingsUrl(string $key): ?string
    {
        $url = self::$implementations[$key]['settings'] ?? null;

        try {
            return $url === null ? null : $url();
        } catch (Throwable) {
            return null;   // e.g. a panel URL asked for outside a panel request
        }
    }

    /** Why the switch cannot be changed from the panel, or null. */
    public static function locked(string $key): ?string
    {
        return in_array(self::moduleName($key), (array) config('modules.disabled', []), true)
            ? __('Disabled by the MODULES_DISABLED setting.')
            : null;
    }

    /**
     * Official packages the module builds on that are missing, too old or switched off.
     *
     * @return list<string>
     */
    public static function problems(string $key): array
    {
        $problems = [];
        $inventory = app()->bound(ModuleInventory::class) ? app(ModuleInventory::class) : null;

        foreach (self::manifest($key)->requiredPackages() as $package => $constraint) {
            if (! InstalledVersions::isInstalled($package)) {
                $problems[] = __('Requires :package, which is not installed.', ['package' => $package]);

                continue;
            }

            $version = ltrim((string) InstalledVersions::getPrettyVersion($package), 'v');
            try {
                $satisfied = Semver::satisfies($version, $constraint);
            } catch (Throwable) {
                $satisfied = true;   // a dev branch: nothing to compare
            }
            if (! $satisfied) {
                $problems[] = __('Requires :package :constraint (installed: :version).', ['package' => $package, 'constraint' => $constraint, 'version' => $version]);
            }

            $module = self::packageModules()[$package] ?? null;
            if ($inventory !== null && $module !== null && ! $inventory->isBooted($module)) {
                $problems[] = __('Requires the :module module, which is not running.', ['module' => $module]);
            }
        }

        return $problems;
    }

    /** @throws ModuleActivationRefused */
    public static function enable(string $key): void
    {
        self::change($key, true);
    }

    /** @throws ModuleActivationRefused */
    public static function disable(string $key): void
    {
        self::change($key, false);
    }

    /**
     * One row per module for «Módulos» and the test command.
     *
     * @return array<string, array{module: string, key: string, source: string, name: string, description: string, version: string, status: string, locked: ?string, problems: list<string>, note: ?string, settings_url: ?string, changeable: bool}>
     */
    public static function rows(): array
    {
        $rows = [];

        foreach (self::manifests() as $key => $manifest) {
            $status = self::status($key);
            $extra = $manifest->toArray()['clarto'] ?? [];
            $notes = array_filter([
                ...self::problems($key),
                self::locked($key),
                ! self::isImplemented($key) ? __('Its implementation is not in this build yet (branch :branch).', ['branch' => $extra['branch'] ?? '—']) : null,
                ($extra['implementation'] ?? null) === 'partial' ? __('Forms: pending implementation (branch :branch).', ['branch' => $extra['branch'] ?? '—']) : null,
                $status === ModuleStatus::PendingConfiguration ? self::missingConfiguration($key) : null,
            ]);

            $rows[$manifest->name()] = [
                'module' => $manifest->name(),
                'key' => $key,
                'source' => 'clarto',
                'name' => __($manifest->displayName()),
                'description' => __($manifest->description()),
                'version' => $manifest->version(),
                'status' => $status->value,
                'locked' => self::locked($key),
                'problems' => self::problems($key),
                'note' => $notes === [] ? null : implode(' ', $notes),
                'settings_url' => $status === ModuleStatus::PendingConfiguration ? self::settingsUrl($key) : null,
                'changeable' => self::locked($key) === null,
            ];
        }

        return $rows;
    }

    private static function change(string $key, bool $enable): void
    {
        if (($reason = self::locked($key)) !== null) {
            throw new ModuleActivationRefused($reason);
        }
        if ($enable && ($problems = self::problems($key)) !== []) {
            throw new ModuleActivationRefused(implode(' ', $problems));
        }

        app(ModuleActivations::class)->set(self::moduleName($key), $enable);
        app()->forgetInstance(self::CHOICES);

        Log::info('CLARTO module '.($enable ? 'enabled' : 'disabled'), ['module' => self::moduleName($key), 'user_id' => auth()->id()]);
    }

    /** @return array<string, bool> */
    private static function choices(): array
    {
        return app()->bound(self::CHOICES) ? app(self::CHOICES) : app(ModuleActivations::class)->all();
    }

    private static function keyOf(Manifest $manifest): string
    {
        $key = $manifest->toArray()['clarto']['key'] ?? null;

        return is_string($key) ? $key : (string) preg_replace('/^'.self::PREFIX.'/', '', $manifest->name());
    }

    /** @return array<string, string> */
    private static function packageModules(): array
    {
        if (self::$packageModules === null) {
            self::$packageModules = [];
            $inventory = app()->bound(ModuleInventory::class) ? app(ModuleInventory::class) : null;
            foreach ($inventory?->manifests ?? [] as $name => $manifest) {
                $composer = json_decode((string) @file_get_contents($manifest->path.'/composer.json'), true);
                if (is_array($composer) && is_string($composer['name'] ?? null)) {
                    self::$packageModules[$composer['name']] = $name;
                }
            }
        }

        return self::$packageModules;
    }
}
