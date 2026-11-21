<?php

declare(strict_types=1);

namespace App\Valuations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One short public link to a valuation report (ReportLinks). Holds the SHA-256 of the code, never the code itself.
 */
final class ReportLink extends Model
{
    protected $table = 'clarto_valuation_report_links';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'revoked_at' => 'datetime', 'ghl_at' => 'datetime', 'last_opened_at' => 'datetime'];
    }

    /** @return BelongsTo<ValuationRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(ValuationRequest::class, 'valuation_request_id');
    }

    /** @return BelongsTo<ValuationRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(ValuationRun::class, 'valuation_run_id');
    }

    /** activo | caducado | revocado */
    public function state(): string
    {
        return match (true) {
            $this->revoked_at !== null => 'revocado',
            $this->expires_at->isPast() => 'caducado',
            default => 'activo',
        };
    }
}
