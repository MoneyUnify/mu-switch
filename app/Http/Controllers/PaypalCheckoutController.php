<?php

namespace App\Http\Controllers;

use App\Enums\TransactionStatus;
use App\Http\Controllers\Providers\PaypalController;
use App\Jobs\ReverifyTransaction;
use App\Models\Transaction;
use App\Support\Paypal\PaypalReceipt;
use App\Support\TransactionVerifier;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Uri;

/**
 * Where PayPal sends the payer back after approving or cancelling checkout.
 */
class PaypalCheckoutController extends Controller
{
    /**
     * PayPal appends these to our signed return URLs.
     */
    private const PAYPAL_QUERY_PARAMETERS = ['token', 'PayerID'];

    /**
     * The payer approved: capture the order and hand them back.
     * Settled or already-refunded transactions render instantly from the database
     * while syncing with PayPal asynchronously in the background.
     */
    public function approved(Request $request, Transaction $transaction, TransactionVerifier $verifier): RedirectResponse|View
    {
        $this->authorizeReturn($request, $transaction);

        if (! $transaction->isFinal()) {
            $verifier->verify($transaction);
            $transaction->refresh();
        } else {
            $this->reverifyInBackground($transaction);
        }

        return $this->finish($transaction);
    }

    /**
     * The payer cancelled: fail the transaction unless it was already paid.
     */
    public function cancelled(Request $request, Transaction $transaction, TransactionVerifier $verifier): RedirectResponse|View
    {
        $this->authorizeReturn($request, $transaction);

        if (! $transaction->isFinal()) {
            $verifier->verify($transaction);
            $transaction->refresh();

            if ($transaction->status === TransactionStatus::PENDING) {
                $transaction->update([
                    'status' => TransactionStatus::FAILED,
                    'provider_response' => [...($transaction->provider_response ?? []), 'cancelled_by_payer' => true],
                ]);

                $verifier->notifyIfSettled($transaction);
            }
        }

        return $this->finish($transaction);
    }

    /**
     * Re-verify settled transactions in the background (at most once a minute),
     * so that already-captured or refunded receipts render immediately.
     */
    private function reverifyInBackground(Transaction $transaction): void
    {
        if (! Cache::add("paypal-receipt:{$transaction->id}", true, PaypalReceipt::REFRESH_SECONDS)) {
            return;
        }

        ReverifyTransaction::dispatch($transaction);
    }

    /**
     * Only signed links to PayPal transactions may be used.
     */
    private function authorizeReturn(Request $request, Transaction $transaction): void
    {
        abort_unless($request->hasValidSignatureWhileIgnoring(self::PAYPAL_QUERY_PARAMETERS), 403);
        abort_unless($transaction->paymentProvider?->class === PaypalController::class, 404);
    }

    /**
     * Redirect to the merchant's return URL, or show the outcome ourselves.
     */
    private function finish(Transaction $transaction): RedirectResponse|View
    {
        if ($transaction->return_url) {
            return redirect()->away((string) Uri::of($transaction->return_url)->withQuery([
                'transaction_id' => $transaction->transaction_id,
                'status' => $transaction->status->value,
            ]));
        }

        // Just verified with PayPal on the way here, so no need to ask again.
        return view('checkout.result', ['transaction' => $transaction, 'receipt' => PaypalReceipt::for($transaction, refresh: false)]);
    }

    /**
     * The payment's PayPal-verified receipt, from a permanent signed link.
     */
    public function receipt(Request $request, Transaction $transaction): View
    {
        abort_unless($request->hasValidSignature(), 403);
        abort_unless($transaction->paymentProvider?->class === PaypalController::class, 404);

        return view('checkout.result', ['transaction' => $transaction, 'receipt' => PaypalReceipt::for($transaction)]);
    }
}
