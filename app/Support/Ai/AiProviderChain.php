<?php

namespace App\Support\Ai;

use App\Models\AiCredential;
use App\Models\User;
use BackedEnum;

/**
 * The ordered AI providers an account's agents fail over between: the
 * account's own list, or the .env default when it has none.
 */
final class AiProviderChain
{
    /**
     * @param  list<ResolvedAiProvider>  $providers
     */
    private function __construct(public readonly array $providers, public readonly bool $fromAccount) {}

    public static function for(?User $user): self
    {
        $credentials = $user?->aiCredentials()->get() ?? collect();

        if ($credentials->isEmpty()) {
            return new self([self::envDefault()], fromAccount: false);
        }

        return new self($credentials->map(fn (AiCredential $credential): ResolvedAiProvider => ResolvedAiProvider::resolve(
            $credential->provider->value,
            $credential->model,
            $credential->api_key,
        ))->values()->all(), fromAccount: true);
    }

    /**
     * The .env default provider (AI_PROVIDER).
     */
    public static function envDefault(): ResolvedAiProvider
    {
        $default = config('ai.default');

        return ResolvedAiProvider::resolve($default instanceof BackedEnum ? $default->value : (string) $default);
    }

    /**
     * Provider name => model, in order, for the AI SDK's failover. Providers
     * without a key are skipped; if none has one, the .env default is used.
     *
     * @return array<string, ?string>
     */
    public function failover(): array
    {
        $usable = array_filter($this->providers, fn (ResolvedAiProvider $provider): bool => $provider->hasKey());
        $usable = $usable === [] ? [self::envDefault()] : $usable;

        $chain = [];
        foreach ($usable as $provider) {
            $chain[$provider->providerName()] = $provider->model;
        }

        return $chain;
    }

    public function hasUsableProvider(): bool
    {
        foreach ($this->providers as $provider) {
            if ($provider->hasKey()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether an agent can run: a provider with a key, here or in .env.
     */
    public function canRun(): bool
    {
        return $this->hasUsableProvider() || self::envDefault()->hasKey();
    }
}
