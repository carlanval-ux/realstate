<?php

declare(strict_types=1);

namespace App\Portals;

use App\Collaborators\Import\CollaboratorImport;
use App\Filament\Collaborators\PropertyOrigin;
use App\Models\PortalListing;
use App\Modules\ClartoModules;
use Illuminate\Support\Collection;
use Liberu\RealEstate\Properties\Models\Property;
use RuntimeException;

/**
 * Registry of external portals (config/portales.php) and the single entry point for sending: run() refuses a portal
 * whose CLARTO module is off and a collaborator's property unless that collaborator allows it («Puede difundirse en
 * portales externos», which needs the written authorization). Withdrawing and checking are never refused. Only what
 * staff explicitly choose is sent: there is no scheduled send.
 */
final class Portals
{
    /** @return array<string, string> key => display name, in config order */
    public static function names(): array
    {
        return collect((array) config('portales'))->map(fn (array $p): string => (string) $p['nombre'])->all();
    }

    public static function name(string $key): string
    {
        return self::names()[$key] ?? $key;
    }

    public static function connector(string $key): PortalConnector
    {
        $class = config("portales.{$key}.conector");
        throw_unless(is_string($class) && is_subclass_of($class, PortalConnector::class), new RuntimeException("Portal desconocido: {$key}"));

        return $class::make();
    }

    public static function enabled(string $key): bool
    {
        $module = config("portales.{$key}.modulo");

        return $module === null || ClartoModules::enabled((string) $module);
    }

    /** Why this property may not be SENT to this portal (module off, collaborator not allowing it), or null. */
    public static function refusal(string $key, Property $property): ?string
    {
        $module = config("portales.{$key}.modulo");
        if ($module !== null && ($refusal = ClartoModules::refusal((string) $module)) !== null) {
            return $refusal;
        }

        return self::collaboratorRefusal($property);
    }

    /** White label + the collaborator's permission: their property only goes out if their record allows it. */
    public static function collaboratorRefusal(Property $property): ?string
    {
        $origin = PropertyOrigin::of($property);
        if ($origin === null) {
            return null;
        }
        $settings = CollaboratorImport::query()->where('collaborator_id', $origin['collaborator']->getKey())->first();

        return match (true) {
            ! ($settings?->portals_allowed ?? false) => __('portales.refusals.collaborator', ['name' => $origin['collaborator']->name]),
            ! ($settings?->isAuthorized() ?? false) => __('portales.refusals.authorization', ['name' => $origin['collaborator']->name]),
            default => null,
        };
    }

    /**
     * @param  'publish'|'update'|'withdraw'|'refresh'  $operation
     * @return array{ok: bool, message: ?string, listing: ?PortalListing}
     */
    public static function run(string $key, string $operation, Property $property): array
    {
        if (in_array($operation, ['publish', 'update'], true) && ($refusal = self::refusal($key, $property)) !== null) {
            return ['ok' => false, 'message' => $refusal, 'listing' => null];
        }
        $start = now()->startOfSecond();
        $listing = self::connector($key)->{$operation}($property);
        // Success = the portal confirmed it during this call (a refusal or failure keeps the previous status).
        $ok = $listing->last_synced_at !== null && $listing->last_synced_at->gte($start) && ! in_array($listing->status, [PortalListing::ERROR, PortalListing::REJECTED], true);

        return ['ok' => $ok, 'message' => $ok ? null : ($listing->last_error ?: __('portales.unconfirmed')), 'listing' => $listing];
    }

    /**
     * Bulk «Enviar a…» / «Retirar de…» over the properties staff selected.
     *
     * @param  Collection<int, Property>  $properties
     * @return array{ok: int, failed: array<string, string>} reference => reason
     */
    public static function runMany(string $key, string $operation, Collection $properties): array
    {
        $ok = 0;
        $failed = [];
        foreach ($properties as $property) {
            if ($operation === 'withdraw' && blank(PortalListing::query()->where('property_id', $property->getKey())->where('portal', $key)->value('external_id'))) {
                continue;   // never sent there: nothing to withdraw
            }
            $result = rescue(fn () => self::run($key, $operation, $property), fn ($e) => ['ok' => false, 'message' => $e->getMessage(), 'listing' => null]);
            $result['ok'] ? $ok++ : $failed[$property->reference()] = (string) ($result['message'] ?? 'Error');
        }

        return ['ok' => $ok, 'failed' => $failed];
    }
}
