<?php

declare(strict_types=1);

namespace App\Portals\Pisos;

/** Calls to pisos.com (same shape as the official connectors' RightmoveTransport/ZooplaTransport). */
interface PisosTransport
{
    /** @param array<string, mixed> $inmueble */
    public function create(array $inmueble): PisosResult;

    /** @param array<string, mixed> $inmueble  must carry ID */
    public function update(array $inmueble): PisosResult;

    public function deactivate(string $id): PisosResult;

    public function get(string $id): PisosResult;

    /** @param list<array{url: string, orden: int}> $photos */
    public function savePhotos(string $id, string $reference, array $photos): PisosResult;

    /** Finds a listing we sent earlier (e.g. after a timeout) by our reference, to avoid a duplicate. */
    public function findByReference(string $reference): ?string;
}
