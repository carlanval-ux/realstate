<?php

declare(strict_types=1);

namespace App\Processes;

/**
 * Templates by type of process (diseno.md §5): format, category, guide step titles and suggested indicators. The guide
 * questions of each section are help texts of the editor (lang procesos.plantillas.*), not content.
 */
final class ProcessTemplates
{
    /** key => [kind, category|null, guide steps, suggested kpis] */
    private const TEMPLATES = [
        'instruccion_breve' => ['breve', null, ['Preparar', 'Hacer', 'Comprobar'], []],
        'comercial' => ['completo', 'comercial', ['Recibir el contacto', 'Cualificar', 'Contactar', 'Proponer', 'Cerrar (ganado o perdido)', 'Traspasar al siguiente proceso'],
            ['Tiempo hasta el primer contacto', 'Conversión por etapa', 'Motivos de pérdida']],
        'operativo_inmueble' => ['completo', 'operativo', ['Preparar (datos y documentos)', 'Coordinar con el cliente o el proveedor', 'Ejecutar en el inmueble', 'Acta o evidencia', 'Cerrar el expediente'],
            ['Cumplimiento del plazo', 'Incidencias reabiertas']],
        'administrativo' => ['completo', 'administrativo', ['Reunir documentación', 'Verificar', 'Presentar o registrar', 'Archivar el justificante', 'Avisar al cliente'],
            ['Trámites en plazo', 'Rechazos o requerimientos']],
        'financiero' => ['completo', 'financiero', ['Calcular', 'Verificar (con doble control si supera un umbral)', 'Emitir o pagar', 'Conciliar', 'Archivar'],
            ['Días de cobro', 'Diferencias de conciliación']],
        'soporte_personas' => ['completo', 'soporte', ['Solicitud', 'Preparar accesos o materiales', 'Ejecutar', 'Verificar', 'Registrar'],
            ['Accesos activos sin puesto', 'Plazo de alta o baja']],
        'servicio_profesional' => ['completo', 'operativo', ['Alta y encargo profesional', 'Recopilar documentación', 'Análisis o tramitación', 'Revisión por un segundo profesional', 'Entrega al cliente', 'Facturación'],
            ['Plazo de entrega', 'Revisiones devueltas']],
    ];

    /** @return array<string, string> key => translated name */
    public static function options(): array
    {
        return array_combine(array_keys(self::TEMPLATES), array_map(fn (string $k): string => __("procesos.plantillas.{$k}"), array_keys(self::TEMPLATES)));
    }

    /** @return array<string, mixed> version and process attributes to pre-fill */
    public static function defaults(string $key): array
    {
        [$kind, $category, $steps, $kpis] = self::TEMPLATES[$key] ?? self::TEMPLATES['instruccion_breve'];

        return array_filter([
            'kind' => $kind,
            'category' => $category,
            'steps' => array_map(fn (string $title): array => [
                'key' => Processes::newKey(), 'title' => $title, 'actor_position_id' => null, 'actor_label' => null, 'action' => null,
                'done_when' => null, 'evidence' => null, 'deadline' => null, 'work_link' => null, 'tarmio_kind' => null, 'decision_key' => null, 'blocks' => [],
            ], $steps),
            'kpis' => $kpis === [] ? null : array_map(fn (string $name): array => ['name' => $name, 'target' => null, 'frequency' => null, 'source' => null], $kpis),
        ], fn ($v) => $v !== null);
    }
}
