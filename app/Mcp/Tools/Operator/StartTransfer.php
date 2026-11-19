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
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[Name('start_transfer')]
#[Title('Start a transfer')]
#[Description('Start a cross-border transfer: the sender pays with PayPal at data.checkout_url, then the recipient is paid on mobile money at the locked rate, or the sender is refunded if delivery fails. Confirm the amount and recipient with the user first.')]
#[IsOpenWorld]
class StartTransfer extends OperatorTool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->forward([CrossBorderTransferController::class, 'request'], $request->all(['amount', 'country', 'account_number', 'mode', 'source_currency', 'recipient_name', 'recipient_email', 'network', 'narration', 'payer_email', 'pay_with', 'return_url', 'fx_quote_id']), $this->account($request), [], $request->get('idempotency_key'));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'amount' => $schema->number()->description('The amount, e.g. 2500.')->required(),
            'country' => $schema->string()->description('The country, as a 2-letter ISO code, e.g. ZM.')->required(),
            'account_number' => $schema->string()->description('The recipient\'s mobile number.')->required(),
            'mode' => $schema->string()->description('target (default): the recipient gets exactly the amount. source: the sender pays exactly the amount.')->enum(['source', 'target']),
            'source_currency' => $schema->string()->description('The currency the payer pays in with PayPal, e.g. USD. Defaults to USD.'),
            'recipient_name' => $schema->string()->description('The recipient\'s name.'),
            'recipient_email' => $schema->string()->description('The recipient\'s email: they\'re told when the money arrives.'),
            'network' => $schema->string()->description('The recipient\'s network.'),
            'narration' => $schema->string()->description('Shown to the recipient.'),
            'payer_email' => $schema->string()->description('The sender\'s email: pre-fills PayPal, and gets the delivered or refunded email.'),
            'pay_with' => $schema->string()->description('paypal (default) or card: card opens PayPal on its guest debit/credit card form, for payers without a PayPal account.')->enum(['paypal', 'card']),
            'return_url' => $schema->string()->description('Where PayPal sends the payer back to afterwards.'),
            'fx_quote_id' => $schema->string()->description('Use this locked FX quote.'),
            'idempotency_key' => $schema->string()->description('Any unique string. Retrying with the same key returns the first result instead of acting twice.'),
        ];
    }
}
