<?php

namespace App\Mcp\Tools\Operator;

use App\Http\Controllers\PayoutController;
use App\Mcp\Tools\OperatorTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('check_payout')]
#[Title('Check a payout')]
#[Description('Re-check a payout with its provider and return its status.')]
#[IsIdempotent]
class CheckPayout extends OperatorTool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->forward([PayoutController::class, 'verifyPayout'], $request->all(['transaction_id']), $this->account($request), [], null);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'transaction_id' => $schema->string()->description('The payout\'s transaction_id.')->required(),
        ];
    }
}
