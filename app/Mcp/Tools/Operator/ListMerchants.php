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
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_merchants')]
#[Title('List merchants')]
#[Description('The merchants this operator collects and settles for.')]
#[IsReadOnly]
class ListMerchants extends OperatorTool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->forward([MerchantApiController::class, 'index'], [], $this->account($request), [], null);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [

        ];
    }
}
