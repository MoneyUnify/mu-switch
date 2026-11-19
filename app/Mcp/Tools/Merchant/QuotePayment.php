<?php

namespace App\Mcp\Tools\Merchant;

use App\Http\Controllers\MerchantApiController;
use App\Mcp\Tools\MerchantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('quote_payment')]
#[Title('Quote a payment')]
#[Description('What a customer would pay with PayPal for an amount, the commission, and what you receive in your wallet. Creates nothing.')]
#[IsReadOnly]
class QuotePayment extends MerchantTool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->forMerchant([MerchantApiController::class, 'quote'], $request->all(['amount', 'mode', 'source_currency', 'settle_currency']), [], null);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'amount' => $schema->number()->description('The amount, e.g. 2500.')->required(),
            'mode' => $schema->string()->description('target (default): amount is your price. source: amount is what the customer pays.')->enum(['source', 'target']),
            'source_currency' => $schema->string()->description('The currency the payer pays in with PayPal, e.g. USD. Defaults to USD.'),
            'settle_currency' => $schema->string()->description('What you are settled in.'),
        ];
    }
}
