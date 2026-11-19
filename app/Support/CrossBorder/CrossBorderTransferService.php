<?php

namespace App\Support\CrossBorder;

use App\Contracts\PaymentProviderInterface;
use App\Contracts\PayoutProviderInterface;
use App\Contracts\RefundsPayments;
use App\Enums\CrossBorderStatus;
use App\Enums\TransactionStatus;
use App\Exceptions\CrossBorderException;
use App\Exceptions\NoEligibleProviderException;
use App\Jobs\DisburseCrossBorderTransfer;
use App\Jobs\RefundCrossBorderTransfer;
use App\Models\ApiLog;
use App\Models\CrossBorderTransfer;
use App\Models\Merchant;
use App\Models\PaymentLink;
use App\Models\PaymentProvider;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Currency;
use App\Support\Fx\Exceptions\FxException;
use App\Support\Fx\FxQuote;
use App\Support\Fx\FxQuoter;
use App\Support\Market;
use App\Support\Notifications\PayerNotifier;
use App\Support\Payouts\PayoutDispatcher;
use App\Support\ProviderCallContext;
use App\Support\ProviderFees;
use App\Support\ProviderRouter;
use App\Support\RequestContext;
use App\Support\TransactionVerifier;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends money across borders using the operator's own accounts: the sender
 * pays through PayPal, and once that payment is captured the switch pays the
 * recipient's mobile wallet from a payout account, in the local currency at
 * the locked FX rate. If the payout can't be delivered, the PayPal payment is
 * refunded.
 *
 * awaiting_payment → funded → paying_out → delivered
 *                  ↘ payment_failed       ↘ payout_failed → refunding → refunded (or refund_failed)
 */
class CrossBorderTransferService
{
    /** How a reversal note on a transfer's timeline starts. */
    private const REVERSAL_NOTE = 'PayPal reversed the sender\'s payment';

    public function __construct(
        private readonly FxQuoter $fx,
        private readonly PayoutDispatcher $payouts,
        private readonly TransactionVerifier $verifier,
        private readonly TransferLimits $limits,
    ) {}

    /**
     * Price a transfer without starting it: FX, routes and estimated fees.
     *
     * @param  array{amount: string, mode?: ?string, source_currency?: ?string, country: string, currency?: ?string, fx_quote_id?: ?string}  $input
     *
     * @throws CrossBorderException
     */
    public function quote(User $user, array $input): CrossBorderQuote
    {
        $country = strtoupper($input['country']);
        $target = Currency::resolve($input['currency'] ?? null, $country);
        $source = strtoupper($input['source_currency'] ?? '') ?: (string) config('moneyunify_paypal.fx.base_currency', 'USD');

        $fx = $this->lockFx($user, $input, $source, $target);

        try {
            $funding = ProviderRouter::route($user, $country, $source, $fx->sourceAmount->toFloat(), RefundsPayments::class)->first();
        } catch (NoEligibleProviderException) {
            throw new CrossBorderException("No active PayPal account can collect {$source}. Add or activate one on the Providers page.", 400);
        }

        try {
            $payoutRoute = ProviderRouter::route($user, $country, $target, $fx->targetAmount->toFloat(), PayoutProviderInterface::class);
        } catch (NoEligibleProviderException) {
            throw new CrossBorderException("No active payout provider can pay {$target} in ".Market::name($country).'.', 400);
        }

        return new CrossBorderQuote(
            fx: $fx,
            funding: $funding,
            payoutRoute: $payoutRoute,
            country: $country,
            fundingFee: $this->fee($funding, $fx->sourceAmount, $source, 'collection'),
            payoutFee: $this->fee($payoutRoute->first(), $fx->targetAmount, $target, 'payout'),
        );
    }

