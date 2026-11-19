<?php

namespace App\Mcp\Tools\Operator;

use App\Http\Controllers\PaymentLinkController;
use App\Mcp\Tools\OperatorTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

#[Name('create_pay_link')]
#[Title('Create a pay link')]
#[Description('Create a saved pay link for a merchant, optionally with a fixed price and the currencies customers pay in.')]
class CreatePayLink extends OperatorTool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->forward([PaymentLinkController::class, 'store'], $request->all(['label', 'amount', 'pay_currencies', 'settle_currency', 'reference']), $this->account($request), ['merchant' => (string) $request->get('merchant')], null);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'merchant' => $schema->string()->description('The merchant\'s id or slug.')->required(),
            'label' => $schema->string()->description('What it\'s for, e.g. Handwoven basket.'),
            'amount' => $schema->number()->description('A fixed price in the settlement currency.'),
            'pay_currencies' => $schema->array()->items($schema->string())->description('Currencies customers may pay in, e.g. ["USD","EUR"].'),
            'settle_currency' => $schema->string()->description('What the merchant is settled in.'),
            'reference' => $schema->string()->description('Attached to each payment.'),
        ];
    }
}
