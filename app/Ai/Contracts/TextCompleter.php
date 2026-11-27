<?php

declare(strict_types=1);

namespace App\Ai\Contracts;

use App\Ai\AiUnavailable;

/**
 * What drafts and translates the public texts: the provider configured in Platform settings → Artificial
 * intelligence (AiClient: OpenRouter or OpenAI) by default.
 *
 * Extension point for a self-hosted model: bind another implementation in the container
 * (`$this->app->bind(TextCompleter::class, …)`) and PropertyTextWriter, the «Idiomas y traducción» status and
 * the property buttons use it without further changes. Small local models were measured too slow and
 * inaccurate for this (docs/clarto/idiomas-y-deuda.md), so none is bound today.
 */
interface TextCompleter
{
    /**
     * @param  'writing'|'translation'  $task
     * @return array{content: string, model: string}
     *
     * @throws AiUnavailable
     */
    public function complete(string $task, string $system, string $user): array;

    /** Why it cannot be used yet (for example, no API key), or null when it is ready. */
    public function missingConfiguration(): ?string;
}
