<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Valuations\Retention;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Retention of valuation requests (App\Valuations\Retention, docs/clarto/conservacion-valoraciones.md). Scheduled
 * daily at 03:40; --dry-run only counts. The log and the output hold counts only, never personal data.
 *
 *   php artisan clarto:valoraciones-conservacion --dry-run
 */
final class ValuationRetention extends Command
{
    protected $signature = 'clarto:valoraciones-conservacion {--dry-run : Solo cuenta lo que se haría; no cambia ni borra nada}';

    protected $description = 'Anonymise valuation requests 24 months after the last contact and expire commercial consent after 12 months';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $counts = Retention::run($dryRun);
        Log::info('Valoraciones: conservación de datos'.($dryRun ? ' (simulacro)' : ''), $counts);
        foreach ($counts as $what => $count) {
            $this->line(str_pad($what, 30).$count);
        }
        if ($dryRun) {
            $this->line('Simulacro: no se ha cambiado nada.');
        }

        return self::SUCCESS;
    }
}
