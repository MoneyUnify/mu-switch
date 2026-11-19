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

#[Name('update_merchant')]
#[Title('Update a merchant')]
#[Description('Change a merchant\'s details, commission, or pause it (is_active false) and resume it.')]
class UpdateMerchant extends OperatorTool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->forward([MerchantApiController::class, 'update'], $request->all(['name', 'wallet_number', 'wallet_name', 'network', 'email', 'commission_percent', 'is_active']), $this->account($request), ['merchant' => (string) $request->get('merchant')], null);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'merchant' => $schema->string()->description('The merchant\'s id or slug.')->required(),
            'name' => $schema->string()->description('The business name.'),
            'wallet_number' => $schema->string()->description('Its settlement wallet.'),
            'wallet_name' => $schema->string()->description('The name on the wallet.'),
            'network' => $schema->string()->description('The wallet\'s network.'),
            'email' => $schema->string()->description('The merchant\'s email.'),
            'commission_percent' => $schema->number()->description('0 to 20.'),
            'is_active' => $schema->boolean()->description('false pauses it.'),
        ];
    }
}
