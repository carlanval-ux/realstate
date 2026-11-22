<?php

declare(strict_types=1);

namespace App\InboundMail;

use App\Ai\AiClient;
use App\Ai\AiQuotaExceeded;
use App\Ai\AiUnavailable;

/**
 * Free OpenRouter models through the panel's AiClient (same key, retries the free tier's 429 with backoff). Never the
 * paid fallback. If the chosen model is gone or fails, tries OpenRouter's free router before giving up.
 */
final class OpenRouterMailClassifier implements MailClassifier
{
    public const DEFAULT_MODEL = 'google/gemma-4-26b-a4b-it:free';

    /** OpenRouter picks any available free model: survives a model being withdrawn from the free tier. */
    public const FREE_ROUTER = 'openrouter/free';

    public function complete(string $system, string $user, ?string $model): array
    {
        $client = AiClient::make();
        $models = array_values(array_unique(array_filter([$model ?: config('correo-entrante.ai_model') ?: self::DEFAULT_MODEL, self::FREE_ROUTER])));
        foreach ($models as $i => $candidate) {
            try {
                return $client->complete('translation', $system, $user, $candidate, paidFallback: false);
            } catch (AiQuotaExceeded $e) {
                throw $e;   // the whole free tier is rate-limited: pause, do not hammer the router too
            } catch (AiUnavailable $e) {
                if ($i === count($models) - 1) {
                    throw $e;
                }
            }
        }
        throw new AiUnavailable('No model.');
    }
}