    /**
     * Start a transfer: lock the quote, then open a PayPal checkout for the
     * sender. Nothing is paid out until PayPal confirms the payment.
     *
     * @param  array{amount: string, mode?: ?string, source_currency?: ?string, country: string, currency?: ?string, fx_quote_id?: ?string, account_number: string, recipient_name?: ?string, recipient_email?: ?string, network?: ?string, narration?: ?string, payer_email?: ?string, pay_with?: ?string, return_url?: ?string}  $input
     * @return array{0: CrossBorderTransfer, 1: ?string} the transfer and the PayPal checkout link
     *
     * @throws CrossBorderException
     */
    public function request(User $user, array $input, ?string $idempotencyKey = null, string $origin = 'api', ?string $conversationId = null): array
    {
        $quote = $this->quote($user, $input);
        $this->limits->check($user, $quote->fx);

        $transfer = $this->create($user, $quote, $input, $idempotencyKey, $origin, $conversationId);
        $checkoutUrl = $this->openCheckout($transfer, $quote, $input);

        return [$transfer->refresh(), $checkoutUrl];
    }

    /**
     * Price a payment to a merchant: what the customer pays with PayPal, the
     * aggregator's commission, and what the merchant receives in its wallet.
     *
     * @param  array{amount: string, mode?: ?string, source_currency?: ?string}  $input  target mode: the amount is the merchant's price, in its currency
     * @return array{quote: CrossBorderQuote, commission_minor: int, net_minor: int}
     *
     * @throws CrossBorderException
     */
    public function quoteForMerchant(Merchant $merchant, array $input): array
    {
        if (! $merchant->is_active) {
            throw new CrossBorderException('This merchant is not accepting payments.', 404);
        }

        $quote = $this->quote($merchant->user, [
            'amount' => $input['amount'],
            'mode' => $input['mode'] ?? 'target',
            'source_currency' => $input['source_currency'] ?? null,
            'country' => $merchant->country,
            'currency' => $input['settle_currency'] ?? $merchant->currency,
        ]);

        $gross = Currency::toMinorUnits($quote->fx->targetAmount, $quote->fx->targetCurrency);
        // Rounded down for the aggregator, so the merchant is never short-changed by rounding.
        $commission = BigDecimal::of($gross)
            ->multipliedBy((string) $merchant->commission_percent)
            ->dividedBy(100, 0, RoundingMode::Down)
            ->toInt();

        return ['quote' => $quote, 'commission_minor' => $commission, 'net_minor' => $gross - $commission];
    }

    /**
     * Collect a payment for a merchant through PayPal and settle it to the
     * merchant's mobile-money wallet once PayPal confirms it.
     *
     * @param  array{amount: string, mode?: ?string, source_currency?: ?string, payer_email?: ?string, pay_with?: ?string, checkout?: ?string, narration?: ?string, return_url?: ?string}  $input
     * @param  (callable(CrossBorderTransfer): string)|null  $returnUrl  where PayPal sends the customer back, built from the new transfer
     * @return array{0: CrossBorderTransfer, 1: ?string}
     *
     * @throws CrossBorderException
     */
    public function collectForMerchant(Merchant $merchant, array $input, string $origin = 'pay_link', ?callable $returnUrl = null, ?string $idempotencyKey = null, ?PaymentLink $link = null): array
    {
        ['quote' => $quote, 'commission_minor' => $commission, 'net_minor' => $net] = $this->quoteForMerchant($merchant, $input);

        if ($net <= 0) {
            throw new CrossBorderException('The amount is too small to settle after commission.');
        }

        // Customers aren't the account holder, so only the per-payment cap applies.
        $this->limits->check($merchant->user, $quote->fx, daily: false);

        $transfer = $this->create($merchant->user, $quote, [
            'account_number' => $merchant->wallet_number,
            'recipient_name' => $merchant->wallet_name ?? $merchant->name,
            'network' => $merchant->network,
            'narration' => $input['narration'] ?? "Payment via {$merchant->name}",
            'payer_email' => $input['payer_email'] ?? null,
        ], $idempotencyKey, $origin, null, [
            'merchant_id' => $merchant->id,
            'payment_link_id' => $link?->id,
            'commission_minor' => $commission,
            'target_amount_minor' => $net,
        ]);

        $checkoutUrl = $this->openCheckout($transfer, $quote, [
            'brand_name' => $merchant->name,
            'payer_email' => $input['payer_email'] ?? null,
            'pay_with' => $input['pay_with'] ?? null,
            'checkout' => $input['checkout'] ?? null,
            'return_url' => $returnUrl ? $returnUrl($transfer) : ($input['return_url'] ?? null),
        ]);

        return [$transfer->refresh(), $checkoutUrl];
    }

