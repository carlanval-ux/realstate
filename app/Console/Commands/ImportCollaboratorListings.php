<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Collaborators\Import\CollaboratorImport;
use App\Collaborators\Import\CollaboratorImports;
use Illuminate\Console\Command;

/**
 * Runs collaborator imports (always through CollaboratorImports::run, which refuses without written authorization).
 *
 *   php artisan clarto:importar-colaboradores              «Importar ahora» requests (scheduler, every minute when any)
 *   php artisan clarto:importar-colaboradores --diario     daily imports (scheduler, 05:30)
 *   php artisan clarto:importar-colaboradores --colaborador=12
 */
final class ImportCollaboratorListings extends Command
{
    protected $signature = 'clarto:importar-colaboradores {--diario : Imports scheduled daily} {--colaborador= : Only this collaborator (id)}';

    protected $description = 'Import collaborator properties from their feed or website (only with written authorization)';

    public function handle(): int
    {
        if ($this->option('colaborador') !== null) {
            $import = CollaboratorImport::query()->where('collaborator_id', (int) $this->option('colaborador'))->first();
            if ($import === null) {
                $this->error(__('importacion.errors.not_configured'));

                return self::FAILURE;
            }
            $result = CollaboratorImports::run($import);
            $this->line($result['status'].' '.json_encode($result['counts']).($result['message'] !== '' ? "\n".$result['message'] : ''));

            return $result['ok'] ? self::SUCCESS : self::FAILURE;
        }
        $this->line('Importaciones ejecutadas: '.CollaboratorImports::runDue((bool) $this->option('diario')));

        return self::SUCCESS;
    }
}
