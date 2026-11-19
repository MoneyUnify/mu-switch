<?php

use App\Http\Middleware\AuthenticateMcpMerchant;
use App\Http\Middleware\AuthenticateMcpOperator;
use App\Http\Middleware\LogApiRequests;
use App\Mcp\Servers\MerchantServer;
use App\Mcp\Servers\OperatorServer;
use Laravel\Mcp\Facades\Mcp;

if (config('app.docs_only_routes')) {
    return;
}

// Operators (DFSPs, aggregators, mobile-money operators): the account's API token.
Mcp::web('/mcp/operator', OperatorServer::class)
    ->middleware([AuthenticateMcpOperator::class, 'throttle:mcp', LogApiRequests::class])
    ->name('mcp.operator');

// Merchants: the merchant's own MCP key, issued by its operator.
Mcp::web('/mcp/merchant', MerchantServer::class)
    ->middleware([AuthenticateMcpMerchant::class, 'throttle:mcp', LogApiRequests::class])
    ->name('mcp.merchant');
