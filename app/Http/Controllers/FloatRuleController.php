<?php

namespace App\Http\Controllers;

use App\Models\FloatRule;
use App\Support\Currency;
use App\Support\Fees\FeeCalculator;
use App\Support\Treasury\FloatMonitor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Float rules: "keep at least this much in this provider account".
 */
class FloatRuleController extends Controller
{
    /**
     * Create or update the rule for a provider account, then re-check it.
     */
    public function upsert(Request $request, FloatMonitor $monitor): RedirectResponse
    {
        $validated = $request->validate([
            'provider_id' => ['required', 'integer', Rule::exists('payment_providers', 'id')->where('user_id', $request->user()->id)],
            'currency' => 'required|string|size:3|alpha',
            'minimum' => 'required|numeric|min:0',
            'target' => 'nullable|numeric|gte:minimum',
            'is_active' => 'boolean',
        ]);

        $currency = strtoupper($validated['currency']);

        $rule = FloatRule::updateOrCreate(
            ['payment_provider_id' => $validated['provider_id'], 'currency' => $currency],
            [
                'user_id' => $request->user()->id,
                'minimum_minor' => Currency::toMinorUnits(FeeCalculator::money((string) $validated['minimum']), $currency),
                'target_minor' => isset($validated['target']) ? Currency::toMinorUnits(FeeCalculator::money((string) $validated['target']), $currency) : null,
                'is_active' => $validated['is_active'] ?? true,
            ],
        );

        $monitor->check($rule->load('paymentProvider'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Float rule saved.')]);

        return back(fallback: route('treasury.index'));
    }

    /**
     * Remove a rule and its alerts.
     */
    public function destroy(Request $request, FloatRule $floatRule): RedirectResponse
    {
        abort_unless($floatRule->user_id === $request->user()->id, 403);

        $floatRule->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Float rule removed.')]);

        return back(fallback: route('treasury.index'));
    }
}
