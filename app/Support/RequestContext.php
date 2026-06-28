<?php

namespace App\Support;

/**
 * Holds a correlation id for the current inbound API request. The API-logging
 * middleware sets it at the start of the request; the provider-call logger
 * stamps it on every outgoing gateway (MNO) call, so an API log and all the MNO
 * calls it triggered share one id and can be traced together.
 */
class RequestContext
{
    private static ?string $requestId = null;

    public static function set(string $requestId): void
    {
        self::$requestId = $requestId;
    }

    public static function clear(): void
    {
        self::$requestId = null;
    }

    public static function id(): ?string
    {
        return self::$requestId;
    }
}
