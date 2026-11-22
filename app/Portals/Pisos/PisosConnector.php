<?php

declare(strict_types=1);

namespace App\Portals\Pisos;

use App\Models\PortalListing;
use App\Modules\ClartoModules;
use App\Portals\PortalConnector;
use App\Portals\Portals;
use Liberu\RealEstate\Properties\Models\Property;

/**
 * Publish / update / withdraw / check a property on pisos.com and record the outcome on PortalListing.
 *
 * - "enviado": sent without confirmation (timeout); "aceptado": validated, id returned; "publicado": the portal
 *   reports it active (Estado 0); "rechazado": validation error, reason kept; "retirado": deactivated.
 * - A repeated "publish" never creates a duplicate: with a portal id it becomes an update, and after an
 *   unconfirmed send the listing is looked up by our reference before trying again.
 * - Withdrawing only deactivates the portal listing; the property stays as it is in the panel.
 */
final class PisosConnector implements PortalConnector
{
    public const PORTAL = 'pisos';

    public function __construct(private readonly PisosTransport $transport, private readonly string $driver) {}

    public static function make(): self
    {
        $driver = (string) config('pisos.driver', 'simulado');

        return new self($driver === 'api' ? new PisosApiTransport : new PisosSimulatedTransport, $driver);
    }

    /** Real sends need the switch on; the simulation always works. */
    public function refusal(): ?string
    {
        if ($this->driver !== 'api') {
            return null;
        }

        return match (true) {
            ! config('pisos.enabled') => 'Los envíos reales a pisos.com están desactivados (PISOS_ENABLED).',
            blank(config('pisos.api_key')) || blank(config('pisos.user_id')) => 'Faltan las credenciales de pisos.com en el servidor (PISOS_API_KEY, PISOS_USER_ID).',
            default => null,
        };
    }

    public function missing(Property $property): array
    {
        return PisosPayload::missing($property);
    }

    public function publish(Property $property): PortalListing
    {
        $listing = PortalListing::for($property, self::PORTAL);
        if ($this->blocked($listing, $property, 'alta')) {
            return $listing;
        }

        if (blank($listing->external_id) && $listing->status === PortalListing::SENT) {
            // A previous send was not confirmed: link it instead of creating a second listing.
            if ($found = $this->transport->findByReference($property->reference())) {
                $listing->update(['external_id' => $found]);
            }
        }

        if (filled($listing->external_id)) {
            return $this->update($property);
        }

        $payload = PisosPayload::build($property);
        $result = $this->transport->create($payload);
        $this->record($listing, 'alta', $payload, $result);

        if ($result->ok() && filled($result->id)) {
            $this->sendPhotos($listing, $property);
        }

        return $listing->refresh();
    }

    public function update(Property $property): PortalListing
    {
        $listing = PortalListing::for($property, self::PORTAL);
        if ($this->blocked($listing, $property, 'actualizar')) {
            return $listing;
        }
        if (blank($listing->external_id)) {
            return $this->fail($listing, 'actualizar', 'El inmueble no tiene identificador de pisos.com: publíquelo primero.');
        }

        $payload = PisosPayload::build($property, $listing->external_id);
        $result = $this->transport->update($payload);
        $this->record($listing, 'actualizar', $payload, $result);
        if ($result->ok()) {
            $this->sendPhotos($listing, $property);
        }

        return $listing->refresh();
    }

    public function withdraw(Property $property): PortalListing
    {
        $listing = PortalListing::for($property, self::PORTAL);
        if (($refusal = $this->refusal()) !== null) {
            return $this->fail($listing, 'retirar', $refusal);
        }
        if (blank($listing->external_id)) {
            return $this->fail($listing, 'retirar', 'No hay anuncio en pisos.com que retirar.');
        }

        $result = $this->transport->deactivate($listing->external_id);
        $this->record($listing, 'retirar', null, $result, $result->ok() ? PortalListing::WITHDRAWN : null);

        return $listing->refresh();
    }

    public function refresh(Property $property): PortalListing
    {
        $listing = PortalListing::for($property, self::PORTAL);
        if (($refusal = $this->refusal()) !== null || blank($listing->external_id)) {
            return $this->fail($listing, 'consultar', $refusal ?? 'No hay anuncio en pisos.com que consultar.');
        }

        $result = $this->transport->get($listing->external_id);
        $status = match (true) {
            ! $result->ok() => null,
            $result->estado === 0 => PortalListing::PUBLISHED,
            $result->estado === -2 || $result->estado === -1 => PortalListing::WITHDRAWN,
            $result->estado === -4 => PortalListing::REJECTED,
            default => PortalListing::ACCEPTED,
        };
        $this->record($listing, 'consultar', null, $result, $status, $result->estado === -4 ? 'pisos.com: pendiente de completar' : null);

        return $listing->refresh();
    }

    /** Publishing and updating are new operations: refused while the pisos.com module is off (withdraw/check stay). */
    private function blocked(PortalListing $listing, Property $property, string $action): bool
    {
        // Collaborator permission checked here too, so a direct call can never send a collaborator's property.
        $refusal = ClartoModules::refusal(ClartoModules::PISOS) ?? Portals::collaboratorRefusal($property) ?? $this->refusal();
        $missing = PisosPayload::missing($property);
        if ($refusal === null && $missing === []) {
            return false;
        }
        $this->fail($listing, $action, $refusal ?? 'Faltan datos obligatorios: '.implode('; ', $missing).'.');

        return true;
    }

    private function sendPhotos(PortalListing $listing, Property $property): void
    {
        $result = $this->transport->savePhotos((string) $listing->external_id, $property->reference(), PisosPayload::photos($property));
        if (! $result->ok()) {
            $listing->update(['last_error' => 'Fotos: '.($result->message ?? 'error')]);
        }
    }

    /** @param array<string, mixed>|null $payload */
    private function record(PortalListing $listing, string $action, ?array $payload, PisosResult $result, ?string $status = null, ?string $note = null): void
    {
        $status ??= match ($result->outcome) {
            // Accepted until "Consultar estado" confirms it is active: the portal may review it again after a change.
            'ok' => PortalListing::ACCEPTED,
            'rejected' => PortalListing::REJECTED,
            'unconfirmed' => $action === 'alta' ? PortalListing::SENT : $listing->status,
            default => PortalListing::ERROR,
        };

        $listing->update([
            'external_id' => $result->ok() && filled($result->id) ? $result->id : $listing->external_id,
            'status' => $status,
            'last_action' => $action,
            'driver' => $this->driver,
            'last_error' => $result->ok() ? $note : trim(($result->errorCode ? "[{$result->errorCode}] " : '').($result->message ?? 'Error')),
            'payload_hash' => $payload ? hash('sha256', (string) json_encode($payload)) : $listing->payload_hash,
            'last_response' => $result->raw ?: ['outcome' => $result->outcome, 'http' => $result->httpStatus, 'message' => $result->message],
            'last_attempt_at' => now(),
            'last_synced_at' => $result->ok() ? now() : $listing->last_synced_at,
        ]);
    }

    private function fail(PortalListing $listing, string $action, string $reason): PortalListing
    {
        $listing->update(['last_action' => $action, 'last_error' => $reason, 'last_attempt_at' => now(), 'driver' => $this->driver]);

        return $listing->refresh();
    }
}
