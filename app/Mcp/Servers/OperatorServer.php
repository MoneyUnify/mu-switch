<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\Operator;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

/**
 * MoneyUnify for operators: a DFSP, aggregator or operator runs its whole
 * switch through it, for its own payments, transfers and payouts, and as a
 * gateway that collects and settles for its merchants.
 */
#[Name('MoneyUnify Operator')]
#[Version('1.0.0')]
#[Instructions(<<<'TEXT'
MoneyUnify is a payment switch that connects PayPal to mobile money. You act for an operator (a DFSP, aggregator or mobile-money operator) on its own MoneyUnify account.

- Direct payments: quote_payment, then request_payment collects from a customer through the first eligible provider; check_payment re-checks it; refund_payment refunds a PayPal payment.
- Payouts: request_payout pays from the operator's float to a mobile wallet; check_payout re-checks it.
- Transfers (PayPal in, mobile money out): quote_transfer, then start_transfer returns a PayPal checkout_url for the sender; check_transfer moves it on; list_transfers shows recent ones.
- Gateway for merchants: onboard_merchant, update_merchant, list_merchants, charge_merchant_customer, merchant_settlements, create_pay_link, list_pay_links.
- FX and treasury: get_exchange_rates, lock_fx_quote (rate_source says whether the rate is PayPal's), treasury_balances.

Money moves on request_payout, refund_payment, and when a payer approves a checkout. Confirm the amount, currency and recipient with the user before calling them. Use only amounts and numbers the user gave; never reuse them from earlier results. Pass an idempotency_key so a retry never acts twice.
TEXT)]
class OperatorServer extends Server
{
    /** Every tool fits in one tools/list page, so clients that don't page still see them all. */
    public int $defaultPaginationLength = 50;

    protected array $tools = [
        Operator\QuotePayment::class,
        Operator\RequestPayment::class,
        Operator\CheckPayment::class,
        Operator\RefundPayment::class,
        Operator\CheckRefund::class,
        Operator\RequestPayout::class,
        Operator\CheckPayout::class,
        Operator\QuoteTransfer::class,
        Operator\StartTransfer::class,
        Operator\CheckTransfer::class,
        Operator\ListTransfers::class,
        Operator\ListMerchants::class,
        Operator\OnboardMerchant::class,
        Operator\UpdateMerchant::class,
        Operator\ChargeMerchantCustomer::class,
        Operator\MerchantSettlements::class,
        Operator\CreatePayLink::class,
        Operator\ListPayLinks::class,
        Operator\GetExchangeRates::class,
        Operator\LockFxQuote::class,
        Operator\TreasuryBalances::class,
    ];
}
