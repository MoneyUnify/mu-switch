---
title: Quote a Payment
excerpt: See what a payment will cost before you charge anyone, with fees from both sides, for every provider that would route it.
date: 2026-10-15
category: API Reference
---

Before you charge anyone, ask the switch what a payment will cost. A quote
shows, for **every provider that would take the payment** and in the order the
switch would try them:

- **Merchant pays the fees.** The payer is charged the amount, and you receive
  the amount less fees.
- **Payer pays the fees.** The payer is charged more, so you receive the full
  amount.

Quoting is free and never contacts a provider.

```http
POST /api/v1/payment/quote
```

## Authentication

Required. Send your API token as a Bearer token. See
[Authentication](/docs/api/authentication).

## Request body

| Field | Type | Rules | Description |
| --- | --- | --- | --- |
| `amount` | number | required, `>= 0.01` | The amount to quote, in at most the currency's decimal places (2 for USD, 0 for JPY or XOF). |
| `country` | string | required, 2 letters | The market, exactly as for [Request a Payment](/docs/api/request-payment). |
| `currency` | string | nullable, 3 letters | Quote in this currency instead of the country's own (e.g. `USD` via PayPal). |

## Example

```bash
curl -X POST https://your-domain.com/api/v1/payment/quote \
  -H "Authorization: Bearer YOUR_API_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{ "amount": 100, "country": "ZM", "currency": "USD" }'
```

```json
{
  "status": "success",
  "message": "Quote generated",
  "data": {
    "amount": "100.00",
    "currency": "USD",
    "country": "ZM",
    "quoted_at": "2026-10-15T12:00:00+00:00",
    "quotes": [
      {
        "provider": "PayPal",
        "provider_id": 1,
        "currency": "USD",
        "fees_known": true,
        "estimated": true,
        "merchant_pays_fees": {
          "payer_pays": "100.00",
          "fees": { "collection": "3.98", "settlement": "0.00", "total": "3.98" },
          "merchant_receives": "96.02"
        },
        "payer_pays_fees": {
          "payer_pays": "104.12",
          "fees": { "collection": "4.12", "settlement": "0.00", "total": "4.12" },
          "merchant_receives": "100.00"
        }
      }
    ]
  }
}
```

| Field | Description |
| --- | --- |
| `quotes` | One entry per provider that would route the payment, in the order the switch tries them (cheapest first under the cost-aware [fee policy](/docs/providers)). |
| `fees.collection` | The provider's charge for collecting the payment (a percentage plus any fixed fee). |
| `fees.settlement` | The provider's charge for settling the money to you. |
| `merchant_receives` | What reaches you after fees. |
| `fees_known` | `false` when the provider publishes no fee schedule. Both views are then `null`. |
| `estimated` | Quotes always come from published fee schedules. |

## How the numbers are worked out

- **Exact decimal arithmetic.** No floating point, so there are no rounding
  drifts.
- **Each fee is rounded half-up** to the currency's minor unit (ISO 4217): cents
  for USD, whole units for JPY or XOF.
- **Fixed fees can differ by currency.** PayPal's fixed fee is 0.49 in USD and
  0.39 in EUR, for example.
- **Minimums, maximums and tiers** are applied as the provider defines them.
- **Payer pays the fees** is the smallest charge that leaves you at least the
  full amount. You may receive one minor unit more because of rounding, never
  less.

Amounts are returned as **strings**, so no precision is lost in JSON.

## Estimated vs actual fees

A quote is an estimate from the provider's published schedule. Once a payment
is made, providers that report their real fee replace the estimate:

- **PayPal** reports its exact fee after capture.
- **Flutterwave** reports its exact fee in the charge response.

The [Payments](/docs/providers) page shows each transaction's fees, marked
**Estimated** or **Actual**. A provider's fee schedule can be overridden per
provider with a `fee_schedule` in its config, to match your own negotiated rate.

## Errors

Quotes use the same routing as payments, so they fail for the same reasons. For
example, `400 No active providers support ZMW payments in ZM` when no provider
can charge that currency. A `422` means the amount has more decimal places than
the currency allows. See [Responses & Errors](/docs/api/responses).
