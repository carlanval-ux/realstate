<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Valuations\ProcessValuation;
use App\Valuations\ValuationDelivery;
use App\Valuations\ValuationRequest;
use Illuminate\Console\Command;

/**
 * Retries what is still pending of the last week's valuation requests: delivery to the client (e-mail, WhatsApp —
 * e.g. once the WhatsApp line is linked) and GoHighLevel. Scheduled every 15 minutes; nothing is ever sent twice.
 */
final class RetryValuationDeliveries extends Command
{
    protected $signature = 'clarto:valoraciones-reenviar';

    protected $description = 'Retry pending valuation deliveries (e-mail, WhatsApp) and GoHighLevel syncs of the last 7 days';

    private const MAX_ATTEMPTS = 40;

    public function handle(): int
    {
        $queued = 0;
        ValuationRequest::query()->whereNotNull('current_run_id')->where('created_at', '>=', now()->subDays(7))->orderBy('id')
            ->each(function (ValuationRequest $request) use (&$queued): void {
                $state = ValuationDelivery::state($request);
                $tooMany = max((int) ($state['email']['intentos'] ?? 0), (int) ($state['whatsapp']['intentos'] ?? 0)) >= self::MAX_ATTEMPTS;
                if (! $tooMany && (ValuationDelivery::pending($request) || in_array($request->ghl_status, ['pendiente', 'error'], true))) {
                    ProcessValuation::dispatch($request->getKey());
                    $queued++;
                }
            });
        $this->line("Valoraciones en cola para reintento: {$queued}");
        if (filled(config('ghl.token'))) {
            $this->line('WhatsApp pedidos por GHL revisados: '.\App\Acquisition\GhlWhatsApp::retryQueued());
        }

        return self::SUCCESS;
    }
}
