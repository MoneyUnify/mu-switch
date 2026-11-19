<?php

namespace App\Mcp\Tools\Operator;

use App\Http\Controllers\MerchantApiController;
use App\Mcp\Tools\OperatorTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[Name('charge_merchant_customer')]
#[Title('Charge for a merchant')]
#[Description('Collect a payment for a merchant: the customer pays with PayPal at data.checkout_url, and the merchant is settled to its wallet less commission.')]
#[IsOpenWorld]
class ChargeMerchantCustomer extends OperatorTool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->forward([MerchantApiController::class, 'charge'], $request->all(['amount', 'mode', 'source_currency', 'settle_currency', 'payer_email', 'pay_with', 'reference', 'return_url']), $this->account($request), ['merchant' => (string) $request->get('merchant')], $request->get('idempotency_key'));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'merchant' => $schema->string()->description('The merchant\'s id or slug.')->required(),
            'amount' => $schema->number()->description('The amount, e.g. 2500.')->required(),
            'mode' => $schema->string()->description('target (default): amount is the merchant\'s price. source: amount is what the customer pays.')->enum(['source', 'target']),
            'source_currency' => $schema->string()->description('The currency the payer pays in with PayPal, e.g. USD. Defaults to USD.'),
            'settle_currency' => $schema->string()->description('What the merchant is settled in.'),
            'payer_email' => $schema->string()->description('The customer\'s email.'),
            'pay_with' => $schema->string()->description('paypal (default) or card: card opens PayPal on its guest debit/credit card form, for payers without a PayPal account.')->enum(['paypal', 'card']),
            'reference' => $schema->string()->description('Your order or invoice reference.'),
            'return_url' => $schema->string()->description('Where PayPal sends the payer back to afterwards.'),
            'idempotency_key' => $schema->string()->description('Any unique string. Retrying with the same key returns the first result instead of acting twice.'),
        ];
    }
}
