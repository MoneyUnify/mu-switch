---
title: Cross-Border Transfers
excerpt: Let a sender pay with PayPal and deliver the money to a mobile wallet in another currency, at a locked exchange rate, with an automatic refund if it can't be delivered.
date: 2026-10-15
category: API Reference
---

A cross-border transfer connects two accounts **you already own**:

1. A **PayPal** business account, where the sender pays (for example USD).
2. A **mobile-money payout** account, such as Lenco or pawaPay, that holds a float in the
   recipient's currency (for example ZMW).

No money moves between PayPal and the mobile-money provider during the
transfer. The sender pays into your PayPal account, and the recipient is paid
from your payout float. You rebalance the two accounts later, for example with
[Treasury](/docs/treasury) transfers. This is the prefunding model that
remittance companies use, and it's why the recipient is paid in seconds.

## How a transfer works

```
quote → awaiting_payment → funded → paying_out → delivered
                         ↘ payment_failed      ↘ payout_failed → refunding → refunded
```

1. **Quote.** The switch locks an exchange rate (see
   [Exchange Rates & FX Quotes](/docs/api/fx)), checks that your PayPal account
   can collect and that a payout provider can deliver, and estimates the fees.
2. **Pay with PayPal.** `request` creates a PayPal order and returns a
   `checkout_url`. The sender approves the payment on PayPal.
3. **Pay out.** As soon as PayPal confirms the capture (the sender's return,
   the [PayPal webhook](/docs/providers) or `verify`), the switch pays the
   recipient's wallet from your payout account. It uses the same payout routing
   as [Send a Payout](/docs/api/payouts), so it falls back across providers but
   never pays twice.
4. **Delivered or refunded.** When the payout provider confirms, the transfer
   is `delivered`. If every payout provider refuses, or the payout fails, the
   switch **refunds the sender's PayPal payment in full**, automatically.

Both legs record the exchange rate (`is_fx` and `fx_rate` on each transaction),
and the [ledger](/docs/treasury) shows the money arriving at PayPal, leaving
the payout float and, if needed, being refunded.

### Who pays the fees

