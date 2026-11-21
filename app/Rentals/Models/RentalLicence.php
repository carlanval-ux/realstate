<?php

declare(strict_types=1);

namespace App\Rentals\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
/** Licence or registration number (VUT Comunitat Valenciana, Registro Único de Arrendamientos, SES establishment…). */
final class RentalLicence extends Model
{
    use SoftDeletes;

    protected $table = 'clarto_rent_licences';

    protected $guarded = ['id'];

    public function team(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\Liberu\Foundation\Organizations\Models\Team::class);
    }

    protected function casts(): array
    {
        return ['valid_from' => 'date', 'valid_until' => 'date'];
    }

    public const TYPES = ['vut_cv', 'registro_unico', 'ses_establecimiento', 'cedula_habitabilidad', 'certificado_energetico', 'informe_compatibilidad'];

    public function property(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\Liberu\RealEstate\Properties\Models\Property::class);
    }

    public function isValidOn(\DateTimeInterface $day): bool
    {
        return $this->status === 'vigente'
            && ($this->valid_from === null || $this->valid_from->lte($day))
            && ($this->valid_until === null || $this->valid_until->gte($day));
    }
}
