<?php

namespace App\Mcp;

use App\Models\Merchant;

/**
 * The merchant signed in to the merchant MCP server.
 */
final class MerchantAccess
{
    public function __construct(public readonly Merchant $merchant) {}
}
