---
title: Exchange Rates & FX Quotes
excerpt: Get exchange rates and lock a currency conversion for 10 minutes, quoted from what you send or from what must arrive.
date: 2026-10-15
category: API Reference
---

Cross-border payments usually collect in one currency and pay out in another,
for example **USD** through PayPal and **ZMW** to a mobile wallet. The FX API
gives you the rate before anything moves, and **locks** it in a quote for a
short time, so the amount you showed is the amount used.

- **Rates** list the rate from a base currency to every supported currency.
- **Quotes** convert one amount and lock the rate, the converted amount and the
  margin for 10 minutes (configurable).

Quoting is free and never moves money. With [PayPal FX](#paypal-fx) on, the
rate comes from PayPal (cached until PayPal refreshes it); otherwise no
provider is contacted.

## How a rate is built

| Term | Meaning |
| --- | --- |
| `base_rate` | The base rate: units of the target currency for 1 unit of the source. PayPal's own rate with PayPal FX, otherwise MoneyUnify's. |
| `rate_source` | Where the base rate came from: `paypal` or `moneyunify`. |
| `margin_percent` | The platform margin, 1.5% by default (`FX_MARGIN_PERCENT`). |
| `effective_rate` | The rate actually used: `base_rate × (1 − margin / 100)`. |

Amounts are exact decimals, rounded to each currency's decimal places (2 for
USD and ZMW, 0 for UGX, RWF, XOF and XAF). When you quote by the amount that
must arrive, the amount to send is **rounded up**, so the recipient always gets
at least the target.

> **MoneyUnify rates.** Without PayPal FX, rates come from a static table in
> `config/moneyunify_paypal.php` (`FX_DRIVER=static`). They're illustrative, not
> live market rates.

## PayPal FX

An account whose PayPal business account has **FX as a Service** gets PayPal's
own exchange rates, with nothing else to set up. PayPal quotes from the
currencies it holds (USD, EUR, GBP and 21 more) into over 100 currencies,
including the mobile-money ones: ZMW, KES, UGX, TZS, GHS, NGN, MWK, RWF, XOF,
XAF and ZAR.

- **Quotes.** The base rate is PayPal's, and your margin is applied on top.
  Pairs PayPal doesn't quote directly are inverted (ZMW → USD) or crossed
  through USD (KES → ZMW). `rate_source` is `paypal` on the quote, the transfer
  and every rate PayPal supplied.
- **Payments in another currency.** When the payer pays in a currency other
  than the PayPal account's settlement currency (USD unless you set **PayPal
  settlement currency**), the order carries PayPal's `fx_id`. PayPal then
  converts at that locked rate instead of the rate at capture, and the receipt
  shows the rate PayPal used.
- **Fallback.** Until FX is enabled, or whenever PayPal can't answer, quotes use
  MoneyUnify's rates. An account without FX is asked again every 30 minutes. If
  PayPal suspends a locked rate for a market event
  (`FX_RATE_CHANGE_DUE_TO_MARKET_EVENT`), the order is made without it and PayPal
  converts at capture.
- **Turning it off.** Set the PayPal provider's **Exchange rates** to
  `moneyunify`.

**Getting it.** PayPal enables FX as a Service for the business account, under
its FX agreement: ask PayPal, or your PayPal account manager. That applies to
sandbox business accounts too; a sandbox account without it gets
`FEATURE_NOT_PROVISIONED`. Once PayPal has turned it on, choose **It's already
enabled → Check again** on the **Exchange Rates** page (or wait up to 30
minutes) and quotes switch to PayPal's rates. The PayPal rate converts PayPal's own leg; the mobile-money payout
is paid from your float at the rate you locked.

## Authentication

Required for every endpoint. Send your API token as a Bearer token. See
[Authentication](/docs/api/authentication).

## Get exchange rates

```http
GET /api/v1/fx/rates?base=USD
```

| Query | Type | Rules | Description |
| --- | --- | --- | --- |
| `base` | string | nullable, 3 letters | The currency to quote from. Defaults to `USD`. |

```bash
curl https://your-domain.com/api/v1/fx/rates?base=USD \
  -H "Authorization: Bearer YOUR_API_TOKEN" \
  -H "Accept: application/json"
```

