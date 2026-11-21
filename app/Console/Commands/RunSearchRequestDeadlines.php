<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\SearchRequests\SearchRequests;
use Illuminate\Console\Command;

/** Hourly: expired requests closed («Caducada · oportunidad perdida»), owners warned, reminders to collaborators. */
final class RunSearchRequestDeadlines extends Command
{
    protected $signature = 'clarto:solicitudes-plazos';

    protected $description = 'Property requests: deadlines, reminders and notices to the owner';

    public function handle(): int
    {
        $done = SearchRequests::runDeadlines();
        $this->line(sprintf('caducadas: %d · avisos al responsable: %d · recordatorios: %d', $done['caducadas'], $done['avisos'], $done['recordatorios']));

        return self::SUCCESS;
    }
}
