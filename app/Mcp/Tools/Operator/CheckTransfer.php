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
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('check_transfer')]
#[Title('Check a transfer')]
#[Description('Move a transfer on if it can (capture, pay out, refund) and return its status and timeline.')]
#[IsIdempotent]
class CheckTransfer extends OperatorTool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->forward([CrossBorderTransferController::class, 'verify'], $request->all(['reference']), $this->account($request), [], null);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'reference' => $schema->string()->description('The transfer reference.')->required(),
        ];
    }
}
