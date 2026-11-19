<?php

namespace App\Mcp\Tools\Operator;

use App\Mcp\Tools\OperatorTool;
use App\Support\Treasury\TreasurySnapshot;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('treasury_balances')]
#[Title('Treasury balances')]
#[Description('Each provider account\'s ledger balance and float: minimum and target, transfers in flight, recent payouts, days of payouts covered, and how much it can send or needs.')]
#[IsReadOnly]
class TreasuryBalances extends OperatorTool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $account = $this->account($request);

        if (! $account) {
            return Response::error('Not signed in.');
        }

        return Response::structured(['accounts' => TreasurySnapshot::for($account)->forAgent()]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
