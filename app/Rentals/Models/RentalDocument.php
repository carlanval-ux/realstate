<?php

declare(strict_types=1);

namespace App\Rentals\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
/** Contract, annex, inventory or record prepared for «Formularios» (values encrypted: they hold identity data). */
final class RentalDocument extends Model
{
    use SoftDeletes;

    protected $table = 'clarto_rent_documents';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['values' => 'encrypted:array'];
    }

    public const KINDS = ['contrato', 'anexo', 'inventario', 'entrega_llaves', 'preaviso', 'acta_salida'];

    public function occupancy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Occupancy::class);
    }
}
