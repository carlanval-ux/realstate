<?php

declare(strict_types=1);

namespace App\Albaranes;

use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Liberu\Foundation\Organizations\Models\Team;

/**
 * Article created by hand to reuse a concept on albarán lines (there is no predefined catalogue). Picking it fills
 * the line's concept, price and VAT, which can still be changed. external_id = its product in Factúralo, once sent.
 */
final class BillingArticle extends Model
{
    protected $table = 'clarto_billing_articles';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['unit_price' => 'decimal:2', 'vat_pct' => 'decimal:2', 'requires_conformity' => 'boolean', 'active' => 'boolean'];
    }

    protected static function booted(): void
    {
        // Created from the panel (list or a line's «+»): it belongs to the current company.
        static::creating(function (self $article): void {
            $article->team_id ??= self::currentTeamId();
        });
    }

    public static function currentTeamId(): mixed
    {
        return Filament::getTenant()?->getKey() ?? auth()->user()?->current_team_id;
    }

    /** Company the article belongs to. */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @param Builder<self> $query */
    public function scopeForTeam(Builder $query, mixed $teamId): void
    {
        $query->where('team_id', $teamId);
    }

    public function label(): string
    {
        return ($this->reference ? $this->reference.' · ' : '').$this->name;
    }
}