    /**
     * React to a leg of a transfer changing state. Called whenever a
     * transaction is saved.
     */
    public function transactionChanged(Transaction $transaction): void
    {
        if (! $transaction->wasChanged('status') && ! $transaction->wasRecentlyCreated) {
            return;
        }

        $transfer = CrossBorderTransfer::query()
            ->where('funding_transaction_id', $transaction->id)
            ->orWhere('payout_transaction_id', $transaction->id)
            ->orWhere('refund_transaction_id', $transaction->id)
            ->first();

        if ($transfer) {
            $this->advance($transfer, $transaction);
        }
    }

    /**
     * PayPal reversed the sender's captured payment (for example a chargeback
     * or bank reversal). The payout may already be made, so the transfer keeps
     * its status and gets a note on its timeline for someone to follow up.
     * Noted once, however often PayPal reports it.
     */
    public function paymentReversed(Transaction $payment, string $reversalId = ''): void
    {
        $transfer = CrossBorderTransfer::query()->where('funding_transaction_id', $payment->id)->first();

        if (! $transfer || $transfer->events()->where('note', 'like', self::REVERSAL_NOTE.'%')->exists()) {
            return;
        }

        $note = self::REVERSAL_NOTE.' (chargeback or bank reversal)'
            .($reversalId !== '' ? ", PayPal reference {$reversalId}" : '')
            .'. Follow up with the sender: the recipient may already have been paid.';

        $transfer->events()->create([
            'from_status' => $transfer->status,
            'to_status' => $transfer->status,
            'note' => $note,
            'created_at' => now(),
        ]);
    }

    /**
     * Pay the recipient once the sender's PayPal payment is captured.
     */
    public function disburse(CrossBorderTransfer $transfer): void
    {
        // Claim the transfer under a lock, so it can only ever be paid out once.
        if (! $this->transition($transfer, CrossBorderStatus::FUNDED, CrossBorderStatus::PAYING_OUT, 'Sending to the recipient')) {
            return;
        }

        $ownsContext = false;
        if (! RequestContext::id()) {
            $ownsContext = true;
            $requestId = (string) Str::uuid();
            RequestContext::set($requestId);
            $startedAt = microtime(true);
        } else {
            $requestId = RequestContext::id();
        }

        try {
            $amount = (string) $transfer->targetAmount();

            try {
                $route = ProviderRouter::route($transfer->user, $transfer->recipient_country, $transfer->target_currency, $transfer->targetAmount()->toFloat(), PayoutProviderInterface::class);
            } catch (NoEligibleProviderException $e) {
                $this->payoutFailed($transfer, $e->getMessage());

                return;
            }

            $result = $this->payouts->send($route, [
                'account_number' => $transfer->recipient_account,
                'network' => $transfer->recipient_network,
                'narration' => $transfer->narration,
            ], $amount, $transfer->target_currency, $transfer->recipient_country, attributes: [
                'is_fx' => $transfer->source_currency !== $transfer->target_currency,
                'fx_rate' => $transfer->source_currency !== $transfer->target_currency ? (string) $transfer->fx_rate : null,
            ]);

            // Accepted, unknown or refused: link the payout transaction and provider so the outcome is traceable.
            if ($result->transaction) {
                $transfer->update([
                    'payout_transaction_id' => $result->transaction->id,
                    'payout_provider_id' => $result->transaction->payment_provider_id,
                ]);
            }

            if ($result->refused()) {
                $data = $result->response->getData(true);
                $message = is_array($data) ? ($data['message'] ?? $data['error'] ?? 'no reason given') : 'no reason given';
                $this->payoutFailed($transfer, 'Every payout provider refused the payout: '.$message);

                return;
            }

            if ($result->transaction) {
                $this->advance($transfer->refresh(), $result->transaction->refresh());
            }
        } finally {
            if ($ownsContext) {
                try {
                    $transfer->refresh();
                    $status = $transfer->status;
                    $httpStatus = match ($status) {
                        CrossBorderStatus::DELIVERED => 200,
                        CrossBorderStatus::PAYOUT_FAILED => 422,
                        default => 202,
                    };

                    ApiLog::create([
                        'user_id' => $transfer->user_id,
                        'request_id' => $requestId,
                        'method' => 'POST',
                        'url' => url("/transfers/{$transfer->reference}/disburse"),
                        'route' => 'transfers.disburse',
                        'response_status' => $httpStatus,
                        'response_body' => json_encode([
                            'reference' => $transfer->reference,
                            'status' => $status->value,
                            'failure_reason' => $transfer->failure_reason,
                            'target_amount' => (string) $transfer->targetAmount(),
                            'target_currency' => $transfer->target_currency,
                            'payout_provider' => $transfer->payoutProvider?->name,
                            'payout_transaction_id' => $transfer->payoutTransaction?->transaction_id,
                            'payout_provider_reference' => $transfer->payoutTransaction?->provider_transaction_id,
                            'provider_response' => $transfer->payoutTransaction?->provider_response,
                        ]),
                        'duration_ms' => isset($startedAt) ? (int) round((microtime(true) - $startedAt) * 1000) : 0,
                    ]);
                } catch (Throwable) {
                    // Logging failures must never break the transfer
                }
                RequestContext::clear();
            }
        }
    }

