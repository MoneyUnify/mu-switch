<?php

namespace App\Support\Ai;

use Laravel\Ai\Ai;
use SensitiveParameter;

/**
 * One provider an agent can run on, with its key already resolved from the
 * dashboard or .env.
 */
final class ResolvedAiProvider
{
    public const SOURCE_DASHBOARD = 'dashboard';

    public const SOURCE_ENV = 'env';

    public function __construct(
        public readonly string $provider,
        public readonly ?string $model,
        #[SensitiveParameter] private readonly ?string $apiKey,
        public readonly ?string $keySource,
    ) {}

    /**
     * Resolve a provider, using the .env key when no account key is given.
     */
    public static function resolve(string $provider, ?string $model = null, #[SensitiveParameter] ?string $accountKey = null): self
    {
        $envKey = config("ai.providers.{$provider}.key") ?: null;
        $apiKey = $accountKey ?: $envKey;

        return new self(
            provider: $provider,
            model: $model ?: null,
            apiKey: $apiKey,
            keySource: match (true) {
                (bool) $accountKey => self::SOURCE_DASHBOARD,
                $envKey !== null => self::SOURCE_ENV,
                default => null,
            },
        );
    }

    public function hasKey(): bool
    {
        return $this->apiKey !== null;
    }

    /**
     * The name to prompt with. An account key gets its own on-demand
     * provider, so it never touches the shared .env one.
     */
    public function providerName(): string
    {
        if ($this->keySource !== self::SOURCE_DASHBOARD) {
            return $this->provider;
        }

        $name = 'account_'.hash('sha256', $this->provider.'|'.$this->apiKey);

        Ai::build([
            ...config("ai.providers.{$this->provider}", []),
            'driver' => $this->provider,
            'key' => $this->apiKey,
            'name' => $name,
        ]);

        return $name;
    }

    /**
     * A key with all but its last four characters hidden.
     */
    public static function mask(#[SensitiveParameter] string $key): string
    {
        return '••••••••'.substr($key, -4);
    }
}
