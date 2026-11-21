<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Acquisition\GhlClient;
use App\Acquisition\GhlHandoff;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * L-8: GHL does not document retries of its workflow webhooks, so once a day the opportunities won in the acquisition
 * pipeline during the last 7 days that have no Liberu file yet are processed (GhlHandoff is idempotent per opportunity).
 */
final class ReconcileGhlWonOpportunities extends Command
{
    protected $signature = 'clarto:ghl-ganadas {--dias=7}';

    protected $description = 'Open the Liberu file of acquisition opportunities won in GHL whose webhook never arrived';

    public function handle(): int
    {
        if (blank(config('ghl.token')) || blank(config('ghl.location_id')) || blank(config('ghl.pipeline_id'))) {
            $this->line('GHL sin configurar.');

            return self::SUCCESS;
        }
        $since = now()->subDays(max(1, (int) $this->option('dias')));
        $done = 0;
        $page = 1;
        do {
            $answer = (new GhlClient)->get('/opportunities/search?'.http_build_query([
                'location_id' => config('ghl.location_id'), 'pipeline_id' => config('ghl.pipeline_id'), 'status' => 'won', 'limit' => 100, 'page' => $page,
            ]));
            $opportunities = (array) ($answer['opportunities'] ?? []);
            foreach ($opportunities as $o) {
                $changed = $o['lastStatusChangeAt'] ?? $o['updatedAt'] ?? null;
                $handled = GhlHandoff::query()->where('opportunity_id', (string) $o['id'])->whereIn('status', ['procesado', 'pendiente'])->exists();
                if ($handled || ($changed !== null && Carbon::parse((string) $changed)->lt($since))) {
                    continue;
                }
                try {
                    $this->line($o['id'].': '.GhlHandoff::process((string) $o['id'])['status']);
                    $done++;
                } catch (Throwable $e) {
                    report($e);
                    $this->warn($o['id'].': '.$e->getMessage());
                }
            }
            $page++;
        } while (count($opportunities) === 100 && $page <= 20);
        $this->line("Oportunidades ganadas procesadas: {$done}");

        return self::SUCCESS;
    }
}
