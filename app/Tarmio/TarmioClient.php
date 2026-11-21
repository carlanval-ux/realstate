<?php

declare(strict_types=1);

namespace App\Tarmio;

/**
 * What Liberu needs from TARMIO. A task is identified on TARMIO's side by source_provider "liberu" +
 * source_external_id, so a task created just before a crash is found again instead of duplicated.
 *
 * $task keys: title, description, due_date (Y-m-d|null), assignee_id (TARMIO profile id|null), external_id.
 */
interface TarmioClient
{
    /** TARMIO profile id for a Liberu user's e-mail, or null when that person is not in TARMIO. */
    public function userIdByEmail(string $email): ?string;

    /** @return array{id: string, ref: ?string}|null */
    public function findTask(string $externalId): ?array;

    /** @return array{id: string, ref: ?string} */
    public function createTask(array $task): array;

    /** @return array{id: string, ref: ?string} */
    public function updateTask(string $id, array $task): array;
}
