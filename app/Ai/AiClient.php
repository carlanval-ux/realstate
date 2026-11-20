<?php

declare(strict_types=1);

namespace App\Ai;

use App\Models\AiSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * OpenAI-compatible chat client: OpenRouter by default, OpenAI directly as the alternative (the same
 * request format the existing GeneratePropertyDescription integration uses).
 */
final class AiClient
{
    public const OPENROUTER_URL = 'https://openrouter.ai/api/v1';

    public function __construct(private readonly AiSetting $settings) {}

    public static function make(): self
    {
        return new self(AiSetting::current());
    }

    /**
     * @param  'writing'|'translation'  $task
     * @return array{content: string, model: string}
     */
    public function complete(string $task, string $system, string $user, ?string $model = null): array
    {
        $model ??= $task === 'writing' ? $this->settings->writing_model : $this->settings->translation_model;
        if (blank($model)) {
            throw new AiUnavailable(__('No AI model is configured for this task.'));
        }

        try {
            return $this->request($model, $system, $user, $task === 'writing' ? 0.5 : 0.2);
        } catch (AiQuotaExceeded $quota) {
            $fallback = $this->settings->paid_fallback_model;
            if ($this->settings->paid_fallback_authorized && filled($fallback) && $fallback !== $model) {
                return $this->request($fallback, $system, $user, $task === 'writing' ? 0.5 : 0.2);
            }
            throw $quota;
        }
    }

    /** @return list<string> human-readable lines */
    public function testConnection(): array
    {
        $lines = [__('Provider: :p', ['p' => AiSetting::PROVIDERS[$this->settings->provider] ?? $this->settings->provider])];

        if ($this->settings->provider === 'openrouter') {
            $key = $this->http()->get(self::OPENROUTER_URL.'/key');
            $this->guard($key, 'openrouter/key');
            $data = (array) $key->json('data');
            $lines[] = __('Key: :label · used :usage · limit :limit · free tier: :free', [
                'label' => $data['label'] ?? '—',
                'usage' => $data['usage'] ?? 0,
                'limit' => $data['limit'] ?? __('none'),
                'free' => ($data['is_free_tier'] ?? false) ? __('yes') : __('no'),
            ]);
        }

        foreach (['writing', 'translation'] as $task) {
            $reply = $this->complete($task, 'Reply with the single word OK.', 'OK?');
            $lines[] = __(':task: :model → :reply', ['task' => __($task === 'writing' ? 'Writing' : 'Translation'), 'model' => $reply['model'], 'reply' => mb_strimwidth(trim($reply['content']), 0, 40, '…')]);
        }

        return $lines;
    }

    /** @return array<string, string> OpenRouter free models (id => name), cached for an hour; empty if unreachable. */
    public static function freeModels(): array
    {
        return Cache::remember('clarto.ai.free-models', 3600, function (): array {
            try {
                $models = (array) Http::timeout(15)->get(self::OPENROUTER_URL.'/models')->json('data');
            } catch (ConnectionException) {
                return [];
            }

            return collect($models)
                ->filter(fn (array $m): bool => (string) data_get($m, 'pricing.prompt') === '0' && (string) data_get($m, 'pricing.completion') === '0')
                ->mapWithKeys(fn (array $m): array => [$m['id'] => $m['name'] ?? $m['id']])
                ->sortKeys()->all();
        });
    }

    /** @return array{content: string, model: string} */
    private function request(string $model, string $system, string $user, float $temperature): array
    {
        $url = $this->settings->provider === 'openai'
            ? (string) config('services.openai.endpoint', 'https://api.openai.com/v1/chat/completions')
            : self::OPENROUTER_URL.'/chat/completions';

        $body = [
            'model' => $model,
            'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]],
            'temperature' => $temperature,
            'max_tokens' => 4000,
        ];
        if ($this->settings->provider === 'openrouter') {
            // Reasoning models would otherwise spend the answer thinking (empty content); ignored by the others.
            $body['reasoning'] = ['effort' => 'low', 'exclude' => true];
        }

        // Free models are rate-limited per minute: wait and retry twice before reporting the quota as used up.
        foreach ([0, 10, 25] as $attempt => $wait) {
            if ($wait > 0) {
                sleep($wait);
            }
            try {
                $response = $this->http()->timeout(180)->post($url, $body);
            } catch (ConnectionException $e) {
                throw new AiUnavailable(__('The AI provider did not answer: :m', ['m' => $e->getMessage()]));
            }
            if ($response->status() !== 429 || $attempt === 2) {
                break;
            }
        }

        $this->guard($response, $model);
        $content = $response->json('choices.0.message.content');
        if (! is_string($content) || trim($content) === '') {
            throw new AiUnavailable(__('The model :model returned an empty answer.', ['model' => $model]));
        }

        return ['content' => $content, 'model' => (string) ($response->json('model') ?: $model)];
    }

    private function http()
    {
        $key = $this->settings->apiKey();
        if (blank($key)) {
            throw new AiUnavailable(__('The API key for :p is not configured (Platform settings → Artificial intelligence).', ['p' => AiSetting::PROVIDERS[$this->settings->provider] ?? $this->settings->provider]));
        }

        return Http::withToken((string) $key)->acceptJson()->withHeaders([
            'HTTP-Referer' => 'https://clarto.es',
            'X-Title' => 'CLARTO Gestion Inmobiliaria',
        ]);
    }

    private function guard(Response $response, string $what): void
    {
        if ($response->successful() && $response->json('error') === null) {
            return;
        }

        $status = $response->status();
        $message = (string) ($response->json('error.message') ?? $response->body());
        if (in_array($status, [402, 429], true) || preg_match('/rate.?limit|quota|credit|insufficient/i', $message)) {
            throw new AiQuotaExceeded(__('The AI quota is used up (:what, HTTP :s). What was already generated is saved; try again later or choose another model.', ['what' => $what, 's' => $status]));
        }

        throw new AiUnavailable(__('The AI provider rejected the request (:what, HTTP :s): :m', ['what' => $what, 's' => $status, 'm' => mb_strimwidth($message, 0, 200, '…')]));
    }
}
