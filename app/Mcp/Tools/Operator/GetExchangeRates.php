<?php

namespace App\Mcp\Tools\Operator;

use App\Http\Controllers\FxController;
use App\Mcp\Tools\OperatorTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_exchange_rates')]
#[Title('Exchange rates')]
#[Description('Rates from a base currency to every supported currency, with rate_source paypal where PayPal FX supplied the rate.')]
#[IsReadOnly]
class GetExchangeRates extends OperatorTool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->forward([FxController::class, 'rates'], $request->all(['base']), $this->account($request), [], null);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'base' => $schema->string()->description('Base currency, default USD.'),
        ];
    }
}
