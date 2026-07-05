<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class FeePolicyController extends Controller
{
    /**
     * Show the account's fee/routing policy setting.
     */
    public function show(Request $request): Response
    {
        return Inertia::render('fee-policy', [
            'policy' => $request->user()->feePolicy(),
        ]);
    }

    /**
     * Update the account's fee/routing policy.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'policy' => ['required', Rule::in(User::FEE_POLICIES)],
        ]);

        $request->user()->forceFill(['fee_policy' => $validated['policy']])->save();

        return back();
    }
}
