<?php

declare(strict_types=1);

namespace App\Tarmio;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * TARMIO's Supabase REST API (PostgREST), signed in as the integration user: row-level security applies as that
 * user, so it only reaches the CLARTO company it belongs to. TARMIO has no API tokens: password grant → JWT.
 */
final class HttpTarmioClient implements TarmioClient
{
    public function userIdByEmail(string $email): ?string
    {
        $email = strtolower(trim($email));

        return Cache::remember('tarmio-user:'.sha1($email), 3600, function () use ($email): ?string {
            $rows = $this->rest()->post('/rest/v1/rpc/lookup_user_by_email', ['_email' => $email])->throw()->json();

            return $rows[0]['user_id'] ?? null;
        });
    }

    public function findTask(string $externalId): ?array
    {
        $rows = $this->rest()->get('/rest/v1/tasks', [
            'select' => 'id,public_ref',
            'organization_id' => 'eq.'.config('tarmio.organization_id'),
            'source_provider' => 'eq.liberu',
            'source_external_id' => 'eq.'.$externalId,
            'limit' => 1,
        ])->throw()->json();

        return isset($rows[0]['id']) ? ['id' => $rows[0]['id'], 'ref' => $rows[0]['public_ref'] ?? null] : null;
    }

    public function createTask(array $task): array
    {
        $rows = $this->rest()->withHeaders(['Prefer' => 'return=representation'])->post('/rest/v1/tasks', array_filter([
            'organization_id' => config('tarmio.organization_id'),
            'project_id' => config('tarmio.project_id') ?: null,
            'created_by' => $this->session()['user_id'],
            'source_provider' => 'liberu',
            'source_external_id' => $task['external_id'],
        ]) + $this->fields($task))->throw()->json();

        return $this->first($rows);
    }

    public function updateTask(string $id, array $task): array
    {
        // Status is never sent: what the team does with the task in TARMIO stays in TARMIO.
        $rows = $this->rest()->withHeaders(['Prefer' => 'return=representation'])
            ->patch('/rest/v1/tasks?id=eq.'.rawurlencode($id), $this->fields($task))->throw()->json();

        return $this->first($rows);
    }

    /** @return array<string, ?string> */
    private function fields(array $task): array
    {
        return ['title' => $task['title'], 'description' => $task['description'], 'due_date' => $task['due_date'], 'assignee_id' => $task['assignee_id']];
    }

    /** @return array{id: string, ref: ?string} */
    private function first(mixed $rows): array
    {
        if (! isset($rows[0]['id'])) {
            throw new RuntimeException('TARMIO no devolvió la tarea (no existe o el usuario de integración no tiene permisos)');
        }

        return ['id' => $rows[0]['id'], 'ref' => $rows[0]['public_ref'] ?? null];
    }

    private function rest(): PendingRequest
    {
        return Http::baseUrl((string) config('tarmio.url'))->acceptJson()->timeout(15)
            ->withHeaders(['apikey' => (string) config('tarmio.api_key')])
            ->withToken($this->session()['token']);
    }

    /** @return array{token: string, user_id: ?string} */
    private function session(): array
    {
        $cached = Cache::get('tarmio-session');
        if (is_array($cached)) {
            return $cached;
        }

        $response = Http::baseUrl((string) config('tarmio.url'))->acceptJson()->timeout(15)
            ->withHeaders(['apikey' => (string) config('tarmio.api_key')])
            ->post('/auth/v1/token?grant_type=password', ['email' => config('tarmio.user'), 'password' => config('tarmio.password')])
            ->throw()->json();
        $session = ['token' => (string) $response['access_token'], 'user_id' => $response['user']['id'] ?? null];
        Cache::put('tarmio-session', $session, max(60, (int) ($response['expires_in'] ?? 3600) - 120));

        return $session;
    }
}