```json
{
  "status": "success",
  "message": "Exchange rates",
  "data": {
    "base_currency": "USD",
    "rate_source": "paypal",
    "paypal_fx": { "state": "enabled", "activation_url": null },
    "rates": {
      "ZMW": {
        "currency": "ZMW",
        "base_rate": 27.5,
        "effective_rate": 27.0875,
        "inverse_rate": 0.03636364,
        "decimals": 2,
        "margin_percent": 1.5,
        "country_code": "ZM",
        "country_name": "Zambia",
        "rate_source": "paypal"
      }
    }
  }
}
```

Rates are indicative. To lock a conversion, request a quote.

## Lock a quote

```http
POST /api/v1/fx/quote
```

| Field | Type | Rules | Description |
| --- | --- | --- | --- |
| `from_currency` | string | required, 3 letters | The currency you send, e.g. `USD`. |
| `to_currency` | string | required, 3 letters | The currency that arrives, e.g. `ZMW`. |
| `amount` | number | required, `> 0` | The amount to convert. Its meaning depends on `mode`. |
| `mode` | string | nullable, `source` or `target` | `source` (default): `amount` is what you send. `target`: `amount` is what must arrive. |

**By what you send:** "I send 100 USD. How much ZMW arrives?"

```bash
curl -X POST https://your-domain.com/api/v1/fx/quote \
  -H "Authorization: Bearer YOUR_API_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{ "from_currency": "USD", "to_currency": "ZMW", "amount": 100 }'
```

```json
{
  "status": "success",
  "message": "FX quote generated",
  "data": {
    "id": "b0f70b2c-f883-4e14-a19d-58230b100ad5",
    "source_currency": "USD",
    "target_currency": "ZMW",
    "source_amount": "100.00",
    "target_amount": "2708.75",
    "base_rate": "27.50000000",
    "effective_rate": "27.08750000",
    "margin_percent": "1.5",
    "margin_amount": "1.50",
    "is_fx": true,
    "quoted_at": "2026-10-15T17:14:09+00:00",
    "expires_at": "2026-10-15T17:24:09+00:00",
    "expires_in_seconds": 599,
    "is_expired": false
  }
}
```

**By what must arrive:** "The recipient needs exactly 2,500 ZMW. How much USD
do I send?" Send the same request with `"amount": 2500, "mode": "target"`. The
response has `"source_amount": "92.30"` and `"target_amount": "2500.00"`.

| Field | Description |
| --- | --- |
| `id` | The quote ID. Keep it to check the quote, or to use it once quotes can fund transfers. |
| `source_amount` / `target_amount` | What is sent and what arrives, each in its currency's decimal places. |
| `margin_amount` | The margin, in the source currency. |
| `is_fx` | `false` when both currencies are the same: the rate is 1 and no margin applies. |
| `expires_at` / `expires_in_seconds` | When the locked rate stops being valid. |

## Check a quote

```http
GET /api/v1/fx/quote/{id}
```

Returns the same fields as above, with `expires_in_seconds` and `is_expired`
updated. A quote can only be read by the **account that created it**. Another
account's quote, an unknown ID or an expired quote all return `404`, so a quote
ID reveals nothing to anyone else.

## Errors

| Status | When |
| --- | --- |
| `400` | No rate exists between the two currencies, e.g. `"No exchange rate available between USD and XYZ."` |
| `401` | The API token is missing or invalid. |
| `404` | The quote doesn't exist, has expired, or belongs to another account. |
| `422` | A field fails validation, e.g. a 4-letter currency or an amount of 0. |

See [Responses & Errors](/docs/api/responses) for the response envelope.

## Configuration (self-hosted)

| `.env` | Default | Description |
| --- | --- | --- |
| `FX_DRIVER` | `static` | Where MoneyUnify's rates come from. `static` reads the table in `config/moneyunify_paypal.php`. Accounts with PayPal FX use PayPal's rates first. |
| `FX_MARGIN_PERCENT` | `1.5` | The margin applied to every cross-currency rate. |
| `FX_QUOTE_TTL_MINUTES` | `10` | How long a quote stays locked. |

The dashboard's **Exchange Rates** page shows the same rates, with a
calculator that locks quotes the same way.
