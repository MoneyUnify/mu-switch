<?php

namespace App\Support;

use App\Models\PaymentProvider;

/**
 * Holds the provider whose outgoing HTTP calls should be logged for the current
 * request. The switch sets this around a driver invocation so the HTTP-client
 * event listener can attribute each call (request + response) to the right
 * provider, then clears it so unrelated outgoing HTTP is never logged.
 */
class ProviderCallContext
{
    private static ?int $providerId = null;

    private static ?int $userId = null;

    /**
     * Begin attributing outgoing HTTP calls to the given provider.
     */
    public static function set(PaymentProvider $provider): void
    {
        self::$providerId = $provider->id;
        self::$userId = $provider->user_id;
    }

    /**
     * Stop attributing outgoing HTTP calls to any provider.
     */
    public static function clear(): void
    {
        self::$providerId = null;
        self::$userId = null;
    }

    /**
     * Whether a provider is currently being attributed.
     */
    public static function active(): bool
    {
        return self::$providerId !== null;
    }

    public static function providerId(): ?int
    {
        return self::$providerId;
    }

    public static function userId(): ?int
    {
        return self::$userId;
    }
}
