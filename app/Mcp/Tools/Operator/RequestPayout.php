<?php

namespace App\Mcp\Tools\Operator;

use App\Http\Controllers\PayoutController;
use App\Mcp\Tools\OperatorTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[Name('request_payout')]
#[Title('Send a payout')]
#[Description('Pay money out from the operator\'s float to a mobile-money account. Falls back to the next payout provider only after a definite refusal, so it never pays twice.')]
#[IsDestructive]
#[IsOpenWorld]
class RequestPayout extends OperatorTool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->forward([PayoutController::class, 'requestPayout'], $request->all(['amount', 'account_number', 'country', 'currency', 'network', 'narration']), $this->account($request), [], $request->get('idempotency_key'));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'amount' => $schema->number()->description('The amount, e.g. 2500.')->required(),
            'account_number' => $schema->string()->description('The recipient\'s mobile number.')->required(),
            'country' => $schema->string()->description('The country, as a 2-letter ISO code, e.g. ZM.')->required(),
            'currency' => $schema->string()->description('3-letter currency code. Defaults to the country\'s own.'),
            'network' => $schema->string()->description('The recipient\'s network, when it can\'t be detected.'),
            'narration' => $schema->string()->description('Shown to the recipient where supported.'),
            'idempotency_key' => $schema->string()->description('Any unique string. Retrying with the same key returns the first result instead of acting twice.'),
        ];
    }
}
