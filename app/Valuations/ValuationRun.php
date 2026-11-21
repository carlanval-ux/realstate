<?php

declare(strict_types=1);

namespace App\Valuations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One calculation of a request. Never updated after the report is attached: a recalculation adds a new run. */
final class ValuationRun extends Model
{
    protected $table = 'clarto_valuation_runs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['result' => 'array', 'provider_errors' => 'array', 'simulated' => 'boolean'];
    }

    /** @return BelongsTo<ValuationRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(ValuationRequest::class, 'valuation_request_id');
    }
}
