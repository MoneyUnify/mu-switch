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
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('check_refund')]
#[Title('Check a refund')]
#[Description('Re-check a refund with PayPal and return its status.')]
#[IsIdempotent]
class CheckRefund extends OperatorTool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->forward([RefundController::class, 'verify'], $request->all(['refund_id']), $this->account($request), [], null);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'refund_id' => $schema->string()->description('The refund\'s refund_id.')->required(),
        ];
    }
}
