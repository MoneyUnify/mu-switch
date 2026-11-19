---
title: Merchant API
excerpt: Onboard merchants, charge their customers through PayPal, and follow each settlement to the merchant's mobile-money wallet.
date: 2026-10-17
category: API Reference
---

For platforms and aggregators: onboard merchants, charge their customers with
PayPal, and follow each payment until it's settled to the merchant's
mobile-money wallet. See [Merchants](/docs/merchants) for how the money flows.

All endpoints need your API token as a Bearer token. A merchant can be named
by its `slug` or its `id`; you only ever see your own merchants.

| Method | Path | Does |
| --- | --- | --- |
| `GET` | `/api/v1/merchants` | List your merchants, with totals. `per_page` up to 100. |
| `POST` | `/api/v1/merchants` | Add a merchant. |
| `GET` | `/api/v1/merchants/{merchant}` | One merchant, with totals. |
| `PATCH` | `/api/v1/merchants/{merchant}` | Change a merchant, or pause it with `"is_active": false`. |
| `POST` | `/api/v1/merchants/{merchant}/quote` | Price a payment without starting it. |
| `POST` | `/api/v1/merchants/{merchant}/charge` | Start a payment and get the PayPal checkout link. |
| `GET` | `/api/v1/merchants/{merchant}/settlements` | The merchant's payments and their settlement status. |
| `GET` | `/api/v1/merchants/{merchant}/links` | The merchant's pay links, with what each has collected. |
| `POST` | `/api/v1/merchants/{merchant}/links` | Create a pay link. |
| `PATCH` | `/api/v1/merchants/{merchant}/links/{code}` | Change a link, or disable it with `"is_active": false`. |
| `DELETE` | `/api/v1/merchants/{merchant}/links/{code}` | Delete a link; one that took payments is archived (`"outcome": "archived"`). |

## Add a merchant

```bash
curl -X POST https://your-domain.com/api/v1/merchants \
  -H "Authorization: Bearer YOUR_API_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{ "name": "Kitwe Crafts", "country": "ZM", "wallet_number": "0977123456", "commission_percent": 2 }'
```

| Field | Type | Rules |
| --- | --- | --- |
| `name` | string | required, max 120 |
| `country` | string | required, 2 letters; a payout provider must serve it |
| `wallet_number` | string | required; stored in international format |
| `network` | string | nullable |
| `wallet_name`, `email` | string | nullable |
| `commission_percent` | number | nullable, 0–20, two decimals |

The response includes the merchant's `pay_url`, its public pay link.

## Quote and charge

| Field | Type | Rules | Description |
| --- | --- | --- | --- |
| `amount` | number | required, `> 0` | The price. |
| `mode` | string | nullable | `target` (default): `amount` is the merchant's price in its currency. `source`: `amount` is what the customer pays. |
| `source_currency` | string | nullable | What the customer pays in with PayPal. Defaults to `USD`. Must be a currency your PayPal account collects. |
| `settle_currency` | string | nullable | What the merchant is settled in. Defaults to the merchant's currency; must be one your payout providers settle in its country. |
| `payer_email` | string | charge only, nullable | Pre-fills the customer's PayPal login. The customer is emailed there when the merchant is paid, or when the payment is refunded. The merchant is also emailed, at the merchant's `email`, when the payment reaches their wallet. |
| `pay_with` | string | charge only, nullable | `paypal` (default) or `card`. `card` opens PayPal on its guest debit or credit card form, for customers without a PayPal account. See [Guest card checkout](/docs/providers#guest-card-checkout). |
| `reference` | string | charge only, nullable | Your order or invoice reference. |
| `return_url` | string | charge only, nullable | Where PayPal sends the customer afterwards. Defaults to MoneyUnify's live receipt page. |

`charge` accepts an `Idempotency-Key` header; repeating a key returns the
original payment.

```json
{
  "status": "awaiting_payment",
  "message": "Payment started. Send the customer to checkout_url to pay with PayPal.",
  "data": {
    "checkout_url": "https://www.sandbox.paypal.com/checkoutnow?token=5O190127TN364715T",
    "receipt_url": "https://your-domain.com/pay/receipt/7f0c6c1e-…",
    "reference": "7f0c6c1e-…",
    "status": "awaiting_payment",
    "sender_pays": { "amount": "36.92", "currency": "USD" },
    "merchant_receives": { "amount": "980.00", "currency": "ZMW" },
    "commission": { "amount": "20.00", "currency": "ZMW" },
    "merchant_id": 12
  }
}
```

A quote returns `customer_pays`, `merchant_price`, `commission`,
`merchant_receives`, the rate and the provider that will settle it.

## Pay links

| Field | Type | Rules |
| --- | --- | --- |
| `label` | string | nullable, max 120 |
| `pay_currencies` | array | nullable; each a currency your PayPal account collects. Empty, or every one, means any. |
| `settle_currency` | string | nullable; a currency your payout providers settle in the merchant's country. Defaults to the merchant's currency. |
| `amount` | number | nullable; a fixed price in the settlement currency, in its decimal places |
| `reference` | string | nullable, max 100 |
| `is_active` | boolean | update only |

A link's `url` is its public pay page, `/pay/l/{code}`.

## Settlement statuses

Merchant payments follow the [cross-border transfer](/docs/api/cross-border-transfers)
statuses: `awaiting_payment`, `funded`, `paying_out`, `delivered`, and on
failure `payout_failed`, `refunding` and `refunded`. Check one at any time with
`POST /api/v1/transfer/verify` and its `reference`.
