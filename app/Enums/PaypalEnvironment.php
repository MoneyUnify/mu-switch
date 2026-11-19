<?php

namespace App\Enums;

enum PaypalEnvironment: string
{
    case SANDBOX = 'sandbox';
    case LIVE = 'live';

    /**
     * The REST API host for this environment.
     */
    public function apiUrl(): string
    {
        return match ($this) {
            self::SANDBOX => 'https://api-m.sandbox.paypal.com',
            self::LIVE => 'https://api-m.paypal.com',
        };
    }

    /**
     * PayPal's MCP server (Streamable HTTP) for this environment.
     */
    /**
     * The PAYPAL_ENVIRONMENT value PayPal's Agent Toolkit MCP server expects.
     */
    public function agentToolkitName(): string
    {
        return $this === self::LIVE ? 'PRODUCTION' : 'SANDBOX';
    }

    public function mcpUrl(): string
    {
        return match ($this) {
            self::SANDBOX => 'https://mcp.sandbox.paypal.com/http',
            self::LIVE => 'https://mcp.paypal.com/http',
        };
    }
}