    /**
     * Return the sender's PayPal payment when the recipient couldn't be paid.
     */
    public function refund(CrossBorderTransfer $transfer): void
    {
        $transfer->refresh();

        if (! in_array($transfer->status, [CrossBorderStatus::PAYOUT_FAILED, CrossBorderStatus::REFUND_FAILED], true)) {
            return;
        }

        $payment = $transfer->fundingTransaction;
        $provider = $transfer->fundingProvider;
        $driver = $provider ? app($provider->class) : null;

        if (! $payment || ! $driver instanceof RefundsPayments || ! $driver instanceof PaymentProviderInterface) {
            $this->transition($transfer, $transfer->status, CrossBorderStatus::REFUND_FAILED, 'The PayPal account can no longer refund this payment');

            return;
        }

        if (! $this->transition($transfer, $transfer->status, CrossBorderStatus::REFUNDING, 'Refunding the sender through PayPal')) {
            return;
        }

        $ownsContext = false;
        if (! RequestContext::id()) {
            $ownsContext = true;
            $requestId = (string) Str::uuid();
            RequestContext::set($requestId);
            $startedAt = microtime(true);
        } else {
            $requestId = RequestContext::id();
        }

        // The same refund record is reused on a retry, so PayPal never refunds twice.
        $refund = $transfer->refundTransaction ?? $this->newRefund($transfer, $payment);
        $transfer->update(['refund_transaction_id' => $refund->id]);

        ProviderCallContext::set($provider);

        try {
            if ($configError = $driver->setProvider($provider)) {
                $refund->update(['status' => TransactionStatus::FAILED]);
            } else {
                $driver->refundPayment($payment, $refund, 'Refund: the transfer could not be delivered');
            }

            $this->advance($transfer->refresh(), $refund->refresh());
        } catch (Throwable $e) {
            report($e);
        } finally {
            ProviderCallContext::clear();

            if ($ownsContext) {
                try {
                    $transfer->refresh();
                    $refund->refresh();
                    $status = $transfer->status;
                    $httpStatus = match ($status) {
                        CrossBorderStatus::REFUNDED => 200,
                        CrossBorderStatus::REFUND_FAILED => 502,
                        default => 202,
                    };

                    ApiLog::create([
                        'user_id' => $transfer->user_id,
                        'request_id' => $requestId,
                        'method' => 'POST',
                        'url' => url("/transfers/{$transfer->reference}/refund"),
                        'route' => 'transfers.refund',
                        'response_status' => $httpStatus,
                        'response_body' => json_encode([
                            'reference' => $transfer->reference,
                            'status' => $status->value,
                            'failure_reason' => $transfer->failure_reason,
                            'source_amount' => (string) $transfer->sourceAmount(),
                            'source_currency' => $transfer->source_currency,
                            'refund_transaction_id' => $refund->transaction_id,
                            'refund_provider_reference' => $refund->provider_transaction_id,
                            'provider_response' => $refund->provider_response,
                        ]),
                        'duration_ms' => isset($startedAt) ? (int) round((microtime(true) - $startedAt) * 1000) : 0,
                    ]);
                } catch (Throwable) {
                    // Logging failures must never break the transfer
                }
                RequestContext::clear();
            }
        }
    }

