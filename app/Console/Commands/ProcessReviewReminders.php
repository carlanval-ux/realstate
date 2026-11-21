<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Processes\Models\Process;
use App\Processes\Models\ProcessSettings;
use App\Processes\ProcessLinks;
use App\Tarmio\TarmioTask;
use Illuminate\Console\Command;
use Throwable;

/**
 * Daily: a TARMIO task «Revisar procedimiento CODE» to the owner 30 days before next_review_on (no personal data in
 * the title). Idempotent through TarmioTask::sync; skipped for companies with review_tasks_in_tarmio off.
 */
final class ProcessReviewReminders extends Command
{
    protected $signature = 'clarto:procesos-revisiones';

    protected $description = 'Procesos y conocimiento: tareas de TARMIO para las revisiones periódicas que vencen en 30 días';

    public function handle(): int
    {
        $sent = 0;
        Process::query()->where('status', 'publicado')->whereNotNull('next_review_on')->whereDate('next_review_on', '<=', now()->addDays(30))
            ->with('owner')->orderBy('id')->each(function (Process $process) use (&$sent): void {
                if (! ProcessSettings::forTeam($process->team_id)->review_tasks_in_tarmio || $process->ownerMissing()) {
                    return;
                }
                try {
                    TarmioTask::sync($process, 'revision_proceso', 'Revisar procedimiento '.$process->code, $process->owner->email, $process->next_review_on, ProcessLinks::process($process));
                    $sent++;
                } catch (Throwable $e) {
                    report($e);
                }
            });
        $this->line("Tareas de revisión sincronizadas: {$sent}");

        return self::SUCCESS;
    }
}
