<?php

declare(strict_types=1);

namespace App\Rentals\Integration;

use App\Rentals\Models\Occupancy;
use App\Rentals\Models\OccupancyPerson;
use Carbon\CarbonImmutable;

/**
 * SIMULATED SES.HOSPEDAJES: checks that every traveller has the data RD 933/2021 (anexo I.A.3) asks for and answers
 * with a fake reference. Nothing leaves the server.
 */
final class SimulatedSesHospedajes implements TravellerRegistry
{
    /** Required for every traveller; document data only from 14 (younger ones: given by the adult with them). */
    public const REQUIRED = ['nombre', 'apellido1', 'sexo', 'nacionalidad', 'fecha_nacimiento', 'direccion', 'contacto'];

    public const DOCUMENT = ['tipo_documento', 'numero_documento'];

    public function submit(Occupancy $occupancy, iterable $travellers): array
    {
        $missing = [];
        $people = [];
        foreach ($travellers as $traveller) {
            /** @var OccupancyPerson $traveller */
            $people[] = [$traveller->name, $traveller->identity ?? []];
        }
        $on = $occupancy->planned_start;
        $age = fn (array $data): ?float => isset($data['fecha_nacimiento']) ? CarbonImmutable::parse($data['fecha_nacimiento'])->diffInYears($on) : null;
        $minors = collect($people)->contains(fn (array $p): bool => ($age($p[1]) ?? 99) < 18);
        foreach ($people as [$name, $data]) {
            $required = self::REQUIRED;
            if (($age($data) ?? 99) >= 14) {
                $required = [...$required, ...self::DOCUMENT];
                if (in_array($data['tipo_documento'] ?? null, ['DNI', 'NIE'], true)) {
                    $required[] = 'soporte_documento';
                }
                if (($data['tipo_documento'] ?? null) === 'DNI') {
                    $required[] = 'apellido2';
                }
            }
            if ($minors) {
                $required[] = 'parentesco';
            }
            foreach ($required as $field) {
                if (blank($data[$field] ?? null)) {
                    $missing[] = $name.': '.$field;
                }
            }
        }
        if ($people === []) {
            $missing[] = 'viajeros';
        }

        return $missing === []
            ? ['driver' => 'simulado', 'status' => 'aceptado_simulado', 'reference' => 'SES-SIM-'.$occupancy->getKey().'-'.now()->format('YmdHis'), 'missing' => []]
            : ['driver' => 'simulado', 'status' => 'incompleto', 'reference' => null, 'missing' => $missing];
    }

    public function notify(Occupancy $occupancy, string $event): array
    {
        return ['driver' => 'simulado', 'status' => 'aceptado_simulado', 'reference' => 'SES-SIM-'.strtoupper($event).'-'.$occupancy->getKey()];
    }
}
