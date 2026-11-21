<?php

declare(strict_types=1);

namespace App\Tarmio;

use Illuminate\Support\Facades\Log;

/** Default driver until TARMIO access is configured: sends nothing, logs and invents stable ids. */
final class SimulatedTarmioClient implements TarmioClient
{
    public function userIdByEmail(string $email): ?string
    {
        return 'sim-user-'.substr(sha1(strtolower($email)), 0, 12);
    }

    public function findTask(string $externalId): ?array
    {
        return null;
    }

    public function createTask(array $task): array
    {
        Log::info('TARMIO (simulado): crear tarea', ['external_id' => $task['external_id'], 'title' => $task['title']]);

        return ['id' => 'sim-'.substr(sha1($task['external_id']), 0, 20), 'ref' => null];
    }

    public function updateTask(string $id, array $task): array
    {
        Log::info('TARMIO (simulado): actualizar tarea', ['id' => $id, 'title' => $task['title']]);

        return ['id' => $id, 'ref' => null];
    }
}
