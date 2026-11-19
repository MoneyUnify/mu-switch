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
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('check_payment')]
#[Title('Check a payment')]
#[Description('Re-check a payment with the provider that took it and return its current status.')]
#[IsIdempotent]
class CheckPayment extends OperatorTool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->forward([SwitchController::class, 'verifyPayment'], $request->all(['transaction_id']), $this->account($request), [], null);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'transaction_id' => $schema->string()->description('The transaction_id the switch returned.')->required(),
        ];
    }
}
