<?php

declare(strict_types=1);

namespace App\Albaranes;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of an albarán, free text: concept (description), quantity, unit price, discount % and VAT %.
 * Optionally filled from a hand-made article (article_id; service_code keeps its reference). amount = before taxes.
 */
final class AlbaranLine extends Model
{
    /** VAT rates offered on lines and articles (Spain: general, reduced, super-reduced, exempt). */
    public const VAT_RATES = [21, 10, 4, 0];

    protected $table = 'clarto_albaran_lines';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2', 'unit_price' => 'decimal:2', 'discount_pct' => 'decimal:2', 'amount' => 'decimal:2',
            'vat_pct' => 'decimal:2', 'requires_conformity' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $l): void {
            $l->amount = self::amountOf((float) $l->quantity, (float) $l->unit_price, (float) $l->discount_pct);
            // The article decides whether the client must sign; a free line never asks for it.
            $article = $l->article_id ? BillingArticle::query()->find($l->article_id) : null;
            $l->requires_conformity = (bool) $article?->requires_conformity;
            $l->service_code = $article?->reference;
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

    public function article(): BelongsTo
    {
        return $this->belongsTo(BillingArticle::class, 'article_id');
    }

    public function vatAmount(): float
    {
        return round((float) $this->amount * (float) $this->vat_pct / 100, 2);
    }
}
