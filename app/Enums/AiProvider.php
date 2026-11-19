<?php

namespace App\Enums;

use Laravel\Ai\Ai;
use Throwable;

/**
 * AI providers an account can choose for its agents in the dashboard.
 */
enum AiProvider: string
{
    case ANTHROPIC = 'anthropic';
    case OPENAI = 'openai';
    case GEMINI = 'gemini';
    case MISTRAL = 'mistral';
    case GROQ = 'groq';
    case XAI = 'xai';
    case DEEPSEEK = 'deepseek';
    case OPENROUTER = 'openrouter';

    public function label(): string
    {
        return match ($this) {
            self::ANTHROPIC => 'Anthropic (Claude)',
            self::OPENAI => 'OpenAI',
            self::GEMINI => 'Google Gemini',
            self::MISTRAL => 'Mistral',
            self::GROQ => 'Groq',
            self::XAI => 'xAI (Grok)',
            self::DEEPSEEK => 'DeepSeek',
            self::OPENROUTER => 'OpenRouter',
        };
    }

    /**
     * The .env variable holding this provider's fallback key.
     */
    public function envKey(): string
    {
        return strtoupper($this->value).'_API_KEY';
    }

    /**
     * Suggested models, the SDK's default first. Accounts may also enter any
     * other model the provider offers. Model names follow the installed SDK
     * (see each provider's default, cheapest and smartest text models).
     *
     * @return list<array{id: string, note: string}>
     */
    public function models(): array
    {
        return match ($this) {
            self::ANTHROPIC => [
                ['id' => 'claude-sonnet-5-5', 'note' => 'Balanced'],
                ['id' => 'claude-opus-5-5', 'note' => 'Most capable'],
                ['id' => 'claude-fable-5-1', 'note' => 'Most capable, newest'],
                ['id' => 'claude-haiku-5-5', 'note' => 'Fastest, lowest cost'],
            ],
            self::OPENAI => [
                ['id' => 'gpt-6.1-sol', 'note' => 'Balanced'],
                ['id' => 'gpt-6-astra', 'note' => 'Most capable'],
                ['id' => 'gpt-6-luna', 'note' => 'Fastest, lowest cost'],
            ],
            self::GEMINI => [
                ['id' => 'gemini-3.8-flash', 'note' => 'Newest; can be busy at peak times'],
                ['id' => 'gemini-3-flash-preview', 'note' => 'Balanced'],
                ['id' => 'gemini-3.1-flash-lite', 'note' => 'Fastest, lowest cost'],
            ],
            self::MISTRAL => [
                ['id' => 'mistral-large-2512', 'note' => 'Balanced'],
                ['id' => 'mistral-medium-3-5', 'note' => 'Most capable'],
                ['id' => 'mistral-small-2603', 'note' => 'Fastest, lowest cost'],
            ],
            self::GROQ => [
                ['id' => 'openai/gpt-oss-120b', 'note' => 'Most capable'],
                ['id' => 'openai/gpt-oss-20b', 'note' => 'Fastest, lowest cost'],
            ],
            self::XAI => [
                ['id' => 'grok-4.7', 'note' => 'Most capable'],
                ['id' => 'grok-4.20-non-reasoning', 'note' => 'Fastest, lowest cost'],
            ],
            self::DEEPSEEK => [
                ['id' => 'deepseek-flash', 'note' => 'Fastest, lowest cost'],
                ['id' => 'deepseek-v4-pro', 'note' => 'Most capable'],
            ],
            self::OPENROUTER => [
                ['id' => 'anthropic/claude-sonnet-5.5', 'note' => 'Balanced'],
                ['id' => 'anthropic/claude-fable-5.1', 'note' => 'Most capable'],
                ['id' => 'anthropic/claude-haiku-4.5', 'note' => 'Fastest, lowest cost'],
            ],
        };
    }

    /**
     * The model used when an account doesn't choose one: the SDK's default,
     * including any override in config/ai.php.
     */
    public function defaultModel(): string
    {
        try {
            return Ai::textProvider($this->value)->defaultTextModel();
        } catch (Throwable) {
            return $this->models()[0]['id'];
        }
    }

    /**
     * Every provider as a dashboard option.
     *
     * @return list<array{value: string, label: string, envKey: string, models: list<array{id: string, note: string}>, defaultModel: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $provider): array => [
            'value' => $provider->value,
            'label' => $provider->label(),
            'envKey' => $provider->envKey(),
            'models' => $provider->models(),
            'defaultModel' => $provider->defaultModel(),
        ], self::cases());
    }
}
