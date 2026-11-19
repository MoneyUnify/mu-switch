<?php

namespace App\Mcp\Tools\Operator;

use App\Http\Controllers\SwitchController;
use App\Mcp\Tools\OperatorTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('quote_payment')]
#[Title('Quote a payment')]
#[Description('Quote what collecting a payment would cost with each provider that can take it (fees both ways). Creates nothing.')]
#[IsReadOnly]
class QuotePayment extends OperatorTool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->forward([SwitchController::class, 'quotePayment'], $request->all(['amount', 'country', 'currency', 'allow_fx']), $this->account($request), [], null);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'amount' => $schema->number()->description('The amount, e.g. 2500.')->required(),
            'country' => $schema->string()->description('The country, as a 2-letter ISO code, e.g. ZM.')->required(),
            'currency' => $schema->string()->description('3-letter currency code. Defaults to the country\'s own.'),
            'allow_fx' => $schema->boolean()->description('Allow providers that charge in another currency (e.g. PayPal for an African currency).'),
        ];
    }
}
