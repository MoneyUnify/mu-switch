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
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[Name('create_payment')]
#[Title('Charge a customer')]
#[Description('Start a payment from a customer: send them to data.checkout_url to pay with PayPal (or by card with pay_with card). You are settled to your wallet once PayPal confirms.')]
#[IsOpenWorld]
class CreatePayment extends MerchantTool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->forMerchant([MerchantApiController::class, 'charge'], $request->all(['amount', 'mode', 'source_currency', 'settle_currency', 'payer_email', 'pay_with', 'reference', 'return_url']), [], $request->get('idempotency_key'));
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
            'payer_email' => $schema->string()->description('The customer\'s email: pre-fills PayPal, and gets the payment emails.'),
            'pay_with' => $schema->string()->description('paypal (default) or card: card opens PayPal on its guest debit/credit card form, for payers without a PayPal account.')->enum(['paypal', 'card']),
            'reference' => $schema->string()->description('Your order or invoice reference.'),
            'return_url' => $schema->string()->description('Where PayPal sends the payer back to afterwards.'),
            'idempotency_key' => $schema->string()->description('Any unique string. Retrying with the same key returns the first result instead of acting twice.'),
        ];
    }
}
