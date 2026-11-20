<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Single-row AI configuration (provider, models, keys). Keys are encrypted at rest with APP_KEY and are
 * never filled back into a form. The paid fallback only runs when an administrator authorised it.
 */
final class AiSetting extends Model
{
    public const PROVIDERS = ['openrouter' => 'OpenRouter', 'openai' => 'OpenAI (directo)'];

    /** Free OpenRouter models used until an administrator chooses others. */
    public const DEFAULT_WRITING_MODEL = 'meta-llama/llama-3.3-70b-instruct:free';

    public const DEFAULT_TRANSLATION_MODEL = 'google/gemma-3-27b-it:free';

    protected $table = 'clarto_ai_settings';

    protected $guarded = ['id'];

    protected $hidden = ['openrouter_api_key', 'openai_api_key'];

    protected function casts(): array
    {
        return [
            'openrouter_api_key' => 'encrypted',
            'openai_api_key' => 'encrypted',
            'paid_fallback_authorized' => 'boolean',
            'paid_fallback_authorized_at' => 'datetime',
        ];
    }

    public static function current(): self
    {
        return self::query()->firstOrCreate([], [
            'provider' => 'openrouter',
            'writing_model' => self::DEFAULT_WRITING_MODEL,
            'translation_model' => self::DEFAULT_TRANSLATION_MODEL,
        ]);
    }

    /** Key for the active provider: OpenAI falls back to the team/env key the existing integration already uses. */
    public function apiKey(): ?string
    {
        return $this->provider === 'openai'
            ? ($this->openai_api_key ?: config('services.openai.api_key'))
            : $this->openrouter_api_key;
    }

    public static function maskKey(?string $key): string
    {
        return filled($key) ? '••••'.substr((string) $key, -4) : __('Not set');
    }
}
