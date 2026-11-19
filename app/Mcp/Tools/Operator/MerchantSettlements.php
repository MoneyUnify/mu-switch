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

#[Name('merchant_settlements')]
#[Title('Merchant settlements')]
#[Description('A merchant\'s payments and how each was settled.')]
#[IsReadOnly]
class MerchantSettlements extends OperatorTool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->forward([MerchantApiController::class, 'settlements'], $request->all(['per_page']), $this->account($request), ['merchant' => (string) $request->get('merchant')], null);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'merchant' => $schema->string()->description('The merchant\'s id or slug.')->required(),
            'per_page' => $schema->integer()->description('1 to 100, default 25.'),
        ];
    }
}
