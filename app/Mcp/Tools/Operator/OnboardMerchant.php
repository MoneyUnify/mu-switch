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

#[Name('onboard_merchant')]
#[Title('Onboard a merchant')]
#[Description('Add a merchant: its customers pay with PayPal and it is settled to its own mobile-money wallet. Its country must be one a payout provider can settle to.')]
class OnboardMerchant extends OperatorTool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->forward([MerchantApiController::class, 'store'], $request->all(['name', 'country', 'wallet_number', 'wallet_name', 'network', 'email', 'commission_percent']), $this->account($request), [], null);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('The business name.')->required(),
            'country' => $schema->string()->description('The country, as a 2-letter ISO code, e.g. ZM.')->required(),
            'wallet_number' => $schema->string()->description('The mobile-money number it is settled to.')->required(),
            'wallet_name' => $schema->string()->description('The name on the wallet.'),
            'network' => $schema->string()->description('The wallet\'s network.'),
            'email' => $schema->string()->description('The merchant\'s email.'),
            'commission_percent' => $schema->number()->description('The operator\'s commission per payment, 0 to 20.'),
        ];
    }
}
