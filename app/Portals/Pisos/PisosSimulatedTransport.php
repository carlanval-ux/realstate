<?php

declare(strict_types=1);

namespace App\Portals\Pisos;

use Illuminate\Support\Facades\Cache;

/**
 * Local stand-in for pisos.com with the same contract, used while real sends are off (PISOS_DRIVER=simulado).
 * It validates like a portal would (price, description, zone, contact), assigns an id, keeps the state in the
 * cache and reports listings as active once accepted. Nothing leaves the server.
 */
final class PisosSimulatedTransport implements PisosTransport
{
    public function create(array $inmueble): PisosResult
    {
        if ($error = $this->validate($inmueble)) {
            return new PisosResult('rejected', message: $error, errorCode: 'SIM-VALIDACION', httpStatus: 400);
        }
        $id = 'SIM-'.substr(sha1((string) ($inmueble['Descripcion'] ?? '').microtime()), 0, 10);
        Cache::forever("pisos-sim:{$id}", $inmueble + ['ID' => $id, 'Estado' => 0]);

        return new PisosResult('ok', $id, 0, 'OK (simulado)', httpStatus: 200);
    }

    public function update(array $inmueble): PisosResult
    {
        $id = (string) ($inmueble['ID'] ?? '');
        if (! Cache::has("pisos-sim:{$id}")) {
            return new PisosResult('rejected', $id, message: 'Inmueble no encontrado (simulado)', errorCode: 'SIM-404', httpStatus: 404);
        }
        if ($error = $this->validate($inmueble)) {
            return new PisosResult('rejected', $id, message: $error, errorCode: 'SIM-VALIDACION', httpStatus: 400);
        }
        Cache::forever("pisos-sim:{$id}", $inmueble + ['Estado' => 0]);

        return new PisosResult('ok', $id, 0, 'OK (simulado)', httpStatus: 200);
    }

    public function deactivate(string $id): PisosResult
    {
        $data = Cache::get("pisos-sim:{$id}");
        if ($data === null) {
            return new PisosResult('rejected', $id, message: 'Inmueble no encontrado (simulado)', errorCode: 'SIM-404', httpStatus: 404);
        }
        Cache::forever("pisos-sim:{$id}", ['Estado' => -2] + $data);

        return new PisosResult('ok', $id, -2, 'Desactivado (simulado)', httpStatus: 200);
    }

    public function get(string $id): PisosResult
    {
        $data = Cache::get("pisos-sim:{$id}");

        return $data === null
            ? new PisosResult('rejected', $id, message: 'Inmueble no encontrado (simulado)', errorCode: 'SIM-404', httpStatus: 404)
            : new PisosResult('ok', $id, (int) $data['Estado'], httpStatus: 200, raw: $data);
    }

    public function savePhotos(string $id, string $reference, array $photos): PisosResult
    {
        return new PisosResult('ok', $id, message: count($photos).' fotos (simulado)', httpStatus: 200);
    }

    public function findByReference(string $reference): ?string
    {
        return null;
    }

    /** @param array<string, mixed> $i */
    private function validate(array $i): ?string
    {
        return match (true) {
            blank($i['Localizacion']['CodigoZonaGeo'] ?? null) => 'Falta el código de zona (simulado)',
            mb_strlen((string) ($i['Descripcion'] ?? '')) < 30 => 'La descripción es demasiado corta (simulado)',
            (float) ($i['Precios'][0]['Valor'] ?? 0) <= 0 => 'Falta el precio (simulado)',
            blank($i['Usuario']['Telefono'] ?? null) => 'Falta el teléfono de contacto (simulado)',
            default => null,
        };
    }
}
