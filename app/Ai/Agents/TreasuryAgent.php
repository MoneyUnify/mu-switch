<?php

namespace App\Ai\Agents;

use App\Ai\Concerns\UsesAccountAiCredentials;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Reads a treasury snapshot and proposes same-currency transfers that top up
 * low floats. It only proposes: every suggestion is checked by
 * RebalanceAdvisor and waits for a person to approve it.
 */
#[MaxTokens(2000)]
#[Temperature(0.2)]
#[Timeout(90)]
class TreasuryAgent implements Agent, HasStructuredOutput
{
    use Promptable;
    use UsesAccountAiCredentials;

    public function instructions(): Stringable|string
    {
        return <<<'TEXT'
        You are the treasury assistant for a payment switch. The operator keeps money
        in several provider accounts (one per provider and currency). Payout accounts
        need a float so recipients are paid instantly. You receive a JSON snapshot of
        every account and available FX rates, and propose transfers that keep floats healthy.

        Rules:
        - Prefer transfers between accounts in the SAME currency when possible.
        - Cross-currency transfers (e.g. from a PayPal account holding USD to an African payout
          account needing ZMW or KES) are supported using the provided exchange rates.
        - When proposing a cross-currency transfer, set "currency" to the source currency (e.g. "USD")
          and "to_currency" to the destination currency (e.g. "ZMW"). If same currency, "to_currency"
          can match "currency" or be omitted.
        - Never send more than the source's "max_can_send". In cross-currency transfers, ensure
          the converted amount (source amount * effective rate) does not exceed the destination's "max_can_receive".
          Prefer whole amounts.
        - Only top up accounts whose "max_can_receive" is above zero. Prioritise
          accounts below their minimum float, then those with the fewest
          "days_of_payouts_covered".
        - Use as few transfers as possible; prefer the source with the most to spare.
        - "reasoning" must cite the numbers you used (balances, floats, FX rates, payout volume).
        - Amounts are decimal strings in the source account's currency, e.g. "250.00".
        - Return an empty "suggestions" list if nothing should move.
        TEXT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'summary' => $schema->string()->description('One or two sentences on the overall position.')->required(),
            'suggestions' => $schema->array()->items($schema->object([
                'from_provider_id' => $schema->integer()->required(),
                'to_provider_id' => $schema->integer()->required(),
                'currency' => $schema->string()->description('The source account currency (e.g. "USD" or "ZMW").')->required(),
                'to_currency' => $schema->string()->description('The destination account currency if cross-currency, else same as currency.')->nullable(),
                'amount' => $schema->string()->description('Decimal amount in source currency, e.g. "250.00".')->required(),
                'reasoning' => $schema->string()->required(),
            ])->withoutAdditionalProperties())->required(),
            'notes' => $schema->array()->items($schema->string())->required(),
        ];
    }
}
