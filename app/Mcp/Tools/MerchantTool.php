<?php

namespace App\Mcp\Tools;

use App\Mcp\MerchantAccess;
use App\Models\Merchant;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

/**
 * A tool on the merchant MCP server. It only ever acts on the signed-in
 * merchant: every call is pinned to that merchant, through the operator's
 * account that collects and settles for it.
 */
abstract class MerchantTool extends SwitchTool
{
    protected function merchant(): ?Merchant
    {
        return app()->bound(MerchantAccess::class) ? app(MerchantAccess::class)->merchant->refresh() : null;
    }

    /**
     * Run a REST API action for the signed-in merchant.
     *
     * @param  array{0: class-string, 1: string}  $action
     * @param  array<string, mixed>  $input
     * @param  array<string, string>  $parameters
     */
    protected function forMerchant(array $action, array $input, array $parameters = [], ?string $idempotencyKey = null): Response|ResponseFactory
    {
        $merchant = $this->merchant();

        if (! $merchant) {
            return Response::error('Not signed in as a merchant.');
        }

        return $this->forward($action, $input, $merchant->user, ['merchant' => (string) $merchant->id, ...$parameters], $idempotencyKey);
    }
}