    /**
     * Re-check whichever leg is in flight, for the verify API.
     */
    public function refresh(CrossBorderTransfer $transfer): CrossBorderTransfer
    {
        match ($transfer->status) {
            CrossBorderStatus::AWAITING_PAYMENT => $transfer->fundingTransaction && $this->verifier->verify($transfer->fundingTransaction),
            CrossBorderStatus::FUNDED => DisburseCrossBorderTransfer::dispatch($transfer->id),
            CrossBorderStatus::PAYING_OUT => $transfer->payoutTransaction && $this->verifier->verify($transfer->payoutTransaction),
            CrossBorderStatus::PAYOUT_FAILED, CrossBorderStatus::REFUND_FAILED => RefundCrossBorderTransfer::dispatch($transfer->id),
            CrossBorderStatus::REFUNDING => $this->verifyRefund($transfer),
            default => null,
        };

        return $transfer->refresh();
    }

    /**
     * Move the transfer forward from a leg's new state.
     */
    private function advance(CrossBorderTransfer $transfer, Transaction $leg): void
    {
        $status = $leg->status;

        if ($leg->id === $transfer->funding_transaction_id) {
            if ($status === TransactionStatus::SUCCESS && $this->transition($transfer, CrossBorderStatus::AWAITING_PAYMENT, CrossBorderStatus::FUNDED, 'PayPal payment captured', ['funded_at' => now()])) {
                DisburseCrossBorderTransfer::dispatch($transfer->id)->afterCommit();
            } elseif ($status === TransactionStatus::FAILED) {
                $this->transition($transfer, CrossBorderStatus::AWAITING_PAYMENT, CrossBorderStatus::PAYMENT_FAILED, 'The PayPal payment was not completed', ['failure_reason' => 'The PayPal payment was not completed']);
            }

            return;
        }

        if ($leg->id === $transfer->payout_transaction_id) {
            if ($status === TransactionStatus::SUCCESS) {
                $this->transition($transfer, CrossBorderStatus::PAYING_OUT, CrossBorderStatus::DELIVERED, 'Delivered to the recipient', ['delivered_at' => now()]);
            } elseif ($status === TransactionStatus::FAILED) {
                $error = data_get($leg->provider_response, 'message')
                    ?? data_get($leg->provider_response, 'error')
                    ?? data_get($leg->provider_response, 'rejectionReason')
                    ?? $leg->failure_reason;
                $reason = is_string($error) ? 'The payout provider reported the payout as failed: '.$error : 'The payout provider reported the payout as failed';
                $this->payoutFailed($transfer, $reason);
            }

            return;
        }

        if ($leg->id === $transfer->refund_transaction_id) {
            if ($status === TransactionStatus::SUCCESS) {
                $this->transition($transfer, CrossBorderStatus::REFUNDING, CrossBorderStatus::REFUNDED, 'Refunded to the sender', ['refunded_at' => now()]);
            } elseif ($status === TransactionStatus::FAILED) {
                $error = data_get($leg->provider_response, 'error.message')
                    ?? data_get($leg->provider_response, 'error')
                    ?? $leg->failure_reason
                    ?? 'PayPal refused the refund';
                $reason = is_string($error) ? 'PayPal refused the refund: '.$error : 'PayPal refused the refund';
                $this->transition($transfer, CrossBorderStatus::REFUNDING, CrossBorderStatus::REFUND_FAILED, $reason, ['failure_reason' => $reason]);
            }
        }
    }

    /**
     * The payout didn't happen: record why and refund the sender.
     */
    private function payoutFailed(CrossBorderTransfer $transfer, string $reason): void
    {
        $from = $transfer->refresh()->status;

        if ($this->transition($transfer, $from, CrossBorderStatus::PAYOUT_FAILED, $reason, ['failure_reason' => $reason])) {
            RefundCrossBorderTransfer::dispatch($transfer->id)->afterCommit();
        }
    }

