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

#[Name('lock_fx_quote')]
#[Title('Lock an FX quote')]
#[Description('Convert an amount and lock the rate for 10 minutes. Pass the returned id as fx_quote_id to use it.')]
class LockFxQuote extends OperatorTool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->forward([FxController::class, 'quote'], $request->all(['from_currency', 'to_currency', 'amount', 'mode']), $this->account($request), [], null);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'from_currency' => $schema->string()->description('e.g. USD.')->required(),
            'to_currency' => $schema->string()->description('e.g. ZMW.')->required(),
            'amount' => $schema->number()->description('The amount, e.g. 2500.')->required(),
            'mode' => $schema->string()->description('source (default): amount is in from_currency. target: amount must arrive in to_currency.')->enum(['source', 'target']),
        ];
    }
}
