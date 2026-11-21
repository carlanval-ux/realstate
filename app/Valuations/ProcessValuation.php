<?php

declare(strict_types=1);

namespace App\Valuations;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * After the API has answered: AI summary (and PDF again), GoHighLevel, and delivery to the client (e-mail + WhatsApp).
 * Every step is idempotent, so the job can run again (retry, scheduled «clarto:valoraciones-reenviar», panel).
 */
final class ProcessValuation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public readonly int $valuationRequestId, public readonly bool $resend = false) {}

    public function handle(ValuationEngine $engine): void
    {
        $request = ValuationRequest::query()->find($this->valuationRequestId);
        $run = $request?->currentRun;
        if ($request === null || $run === null) {
            return;
        }
        try {
            $engine->improveSummary($run);   // before sending, so the client gets the final PDF
        } catch (Throwable $e) {
            report($e);
        }
        if ($request->ghl_status !== 'sincronizada' || $request->ghl_synced_at < $run->updated_at) {
            (new GhlValuationSync)->sync($request->refresh());
        }
        (new ValuationDelivery)->deliver($request->refresh(), $this->resend);
    }
}
