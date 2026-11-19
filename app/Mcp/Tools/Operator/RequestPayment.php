<?php

namespace App\Mcp\Tools\Operator;

use App\Http\Controllers\SwitchController;
use App\Mcp\Tools\OperatorTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[Name('request_payment')]
#[Title('Collect a payment')]
#[Description('Collect a payment from a customer through the switch: the first eligible provider takes it, falling back to the next one only when a provider definitely refuses. Mobile money prompts the payer\'s phone; PayPal returns data.checkout_url for the payer to approve.')]
#[IsOpenWorld]
class RequestPayment extends OperatorTool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->forward([SwitchController::class, 'requestPayment'], $request->all(['amount', 'account_number', 'country', 'currency', 'email', 'pay_with', 'return_url', 'callback_url', 'allow_fx']), $this->account($request), [], null);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'amount' => $schema->number()->description('The amount, e.g. 2500.')->required(),
            'account_number' => $schema->string()->description('The payer: a mobile number for mobile money, or an email for PayPal.')->required(),
            'country' => $schema->string()->description('The country, as a 2-letter ISO code, e.g. ZM.')->required(),
            'currency' => $schema->string()->description('3-letter currency code. Defaults to the country\'s own.'),
            'email' => $schema->string()->description('The payer\'s email, for providers that accept one.'),
            'pay_with' => $schema->string()->description('paypal (default) or card: card opens PayPal on its guest debit/credit card form, for payers without a PayPal account.')->enum(['paypal', 'card']),
            'return_url' => $schema->string()->description('Where PayPal sends the payer back to afterwards.'),
            'callback_url' => $schema->string()->description('Where the final result is POSTed.'),
            'allow_fx' => $schema->boolean()->description('Allow providers that charge in another currency.'),
        ];
    }
}