    /**
     * Move from one status to another if the transfer is still in the first,
     * recording the step. Returns false if another process got there first.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function transition(CrossBorderTransfer $transfer, CrossBorderStatus $from, CrossBorderStatus $to, ?string $note = null, array $attributes = []): bool
    {
        $moved = DB::transaction(function () use ($transfer, $from, $to, $note, $attributes): bool {
            $current = CrossBorderTransfer::query()->lockForUpdate()->find($transfer->id);

            if (! $current || $current->status !== $from || ! $from->canTransitionTo($to)) {
                return false;
            }

            $current->update([...$attributes, 'status' => $to]);
            $current->events()->create(['from_status' => $from, 'to_status' => $to, 'note' => $note, 'created_at' => now()]);

            return true;
        });

        $transfer->refresh();

        if ($moved && $to === CrossBorderStatus::DELIVERED) {
            app(PayerNotifier::class)->transferDelivered($transfer);
        }

        return $moved;
    }

    /**
     * The quote to use: the caller's own locked quote, or a new one.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws CrossBorderException
     */
    private function lockFx(User $user, array $input, string $source, string $target): FxQuote
    {
        try {
            if (! empty($input['fx_quote_id'])) {
                $quote = $this->fx->getValidQuote((string) $input['fx_quote_id'], $user->id);

                if ($quote->sourceCurrency !== $source || $quote->targetCurrency !== $target) {
                    throw new CrossBorderException("The FX quote is for {$quote->sourceCurrency} to {$quote->targetCurrency}, not {$source} to {$target}.");
                }

                return $quote;
            }

            // By default the recipient gets exactly the amount asked for.
            return ($input['mode'] ?? 'target') === 'source'
                ? $this->fx->quote($source, $target, (string) $input['amount'], ownerId: $user->id)
                : $this->fx->quoteTarget($source, $target, (string) $input['amount'], ownerId: $user->id);
        } catch (FxException $e) {
            throw new CrossBorderException($e->getMessage(), 400);
        }
    }

    /**
     * Record the transfer before the sender is sent to PayPal.
     *
     * @param  array<string, mixed>  $input
     */
    private function create(User $user, CrossBorderQuote $quote, array $input, ?string $idempotencyKey, string $origin, ?string $conversationId, array $overrides = []): CrossBorderTransfer
    {
        $fx = $quote->fx;

        return DB::transaction(function () use ($user, $quote, $fx, $input, $idempotencyKey, $origin, $conversationId, $overrides): CrossBorderTransfer {
            $transfer = CrossBorderTransfer::create([
                'reference' => (string) Str::uuid(),
                'user_id' => $user->id,
                'idempotency_key' => $idempotencyKey,
                'origin' => $origin,
                'conversation_id' => $conversationId,
                'funding_provider_id' => $quote->funding->id,
                'source_currency' => $fx->sourceCurrency,
                'source_amount_minor' => Currency::toMinorUnits($fx->sourceAmount, $fx->sourceCurrency),
                'target_currency' => $fx->targetCurrency,
                'target_amount_minor' => Currency::toMinorUnits($fx->targetAmount, $fx->targetCurrency),
                'recipient_country' => $quote->country,
                'recipient_account' => Market::msisdn((string) $input['account_number'], $quote->country),
                'recipient_name' => $input['recipient_name'] ?? null,
                'recipient_email' => $input['recipient_email'] ?? null,
                'recipient_network' => $input['network'] ?? null,
                'narration' => $input['narration'] ?? null,
                'fx_quote_id' => $fx->id,
                'fx_base_rate' => (string) $fx->baseRate,
                'fx_rate' => (string) $fx->effectiveRate,
                'fx_margin_percent' => (string) $fx->marginPercent,
                'fx_margin_minor' => Currency::toMinorUnits($fx->marginAmount, $fx->sourceCurrency),
                'fx_rate_source' => $fx->isFx() ? $fx->rateSource : null,
                'payer_email' => $input['payer_email'] ?? null,
                'status' => CrossBorderStatus::AWAITING_PAYMENT,
                ...$overrides,
            ]);

            $transfer->events()->create(['from_status' => null, 'to_status' => CrossBorderStatus::AWAITING_PAYMENT, 'note' => 'Quoted at '.$fx->summary(), 'created_at' => now()]);

            return $transfer;
        });
    }

