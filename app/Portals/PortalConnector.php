<?php

declare(strict_types=1);

namespace App\Portals;

use App\Models\PortalListing;
use Liberu\RealEstate\Properties\Models\Property;

/**
 * One external portal (pisos.com today). A new portal = a class implementing this + an entry in config/portales.php.
 * Callers go through Portals::run(), which applies the shared rules first (module on, collaborator permission);
 * the connector records each outcome on PortalListing (one row per property and portal).
 */
interface PortalConnector
{
    public static function make(): self;

    /** Why real sends cannot happen now (switch off, credentials missing), or null. A simulation never refuses. */
    public function refusal(): ?string;

    /** @return list<string> human-readable data still missing on the property for this portal */
    public function missing(Property $property): array;

    /** Creates the listing (or updates it if the portal already has it). */
    public function publish(Property $property): PortalListing;

    public function update(Property $property): PortalListing;

    public function withdraw(Property $property): PortalListing;

    public function refresh(Property $property): PortalListing;
}
