<?php

declare(strict_types=1);

namespace App\Rentals\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
/** Money owed or paid for an occupancy (rent, utilities, nightly rate, cleaning, CLARTO service, owner settlement). */
final class RentalCharge extends Model
{
    use SoftDeletes;

    protected $table = 'clarto_rent_charges';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'due_on' => 'date', 'paid_on' => 'date', 'data' => 'array'];
    }

    public const CONCEPTS = ['renta', 'suministro', 'tarifa', 'limpieza', 'servicio_clarto', 'reparacion', 'penalizacion', 'devolucion', 'liquidacion_propietario'];

    public function occupancy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Occupancy::class);
    }
}
