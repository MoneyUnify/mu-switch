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
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_payments')]
#[Title('List payments')]
#[Description('Your payments, newest first, and how each was settled to your wallet.')]
#[IsReadOnly]
class ListPayments extends MerchantTool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->forMerchant([MerchantApiController::class, 'settlements'], $request->all(['per_page']), [], null);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'per_page' => $schema->integer()->description('1 to 100, default 25.'),
        ];
    }
}
