<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Processes\ProcessDemo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Liberu\Foundation\Organizations\Models\Team;

/**
 * Loads the proposed areas and positions, the process map and the four fictitious «DEMOSTRACIÓN» procedures into a
 * company. Idempotent: what already exists is left untouched.
 *
 *   php artisan clarto:procesos-demo [--team=ID] [--sin-ejemplos]
 */
final class ProcessesDemo extends Command
{
    protected $signature = 'clarto:procesos-demo {--team= : Empresa (por defecto, la primera que no es personal)} {--sin-ejemplos : Solo estructura y mapa}';

    protected $description = 'Procesos y conocimiento: estructura propuesta, mapa de procesos y ejemplos de DEMOSTRACIÓN (idempotente)';

    public function handle(): int
    {
        $team = $this->option('team') ? Team::query()->find($this->option('team')) : Team::query()->where('personal_team', false)->orderBy('id')->first();
        if ($team === null) {
            $this->error('Empresa no encontrada.');

            return self::FAILURE;
        }
        $result = ProcessDemo::load($team, ! $this->option('sin-ejemplos'));
        $this->line(sprintf('Empresa %s: %d áreas, %d puestos y %d entradas del mapa nuevas; %d personas de demostración (desactivadas); procesos creados: %s.',
            $team->name, $result['areas'], $result['positions'], $result['map'], count($result['people']), implode(', ', array_keys($result['processes'])) ?: 'ninguno (ya existían)'));

        if (array_key_exists('clarto:asistente-reindexar', Artisan::all())) {
            $this->call('clarto:asistente-reindexar', ['--team' => $team->getKey()]);
        }

        return self::SUCCESS;
    }
}
