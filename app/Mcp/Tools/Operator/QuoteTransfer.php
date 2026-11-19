<?php

namespace App\Mcp\Tools\Operator;

use App\Http\Controllers\CrossBorderTransferController;
use App\Mcp\Tools\OperatorTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('quote_transfer')]
#[Title('Quote a transfer')]
#[Description('Quote a cross-border transfer paid in with PayPal and delivered to mobile money: what the sender pays, what arrives, the locked FX rate (and whether it is PayPal\'s), fees and route. Creates nothing.')]
#[IsReadOnly]
class QuoteTransfer extends OperatorTool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->forward([CrossBorderTransferController::class, 'quote'], $request->all(['amount', 'country', 'mode', 'source_currency', 'currency']), $this->account($request), [], null);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'amount' => $schema->number()->description('The amount, e.g. 2500.')->required(),
            'country' => $schema->string()->description('The country, as a 2-letter ISO code, e.g. ZM.')->required(),
            'mode' => $schema->string()->description('target (default): the recipient gets exactly the amount. source: the sender pays exactly the amount.')->enum(['source', 'target']),
            'source_currency' => $schema->string()->description('The currency the payer pays in with PayPal, e.g. USD. Defaults to USD.'),
            'currency' => $schema->string()->description('3-letter currency code. Defaults to the country\'s own.'),
        ];
    }
}
