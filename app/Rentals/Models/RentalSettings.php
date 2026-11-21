<?php

declare(strict_types=1);

namespace App\Rentals\Models;

use Illuminate\Database\Eloquent\Model;

/** Per-company channels, templates and rules (see RentalSettings::DEFAULTS). */
final class RentalSettings extends Model
{

    protected $table = 'clarto_rent_settings';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['data' => 'array'];
    }

    /** @var array<string, mixed> */
    public const DEFAULTS = [
        'canales' => ['directo', 'airbnb', 'booking', 'vrbo'],
        'plantillas' => [
            'contrato_habitual' => 'ficticio/contrato-vivienda-habitual',
            'contrato_temporal' => 'ficticio/contrato-temporada',
            'contrato_habitacion' => 'ficticio/contrato-habitacion',
            'anexo' => 'ficticio/anexo',
            'inventario' => 'ficticio/inventario',
            'entrega_llaves' => 'ficticio/acta-entrega-llaves',
            'acta_salida' => 'ficticio/acta-salida',
            'preaviso' => 'ficticio/preaviso',
        ],
        'comision_vacacional_pct' => 20,
        'limpieza_vacacional' => 60,
        'region' => 'CV',
    ];

    /** @return array<string, mixed> */
    public static function forTeam(int|string|null $teamId): array
    {
        $stored = $teamId === null ? null : self::query()->where('team_id', $teamId)->value('data');

        return array_replace(self::DEFAULTS, is_array($stored) ? $stored : (json_decode((string) $stored, true) ?: []));
    }
}
