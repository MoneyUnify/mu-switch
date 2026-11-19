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

#[Name('merchant_profile')]
#[Title('My merchant account')]
#[Description('Your merchant account: name, country, settlement wallet, currency, commission and whether you are taking payments.')]
#[IsReadOnly]
class MerchantProfile extends MerchantTool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->forMerchant([MerchantApiController::class, 'show'], [], [], null);
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
