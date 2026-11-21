<?php

declare(strict_types=1);

namespace App\Processes\Recommendations;

use Illuminate\Database\Eloquent\Model;

/**
 * A documentation recommendation (modelo-datos.md §6). A dismissed one is never created again while its fingerprint
 * exists. ai_generated = its body was written by the AI: always shown as «Propuesta generada por IA».
 *
 * @property array<string, mixed>|null $evidence
 * @property list<array{process_id: int, code: string, title: string, score: float}>|null $similar
 */
final class Recommendation extends Model
{
    public const TYPES = ['mapa', 'puesto', 'area', 'sector', 'preguntas', 'dudas', 'practica_sugerida'];

    public const STATUSES = ['pendiente', 'aceptada', 'descartada', 'convertida'];

    protected $table = 'clarto_process_recommendations';

    protected $guarded = ['id'];

    protected $casts = ['evidence' => 'array', 'similar' => 'array', 'ai_generated' => 'boolean', 'decided_at' => 'datetime', 'priority' => 'integer'];
}