    /**
     * Create the sender's PayPal order and link it as the funding leg.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws CrossBorderException
     */
    private function openCheckout(CrossBorderTransfer $transfer, CrossBorderQuote $quote, array $input): ?string
    {
        $driver = app($quote->funding->class);
        $payer = $input['payer_email'] ?? null;

        $payment = Request::create('/', 'POST', array_filter([
            'amount' => (string) $quote->fx->sourceAmount,
            'currency' => $quote->fx->sourceCurrency,
            'country' => $quote->country,
            'account_number' => $payer ?: 'transfer-'.$transfer->reference,
            'email' => $payer,
            'return_url' => $input['return_url'] ?? null,
            'brand_name' => $input['brand_name'] ?? null,
            'pay_with' => $input['pay_with'] ?? null,
            'checkout' => $input['checkout'] ?? null,
        ]));
        $payment->setUserResolver(fn () => $transfer->user);

        ProviderCallContext::set($quote->funding);

        try {
            if (! $driver instanceof PaymentProviderInterface || ($error = $driver->setProvider($quote->funding))) {
                $response = $error ?? null;
            } else {
                $response = $driver->requestPayment($payment);
            }
        } catch (Throwable $e) {
            report($e);
            $response = null;
        } finally {
            ProviderCallContext::clear();
        }

        $data = $response?->getData(true) ?? [];
        $funding = isset($data['data']['transaction_id'])
            ? Transaction::query()->where('transaction_id', $data['data']['transaction_id'])->first()
            : null;

        if (! $response || $response->getStatusCode() >= 300 || ! $funding) {
            $reason = $data['message'] ?? 'PayPal could not start the payment';
            $this->transition($transfer, CrossBorderStatus::AWAITING_PAYMENT, CrossBorderStatus::PAYMENT_FAILED, $reason, ['failure_reason' => $reason]);

            throw new CrossBorderException('PayPal could not start the payment: '.$reason, 502);
        }

        // The funding leg carries the FX it was priced at.
        $funding->update([
            'is_fx' => $transfer->source_currency !== $transfer->target_currency,
            'fx_rate' => $transfer->source_currency !== $transfer->target_currency ? (string) $transfer->fx_rate : null,
        ]);
        $transfer->update(['funding_transaction_id' => $funding->id]);

        return $data['data']['checkout_url'] ?? null;
    }

    /**
     * The refund record: a debit on the PayPal account for the full payment.
     */
    private function newRefund(CrossBorderTransfer $transfer, Transaction $payment): Transaction
    {
        $id = (string) Str::uuid();

        $refund = new Transaction([
            'transaction_id' => $id,
            'provider_transaction_id' => $id,
            'customer_id' => $payment->customer_id,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'country' => $payment->country,
            'status' => TransactionStatus::PENDING,
            'direction' => 'debit',
            'refund_of_id' => $payment->id,
        ]);
        $refund->paymentProvider()->associate($transfer->fundingProvider);
        $refund->save();

        return $refund;
    }

    /**
     * Re-check a refund that PayPal is still processing.
     */
    private function verifyRefund(CrossBorderTransfer $transfer): void
    {
        $refund = $transfer->refundTransaction;
        $provider = $transfer->fundingProvider;
        $driver = $provider ? app($provider->class) : null;

        if (! $refund || ! $driver instanceof RefundsPayments || ! $driver instanceof PaymentProviderInterface || $driver->setProvider($provider)) {
            return;
        }

        ProviderCallContext::set($provider);

        try {
            $driver->verifyRefund($refund);
        } finally {
            ProviderCallContext::clear();
        }

        $this->advance($transfer->refresh(), $refund->refresh());
    }

    /**
     * A provider's estimated fee for an amount, from its fee schedule.
     */
    private function fee(?PaymentProvider $provider, BigDecimal $amount, string $currency, string $kind): ?string
    {
        $schedule = $provider ? ProviderFees::scheduleFor($provider) : null;

        if (! $schedule) {
            return null;
        }

        $fee = $kind === 'payout'
            ? ProviderFees::payoutFee($schedule, $amount->toFloat(), $currency)
            : ProviderFees::collectionFee($schedule, $amount->toFloat(), $currency);

        return $fee === null ? null : (string) Currency::fromMinorUnits(Currency::toMinorUnits((string) $fee, $currency), $currency);
    }
}
