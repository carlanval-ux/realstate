<?php

declare(strict_types=1);

namespace App\Albaranes;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of an albarán: a catalogue service (service_code) or a free line; amount before taxes. */
final class AlbaranLine extends Model
{
    protected $table = 'clarto_albaran_lines';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2', 'unit_price' => 'decimal:2', 'discount_pct' => 'decimal:2', 'amount' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $l): void {
            $l->amount = self::amountOf((float) $l->quantity, (float) $l->unit_price, (float) $l->discount_pct);
        });
        static::saved(fn (self $l) => $l->albaran?->recalculate());
        static::deleted(fn (self $l) => $l->albaran?->recalculate());
    }

    public static function amountOf(float $quantity, float $unitPrice, float $discountPct): float
    {
        return round($quantity * $unitPrice * (1 - min(max($discountPct, 0), 100) / 100), 2);
    }

    public function albaran(): BelongsTo
    {
        return $this->belongsTo(Albaran::class);
    }
}
