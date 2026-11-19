<?php

namespace App\Http\Controllers;

use App\Models\TreasuryTransfer;
use App\Support\Ai\AiProviderChain;
use App\Support\Treasury\RebalanceAdvisor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Throwable;

/**
 * Asks the treasury agent to propose rebalancing transfers. Proposals are
 * recorded as requested transfers for a person to approve or dismiss.
 */
class RebalanceSuggestionController extends Controller
{
    public function __construct(private readonly RebalanceAdvisor $advisor) {}

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! AiProviderChain::for($user)->canRun()) {
            return $this->done(__('Add an AI provider on the AI & Agents page to get suggestions.'), 'error');
        }

        try {
            $result = $this->advisor->suggest($user);
        } catch (Throwable $e) {
            report($e);

            return $this->done(__('The treasury agent could not respond: :reason', ['reason' => Str::limit($e->getMessage(), 160)]), 'error');
        }

        $count = count($result['created']);

        Inertia::flash('rebalance', [
            'summary' => $result['summary'],
            'notes' => $result['notes'],
            'rejected' => $result['rejected'],
            'suggested' => array_map(fn (TreasuryTransfer $transfer): string => $transfer->reference, $result['created']),
            'askedAgent' => $result['askedAgent'],
        ]);

        return $this->done(match (true) {
            ! $result['askedAgent'] => __('All floats are healthy. Nothing to suggest.'),
            $count === 0 => __('The treasury agent suggested no transfers.'),
            default => trans_choice('{1} 1 transfer suggested. Review it below.|[2,*] :count transfers suggested. Review them below.', $count, ['count' => $count]),
        });
    }

    private function done(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back(fallback: route('treasury.index'));
    }
}
