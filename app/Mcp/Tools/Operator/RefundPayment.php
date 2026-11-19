<?php

namespace App\Mcp\Tools\Operator;

use App\Http\Controllers\RefundController;
use App\Mcp\Tools\OperatorTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[Name('refund_payment')]
#[Title('Refund a payment')]
#[Description('Refund a PayPal payment in full, or in part with amount. Payments that fund a cross-border transfer are refunded automatically if delivery fails, so they can\'t be refunded here.')]
#[IsDestructive]
#[IsOpenWorld]
class RefundPayment extends OperatorTool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->forward([RefundController::class, 'store'], $request->all(['transaction_id', 'amount', 'reason']), $this->account($request), [], $request->get('idempotency_key'));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'transaction_id' => $schema->string()->description('The payment\'s transaction_id.')->required(),
            'amount' => $schema->number()->description('Part of the payment to refund. Omit to refund what\'s left.'),
            'reason' => $schema->string()->description('Shown to the payer.'),
            'idempotency_key' => $schema->string()->description('Any unique string. Retrying with the same key returns the first result instead of acting twice.'),
        ];
    }
}
