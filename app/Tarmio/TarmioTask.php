<?php

declare(strict_types=1);

namespace App\Tarmio;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * One pending action of a Liberu record, mirrored as one TARMIO task. Entry point for every module:
 *
 *   TarmioTask::sync($record, 'averia', 'Avería · RE-2026-00012', 'tecnico@clarto.es', now()->addDay(), $url);
 *
 * Calling it again with new data (date, assignee, title) updates the same TARMIO task; calling it with the same
 * data sends nothing. Only title, assignee, due date, link and a technical reference leave Liberu.
 * TARMIO's status is never read back: completing the task there does not change the Liberu record.
 */
final class TarmioTask extends Model
{
    protected $table = 'clarto_tarmio_tasks';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['due_on' => 'date', 'synced_at' => 'datetime'];
    }

    public static function sync(Model $source, string $kind, string $title, ?string $assigneeEmail, ?DateTimeInterface $dueAt, string $url): self
    {
        $task = self::query()->updateOrCreate(
            ['source_type' => $source->getMorphClass(), 'source_id' => $source->getKey(), 'kind' => $kind],
            ['title' => Str::limit($title, 290), 'assignee_email' => $assigneeEmail ? Str::lower(trim($assigneeEmail)) : null, 'due_on' => $dueAt?->format('Y-m-d'), 'url' => $url],
        );

        if ($task->fingerprint() !== $task->synced_hash) {
            $task->forceFill(['status' => 'pendiente'])->save();
            SyncTarmioTask::dispatch($task->getKey())->afterCommit();
        }

        return $task;
    }

    /** Sends the current data to TARMIO (create, or update the task already linked). Run by SyncTarmioTask. */
    public function push(?TarmioClient $client = null): void
    {
        $client ??= app(TarmioClient::class);

        Cache::lock('tarmio-task:'.$this->getKey(), 60)->block(20, function () use ($client): void {
            $this->refresh();
            $hash = $this->fingerprint();
            if ($this->tarmio_task_id && $hash === $this->synced_hash) {
                return;   // the same event twice: TARMIO already has this
            }

            try {
                $assigneeId = $this->assignee_email ? $client->userIdByEmail($this->assignee_email) : null;
                $data = [
                    'external_id' => $this->externalId(),
                    'title' => $this->title,
                    'description' => "Abrir en CLARTO Gestión Inmobiliaria: {$this->url}\nReferencia: {$this->externalId()}\n"
                        .'Tarea creada desde Liberu. Los documentos y datos del expediente se consultan allí; completar esta tarea no cambia el expediente.',
                    'due_date' => $this->due_on?->format('Y-m-d'),
                    'assignee_id' => $assigneeId,
                ];

                $id = $this->tarmio_task_id;
                if ($id !== null && str_starts_with($id, 'sim-') && config('tarmio.driver') === 'http') {
                    $id = null;   // created while simulated: create it for real now
                }
                $id ??= $client->findTask($data['external_id'])['id'] ?? null;   // created right before a crash: reuse it
                $result = $id !== null ? $client->updateTask($id, $data) : $client->createTask($data);

                $this->forceFill([
                    'tarmio_task_id' => $result['id'], 'tarmio_ref' => $result['ref'] ?? $this->tarmio_ref, 'synced_hash' => $hash,
                    'status' => 'sincronizada', 'synced_at' => now(), 'attempts' => $this->attempts + 1,
                    'error' => $this->assignee_email && $assigneeId === null ? 'Responsable sin usuario en la empresa CLARTO de TARMIO: '.$this->assignee_email : null,
                ])->save();
            } catch (Throwable $e) {
                $this->forceFill(['status' => 'error', 'attempts' => $this->attempts + 1, 'error' => Str::limit($e->getMessage(), 250)])->save();
                throw $e;
            }
        });
    }

    /** Non-sensitive reference shown in TARMIO and used to find the task again: kind:record:id. */
    public function externalId(): string
    {
        return $this->kind.':'.Str::snake(class_basename($this->source_type)).':'.$this->source_id;
    }

    private function fingerprint(): string
    {
        return sha1(json_encode([$this->title, $this->assignee_email, $this->due_on?->format('Y-m-d'), $this->url]));
    }
}
