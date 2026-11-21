<?php

declare(strict_types=1);

namespace App\InboundMail;

/**
 * The model that classifies what the rules cannot place. InboundMails builds the short prompt and reads the JSON;
 * an implementation only sends it. Chosen with MAIL_IN_CLASIFICADOR (default: free OpenRouter models), so another
 * provider (e.g. an OpenCode free endpoint) plugs in without touching anything else.
 */
interface MailClassifier
{
    /**
     * @param  string|null  $model  model chosen in «Ajustes del correo entrante» (null: the implementation's default)
     * @return array{content: string, model: string}
     *
     * @throws \App\Ai\AiQuotaExceeded rate limit / quota: triage pauses the AI for a while
     * @throws \App\Ai\AiUnavailable anything else: the message waits in «Pendiente de revisar»
     */
    public function complete(string $system, string $user, ?string $model): array;
}
