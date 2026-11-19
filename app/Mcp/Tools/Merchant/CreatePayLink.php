<?php

namespace App\Mcp\Tools\Merchant;

use App\Http\Controllers\PaymentLinkController;
use App\Mcp\Tools\MerchantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

#[Name('create_pay_link')]
#[Title('Create a pay link')]
#[Description('Create a saved pay link, optionally with a fixed price and the currencies customers pay in.')]
class CreatePayLink extends MerchantTool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->forMerchant([PaymentLinkController::class, 'store'], $request->all(['label', 'amount', 'pay_currencies', 'settle_currency', 'reference']), [], null);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'label' => $schema->string()->description('What it\'s for.'),
            'amount' => $schema->number()->description('A fixed price in your settlement currency.'),
            'pay_currencies' => $schema->array()->items($schema->string())->description('Currencies customers may pay in, e.g. ["USD","EUR"].'),
            'settle_currency' => $schema->string()->description('What you are settled in.'),
            'reference' => $schema->string()->description('Attached to each payment.'),
        ];
    }
}