The sender pays exactly `sender_pays`, and the recipient gets exactly
`recipient_gets`. The provider fees (PayPal's fee on the payment and the payout
provider's fee) are paid by **you, the operator**, from your own accounts.
They're shown as `operator_fees` so you can price your margin. Your income is
the FX margin (`fx.margin_amount`).

## Authentication

Required for every endpoint. Send your API token as a Bearer token. See
[Authentication](/docs/api/authentication).

## Quote a transfer

```http
POST /api/v1/transfer/quote
```

| Field | Type | Rules | Description |
| --- | --- | --- | --- |
| `amount` | number | required, `> 0` | The amount. Its meaning depends on `mode`. |
| `mode` | string | nullable, `target` or `source` | `target` (default): the recipient gets exactly `amount`. `source`: the sender pays exactly `amount`. |
| `country` | string | required, 2 letters | The recipient's country, e.g. `ZM`. |
| `currency` | string | nullable, 3 letters | The recipient's currency. Defaults to the country's own (`ZMW` for `ZM`). |
| `source_currency` | string | nullable, 3 letters | The currency the sender pays in on PayPal. Defaults to `USD`. |
| `fx_quote_id` | string | nullable | Use a quote you already locked with [`POST /api/v1/fx/quote`](/docs/api/fx). It must be yours, unexpired, and for the same currencies. |

```bash
curl -X POST https://your-domain.com/api/v1/transfer/quote \
  -H "Authorization: Bearer YOUR_API_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{ "amount": 2500, "country": "ZM" }'
```

```json
{
  "status": "success",
  "message": "Transfer quoted",
  "data": {
    "fx_quote_id": "2653c984-4e7f-4b34-b338-91ec79fecda0",
    "sender_pays": { "amount": "92.30", "currency": "USD" },
    "recipient_gets": { "amount": "2500.00", "currency": "ZMW" },
    "fx": {
      "base_rate": "27.50000000",
      "effective_rate": "27.08750000",
      "margin_percent": "1.5",
      "margin_amount": "1.38"
    },
    "operator_fees": {
      "paypal": { "amount": "3.71", "currency": "USD" },
      "payout": { "amount": "25.00", "currency": "ZMW" },
      "estimated": true
    },
    "funding_provider": "PayPal",
    "payout_route": ["pawaPay", "Simulated Payouts"],
    "country": "ZM",
    "expires_at": "2026-10-15T17:24:09+00:00"
  }
}
```

`payout_route` lists the payout providers in the order they'll be tried.
Quoting never contacts a provider and never moves money.

## Start a transfer

```http
POST /api/v1/transfer/request
```

Takes every quote field, plus:

| Field | Type | Rules | Description |
| --- | --- | --- | --- |
| `account_number` | string | required | The recipient's mobile-money number, local or international format. |
| `recipient_name` | string | nullable | Shown in the transfer's details. |
| `recipient_email` | string | nullable, email | The recipient is emailed here when the money arrives in their wallet. |
| `network` | string | nullable | The recipient's network, when the payout provider can't detect it. |
| `narration` | string | nullable, max 100 | Shown to the recipient where the provider supports it. |
| `payer_email` | string | nullable, email | Pre-fills the sender's PayPal login, and is where the sender is emailed when the transfer is delivered or refunded. |
| `pay_with` | string | nullable, `paypal` or `card` | `card` opens PayPal on its guest debit or credit card form, for senders without a PayPal account. Defaults to `paypal`. |
| `return_url` | string | nullable, URL | Where PayPal sends the sender after paying. |

Send an `Idempotency-Key` header to make retries safe: the same key returns
the original transfer (with `"replayed": true`) instead of starting another.

```bash
curl -X POST https://your-domain.com/api/v1/transfer/request \
  -H "Authorization: Bearer YOUR_API_TOKEN" \
  -H "Idempotency-Key: send-mum-2026-10-15" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "amount": 2500,
    "country": "ZM",
    "account_number": "0977123456",
    "recipient_name": "Mum",
    "recipient_email": "mum@example.com",
    "payer_email": "chipo@example.com",
    "return_url": "https://your-app.com/sent"
  }'
```

```json
{
  "status": "awaiting_payment",
  "message": "Transfer started. Send the sender to checkout_url to pay with PayPal.",
  "data": {
    "reference": "7f0c6c1e-3b5a-4c55-9a1d-2b7f3f9e8a10",
    "status": "awaiting_payment",
    "status_label": "Awaiting PayPal payment",
    "checkout_url": "https://www.sandbox.paypal.com/checkoutnow?token=5O190127TN364715T",
    "sender_pays": { "amount": "92.30", "currency": "USD" },
    "recipient_gets": { "amount": "2500.00", "currency": "ZMW" },
    "recipient": { "account_number": "260977123456", "country": "ZM", "name": "Mum" },
    "fx": {
      "quote_id": "2653c984-4e7f-4b34-b338-91ec79fecda0",
      "base_rate": "27.50000000",
      "effective_rate": "27.08750000",
      "margin_percent": "1.5000",
      "margin_amount": "1.38"
    },
    "funding": { "provider": "PayPal", "transaction_id": "9b1d…", "status": "pending" },
    "timeline": [
      { "status": "awaiting_payment", "note": "Quoted at 92.30 USD → 2500.00 ZMW @ 27.08750000 (includes 1.5% margin)", "at": "2026-10-15T17:14:09+00:00" }
    ]
  }
}
```

Redirect the sender to `checkout_url`. Nothing is paid out until PayPal
confirms the payment.

## Check a transfer

```http
POST /api/v1/transfer/verify
```

| Field | Type | Rules | Description |
| --- | --- | --- | --- |
| `reference` | string | required | The transfer's `reference`. |

`verify` re-checks whichever leg is in progress (the PayPal payment, the
payout or the refund), moves the transfer forward, and returns it with its
`funding`, `payout`, `refund` and full `timeline`. It's safe to call at any
time. Webhooks and the sender's return from PayPal move transfers forward on
their own; `verify` is the fallback.

| `status` | Meaning |
| --- | --- |
| `awaiting_payment` | Waiting for the sender to pay on PayPal. |
| `funded` | PayPal captured the payment; the payout is starting. |
| `paying_out` | The payout provider is delivering to the wallet. |
| `delivered` | The recipient was paid. **Final.** |
| `payment_failed` | The sender didn't complete the PayPal payment. Nothing was paid out. **Final.** |
| `payout_failed` | The payout couldn't be delivered; the refund is starting. |
| `refunding` | PayPal is processing the refund. |
| `refunded` | The sender's PayPal payment was refunded in full. **Final.** |
| `refund_failed` | PayPal refused the refund. Call `verify` to retry it; the retry reuses the same PayPal request, so the sender is never refunded twice. |

A transfer can only be read by the account that created it.

## Emails to the sender and recipient

When the request includes `payer_email`, the sender gets an email:

- **Delivered:** what the recipient received, what they paid with PayPal, the
  locked exchange rate, the reference and a link to their PayPal-verified
  receipt.
- **Refunded:** the transfer couldn't be delivered, so the full payment went
  back to them through PayPal, with the same reference and receipt link.

When the request includes `recipient_email`, the recipient is emailed once the
money is in their wallet: the amount, the masked wallet number, who sent it, the
narration and the reference. They aren't emailed if the transfer is refunded.
Their mobile-money provider also confirms the deposit by SMS.

Each email is sent once, after the change is saved, through your app's mail
settings (`MAIL_*` in `.env`). Senders who gave no email aren't emailed; PayPal
still sends its own receipts.

## Errors

| Status | When |
| --- | --- |
| `400` | No active PayPal account can collect the currency, no payout provider can deliver to the country, or no exchange rate exists for the pair. Nothing is created. |
| `401` | The API token is missing or invalid. |
| `404` | The transfer doesn't exist or belongs to another account. |
| `409` | A request with the same `Idempotency-Key` is still being processed. |
| `422` | A field fails validation, the `fx_quote_id` is for different currencies, or a spending limit would be passed. |
| `502` | PayPal couldn't start the payment. The transfer is marked `payment_failed`. |

See [Responses & Errors](/docs/api/responses) for the response envelope.

## Spending limits

Every cross-border transfer is checked against two caps, in USD, whoever
starts it (the API, the dashboard or the AI assistant):

| `.env` | Default | Limit |
| --- | --- | --- |
| `TRANSFER_MAX_PER_TRANSACTION_USD` | `2000` | The most one transfer may be worth. |
| `TRANSFER_MAX_DAILY_USD` | `5000` | The most the account may send per day. Transfers whose PayPal payment wasn't completed don't count. |

A transfer over a limit is refused with `422` and a message saying how much is
left today. Nothing is created.

## From the dashboard

The **Send Money** page (in the sidebar) does the same without any code:

- **Form.** Choose the country and the sender's currency, then enter what the
  recipient gets or what the sender pays. The quote updates as you type and
  shows the rate, the margin, the payout route and your estimated fees. **Pay
  with PayPal** starts the transfer and takes the sender to PayPal; after
  paying, they come back to the page.
- **Assistant.** Describe the transfer in plain words, for example "Send K2,500
  to my mum on 0977123456". The [AI assistant](/docs/ai-and-mcp) quotes it,
  and when you say go ahead it asks for your **approval** before starting the
  transfer. It then gives you the PayPal link.
- **Transfers.** Every transfer shows its progress (paid, sending, delivered
  or refunded) and its full timeline. The list updates on its own while a
  transfer is moving; **Check now** re-checks one immediately.

## Set up (operator)

1. On the **Providers** page, add your **PayPal** account (collects the
   sender's payment) and at least one **payout** provider for the recipient's
   country, such as Lenco (set its **Account ID (for payouts)**) or pawaPay. Tick the countries each one serves.
2. Keep a float in the payout account, in the recipient's currency. Set a float
   rule on the **Treasury** page so you're alerted before it runs low.
3. Run a queue worker (`php artisan queue:work`) and the scheduler
   (`php artisan schedule:run` every minute). Payouts and refunds run as
   queued jobs as soon as PayPal confirms a payment, and
   `cross-border:refresh` moves in-flight transfers forward every minute,
   since payout providers rarely call back.
4. In sandbox, test every outcome using full 10-digit (local) or 12-digit (international) numbers:
   - With **pawaPay sandbox** (active in hosted staging/production): use `0763456789` / `260763456789` to complete; use `0763456079` / `260763456079` (MTN) or `0973456089` / `260973456089` (Airtel) to test failure and automatic PayPal refund. (Arbitrary numbers ending in `1111` do not fail on pawaPay.)
   - With **Simulated Payouts** (local): `0977123456` completes, `0977120000` (ends in `0000`) is refused immediately, and `0966551111` (ends in `1111`) is accepted then fails on verification, refunding the sender via PayPal.
