<?php

declare(strict_types=1);

namespace App\Collaborators;

use Illuminate\Support\Collection;
use Liberu\RealEstate\Properties\Models\Property;

/**
 * What a collaborator sees of an assigned property — the single presenter of the panel, the API and the export.
 * Authorised data of the collaboration and shared updates only: no exact address, owner, buyer/tenant data or notes.
 */
final class CollaboratorView
{
    /** @return array<string, mixed> */
    public static function row(Property $property, CollaboratorProperty $assignment): array
    {
        $property->loadMissing(['city', 'district']);
        $last = PropertyUpdate::query()->whereIn('id', self::updatesQuery($assignment)->select('id'))->max('shared_at');

        return [
            'id' => $property->getKey(),
            'referencia' => $property->reference(),
            'titulo' => (string) $property->title,
            'tipo' => UpdateMessage::typeLabel($property),
            'ubicacion' => UpdateMessage::place($property),
            'superficie_m2' => $property->area_sqft !== null ? round((float) $property->area_sqft, 2) : null,
            'habitaciones' => $property->bedrooms,
            'banos' => $property->bathrooms,
            'relacion' => $assignment->relation,
            'precio_autorizado' => $assignment->authorised_price !== null ? (float) $assignment->authorised_price : null,
            'disponibilidad' => $assignment->availability,
            'estado_comercial' => $assignment->commercial_status,
            'asignado' => $assignment->assigned_at?->toIso8601String(),
            'ultima_novedad' => $last ? \Illuminate\Support\Carbon::parse($last)->toIso8601String() : null,
        ];
    }

    /** @return Collection<int, PropertyUpdate> shared updates addressed to this collaborator, newest first */
    public static function updates(CollaboratorProperty $assignment): Collection
    {
        return self::updatesQuery($assignment)->orderByDesc('shared_at')->orderByDesc('id')->get();
    }

    /** @return array<string, mixed> */
    public static function update(PropertyUpdate $update): array
    {
        return [
            'id' => $update->getKey(),
            'tipo' => $update->kind,
            'titulo' => $update->title,
            'mensaje' => $update->body,
            'precio' => $update->price !== null ? (float) $update->price : null,
            'disponibilidad' => $update->availability,
            'visita' => $update->visit_at?->toIso8601String(),
            'estado_visita' => $update->visit_status,
            'resultado_visita' => $update->visit_result,
            'proximos_pasos' => $update->next_steps,
            'condiciones' => $update->conditions,
            'adjuntos' => collect($update->attachments ?? [])->values()->map(fn (array $a, int $i): array => ['nombre' => $a['name'] ?? '', 'url' => route('colaboradores.adjunto', ['update' => $update->getKey(), 'index' => $i])])->all(),
            'fecha' => $update->shared_at?->toIso8601String(),
        ];
    }

    private static function updatesQuery(CollaboratorProperty $assignment): \Illuminate\Database\Eloquent\Builder
    {
        return CollaboratorAccess::scopeUpdates(PropertyUpdate::query()->where('property_id', $assignment->property_id), $assignment->collaborator);
    }
}
