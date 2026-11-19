---
title: Send a Payout
excerpt: Send money out to a mobile wallet, with fallback across providers that never risks paying anyone twice.
date: 2026-10-15
category: API Reference
---

A payout sends money **out** of one of your provider accounts to a recipient's
mobile wallet. The switch picks an eligible payout provider, falls back if one
refuses, records the fees, and keeps your [ledger](/docs/providers) balance up
to date.

```http
POST /api/v1/payout/request
POST /api/v1/payout/verify
```

Both need your API token. See [Authentication](/docs/api/authentication).

## Send a payout

### Request body

| Field | Type | Rules | Description |
| --- | --- | --- | --- |
| `amount` | number | required, `>= 0.01` | What the recipient receives, in at most the currency's decimal places. |
| `account_number` | string | required | The recipient's mobile-money number, e.g. `0977123456`. |
| `country` | string | required, 2 letters | The recipient's market. |
| `currency` | string | nullable, 3 letters | Defaults to the country's own currency (e.g. ZMW for `ZM`). |
| `network` | string | nullable | The recipient's operator, when the provider can't detect it (e.g. pawaPay's `MTN_MOMO_ZMB`, or Lenco's `airtel`, `mtn`, `zamtel` or `tnm`). |
| `narration` | string | nullable, max 100 | A note shown to the recipient where the provider supports it. |
| `callback_url` | string | nullable, valid URL | Where the final result is POSTed once the payout settles. |

### Idempotency

Send an `Idempotency-Key` header (any unique string, up to 255 characters) with
every payout. If the same key is sent again, the switch returns the **original**
payout instead of paying again, with `"replayed": true`. While a payout with
that key is still being sent, a second request gets `409`.

```bash
curl -X POST https://your-domain.com/api/v1/payout/request \
  -H "Authorization: Bearer YOUR_API_TOKEN" \
  -H "Idempotency-Key: order-42" \
  -H "Content-Type: application/json" \
  -d '{ "amount": 200, "account_number": "0977123456", "country": "ZM", "narration": "School fees" }'
```

```json
{
  "status": "success",
  "message": "Payout initiated successfully",
  "data": {
    "transaction_id": "9b6c2f1e-2a4d-4c7e-9f3a-1b2c3d4e5f6a",
    "reference": "9b6c2f1e-2a4d-4c7e-9f3a-1b2c3d4e5f6a",
    "status": "pending"
  }
}
```

### How fallback works, and why it never pays twice

The switch tries your payout providers in routing order. What happens next
depends on the provider's answer:

| Provider's answer | What the switch does | HTTP |
| --- | --- | --- |
| **Accepted** | Returns the payout (usually `pending` until delivered). | `200` |
| **Definitely refused** (invalid number, insufficient balance, …) | Marks that attempt `failed` and tries the next provider. | `422` if every provider refuses |
| **No definite answer** (server error, dropped connection) | **Stops.** The payout may still go through, so trying another provider could pay the recipient twice. | `202` with `status: "pending"` |

After a `202`, **verify the payout** before doing anything else.

## Verify a payout

```http
POST /api/v1/payout/verify
```

```json
{ "transaction_id": "9b6c2f1e-2a4d-4c7e-9f3a-1b2c3d4e5f6a" }
```

The switch asks the provider for the latest status and returns `success`,
`failed` or `pending`, with `provider_status` and, for failures,
`failure_reason`. When the payout settles, your `callback_url` is notified once.

## Fees and your balance

- The payout fee is charged **on top**: the recipient gets `amount`, and your
  provider account is debited `amount + fee`.
- Fees come from the provider's fee schedule (a `payout` section) and are
  marked estimated.
- Every settled payout is written to the ledger, so the provider's **Accounts**
  balance goes down by the amount and the fee.

## Payout providers

| Driver | Notes |
| --- | --- |
| **pawaPay** | Mobile-money payouts in its markets. Set **Environment** to `sandbox` with a sandbox API token to test. Use `0763456789` / `260763456789` (MTN) to complete, or `0763456079` / `260763456079` (MTN) or `0973456089` / `260973456089` (Airtel) to test failure/refund. (Arbitrary numbers do not fail on pawaPay.) |
| **Lenco** | Mobile-money payouts in Zambia and Malawi, from the Lenco account set in the provider's **Account ID (for payouts)**. See [Payouts through Lenco](/docs/providers). |
| **Simulated Payouts** | Sandbox only, never available in production. Full 10-digit/12-digit numbers: `0977123456` completes, `0977120000` (ends in `0000`) is refused, `0977129999` (ends in `9999`) gives no answer, and `0966551111` (ends in `1111`) fails on verification (refund). |

## Errors

| Status | Example `message` | Meaning |
| --- | --- | --- |
| `400` | `No active payout providers support the requested country` | No payout-capable provider serves the country. |
| `404` | `Payout not found` | No payout with that `transaction_id` on your account. |
| `409` | `A payout with this Idempotency-Key is already being processed` | Wait, then retry with the same key. |
| `422` | `ZMW amounts can have at most 2 decimal places` | The amount is finer than the currency allows. |
