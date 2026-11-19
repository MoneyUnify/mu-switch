<?php

namespace App\Mcp\Tools;

use App\Models\User;
use Laravel\Mcp\Request;

/**
 * A tool on the operator MCP server, run as the operator's account (the
 * DFSP, aggregator or operator that owns the providers).
 */
abstract class OperatorTool extends SwitchTool
{
    protected function account(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }
}
