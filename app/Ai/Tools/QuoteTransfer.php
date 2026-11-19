<?php

namespace App\Ai\Tools;

use App\Exceptions\CrossBorderException;
use App\Models\User;
use App\Support\CrossBorder\CrossBorderTransferService;
use App\Support\CrossBorder\TransferIntent;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Price a transfer without starting it. Nothing is created or charged.
 */
class QuoteTransfer implements Tool
{
    public function __construct(private readonly User $user, private readonly ?TransferIntent $intent = null) {}

    public function description(): Stringable|string
    {
        return 'Quote a transfer: what the sender pays with PayPal, what the recipient receives on mobile money, the exchange rate, the margin and the payout route. Free; creates nothing.';
    }

    public function handle(Request $request): Stringable|string
    {
        $input = $request->validate([
            'amount' => 'required|numeric|gt:0',
            'mode' => 'required|in:source,target',
            'country' => 'required|string|size:2',
            'source_currency' => 'nullable|string|size:3',
        ]);

        // Only what the person asked for: never an amount from an earlier transfer.
        if ($reason = $this->intent?->mismatch($input['amount'], $input['mode'], $input['country'], $input['source_currency'] ?? null)) {
            return (string) json_encode(['error' => $reason]);
        }

        try {
            $quote = app(CrossBorderTransferService::class)->quote($this->user, $input)->toArray();
        } catch (CrossBorderException $e) {
            return (string) json_encode(['error' => $e->getMessage()]);
        }

        unset($quote['fx_quote_id']);

        return (string) json_encode($quote);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'amount' => $schema->number()->description('The amount, as a number.')->required(),
            'mode' => $schema->string()->enum(['target', 'source'])->description('"target": the recipient receives exactly this amount in their currency. "source": the sender pays exactly this amount.')->required(),
            'country' => $schema->string()->description('Recipient country, ISO 3166 alpha-2, e.g. "ZM".')->required(),
            'source_currency' => $schema->string()->description('Currency the sender pays in on PayPal, e.g. "USD". Defaults to USD.')->nullable(),
        ];
    }
}
