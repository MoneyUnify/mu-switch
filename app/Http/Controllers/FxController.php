<?php

namespace App\Http\Controllers;

use App\ApiResponse;
use App\Support\Fx\Exceptions\UnsupportedCurrencyPairException;
use App\Support\Fx\FxQuoter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FxController extends Controller
{
    /**
     * Generate a locked 10-minute FX quote.
     */
    public function quote(Request $request, FxQuoter $quoter): JsonResponse
    {
        $validated = $request->validate([
            'from_currency' => 'required|string|size:3|alpha',
            'to_currency' => 'required|string|size:3|alpha',
            'amount' => 'required|numeric|gt:0',
            'mode' => 'nullable|string|in:source,target',
        ]);

        $mode = $validated['mode'] ?? 'source';
        $from = strtoupper($validated['from_currency']);
        $to = strtoupper($validated['to_currency']);

        try {
            $quote = $mode === 'target'
                ? $quoter->quoteTarget($from, $to, (string) $validated['amount'], ownerId: $request->user()->id)
                : $quoter->quote($from, $to, (string) $validated['amount'], ownerId: $request->user()->id);
        } catch (UnsupportedCurrencyPairException $e) {
            return ApiResponse::error($e->getMessage(), 400);
        }

        return ApiResponse::success('FX quote generated', $quote->toArray());
    }

    /**
     * Get the mid-market and effective rate matrix against a base currency.
     */
    public function rates(Request $request, FxQuoter $quoter): JsonResponse
    {
        $validated = $request->validate([
            'base' => 'nullable|string|size:3|alpha',
        ]);

        $base = strtoupper($validated['base'] ?? 'USD');
        $matrix = $quoter->matrix($base, $request->user()->id);
        $source = $quoter->rateSourceFor($request->user()->id);

        return ApiResponse::success('Exchange rates', [
            'base_currency' => $base,
            'rate_source' => $source['source'],
            'paypal_fx' => ['state' => $source['state'], 'activation_url' => $source['activation_url']],
            'rates' => $matrix,
        ]);
    }

    /**
     * Look up one of the account's quotes by ID to check its status or remaining time.
     */
    public function show(Request $request, string $id, FxQuoter $quoter): JsonResponse
    {
        $quote = $quoter->findQuote($id, $request->user()->id);

        if ($quote === null) {
            return ApiResponse::error('FX quote not found or expired', 404);
        }

        return ApiResponse::success('FX quote', $quote->toArray());
    }
}
