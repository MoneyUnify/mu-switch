<?php

namespace App\Ai\Concerns;

use App\Models\User;
use App\Support\Ai\AiProviderChain;

/**
 * Runs an agent on an account's AI providers, failing over in their order
 * (or on the .env default when the account has none).
 */
trait UsesAccountAiCredentials
{
    private ?User $credentialsOwner = null;

    /**
     * Use this account's AI providers.
     */
    public function usingCredentialsOf(?User $user): static
    {
        $this->credentialsOwner = $user;

        return $this;
    }

    /**
     * Provider name => model, tried in order.
     *
     * @return array<string, ?string>
     */
    public function provider(): array
    {
        return AiProviderChain::for($this->credentialsOwner)->failover();
    }
}
