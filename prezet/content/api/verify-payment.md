---
title: Verify a Payment
excerpt: Re-check a transaction's status through the switch. The switch queries the original provider, persists the latest status, and notifies your callback URL when the payment settles.
date: 2026-06-27
category: API Reference
---

Mobile-money collections are often **asynchronous** — the payer approves the
charge on their phone moments after you initiate it. Use this endpoint to check
where a transaction stands at any time.

```http
POST /api/v1/payment/verify
```

The switch looks up the transaction, asks the **same provider that processed it**
for the latest status, saves it, and returns the normalised result. If the
transaction has settled and you supplied a [`callback_url`](/docs/api/request-payment)
when initiating it, the switch also notifies that URL — see
[Callbacks](#callbacks) below.

## Authentication

Required. Send your API token as a Bearer token — see
[Authentication](/docs/api/authentication).

## Request body

| Field | Type | Rules | Description |
| --- | --- | --- | --- |
| `transaction_id` | string | required | The `transaction_id` returned by [Request a Payment](/docs/api/request-payment). |

## Example request

```bash
curl -X POST https://your-domain.com/api/v1/payment/verify \
  -H "Authorization: Bearer YOUR_API_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "transaction_id": "9b6c2f1e-2a4d-4c7e-9f3a-1b2c3d4e5f6a"
  }'
```

## Response

`200 OK` — verification always returns HTTP `200` because the request itself
succeeded. **The top-level `status` mirrors the transaction's real outcome**
(`success`, `failed`, or `pending`) — it is *not* a generic "the API call
worked" flag — so you can branch on it directly.

A **successful** payment:

```json
{
  "status": "success",
  "message": "Transaction completed successfully",
  "data": {
    "transaction_id": "9b6c2f1e-2a4d-4c7e-9f3a-1b2c3d4e5f6a",
    "reference": "lenco_ref_abc123",
    "status": "success",
    "provider_status": "successful",
    "amount": 50.0,
    "currency": "ZMW"
  }
}
```

A **failed** payment (note the top-level `status` is `failed`, not `success`):

```json
{
  "status": "failed",
  "message": "Transaction failed",
  "data": {
    "transaction_id": "9b6c2f1e-2a4d-4c7e-9f3a-1b2c3d4e5f6a",
    "reference": "lenco_ref_abc123",
    "status": "failed",
    "provider_status": "failed",
    "amount": 50.0,
    "currency": "ZMW"
  }
}
```

A **pending** payment (still awaiting the payer / provider):

```json
{
  "status": "pending",
  "message": "Transaction is still pending",
  "data": { "status": "pending", "provider_status": "pay-offline", "...": "..." }
}
```

| Field | Description |
| --- | --- |
| `status` (top-level) | The transaction's normalised outcome: `success`, `failed`, or `pending`. Branch on this. |
| `message` | A human-readable summary of the outcome. |
| `data.status` | The same normalised status (mirrors the top-level `status`). |
| `data.provider_status` | The raw status string from the provider (e.g. Lenco's `successful`, `pay-offline`). |
| `data.reference` | The provider's reference for the transaction. |

> A genuine **error** (e.g. the provider can't be reached, or the transaction
> isn't found) is different — those return `status: "error"` with a `4xx`/`5xx`
> code, so `error` always means "verification failed", never "the payment
> failed".

## Status mapping

The switch normalises each provider's statuses onto three states, so your
integration only ever deals with `success`, `failed`, or `pending`:

| Switch status | Lenco statuses |
| --- | --- |
| `success` | `successful` |
| `failed` | `failed` |
| `pending` | `pending`, `pay-offline`, `3ds-auth-required` |

## Errors

| Status | `message` | Meaning |
| --- | --- | --- |
| `404` | `Transaction not found` | No transaction with that `transaction_id` belongs to your account. |
| `422` | `The provider for this transaction is no longer available` | The driver that created the transaction is missing. |
| `4xx`/`5xx` | *(provider message)* | The provider could not be reached or returned an error. |

## Callbacks

If a transaction was created with a `callback_url`, the switch delivers the final
result to that URL **once** — the first time verification finds the transaction
in a settled state (`success` or `failed`). Pending checks never fire a callback.

The callback is an HTTP `POST` with this JSON body:

```json
{
  "transaction_id": "9b6c2f1e-2a4d-4c7e-9f3a-1b2c3d4e5f6a",
  "reference": "lenco_ref_abc123",
  "status": "success",
  "amount": 50.0,
  "currency": "ZMW",
  "provider": "Lenco"
}
```

Notes:

- Delivery is **queued and retried** (up to 3 attempts with backoff), so make
  your endpoint **idempotent** — key off `transaction_id`.
- A transaction is notified **at most once**; repeated verification of an
  already-settled, already-notified transaction will not re-send the callback.
- Respond with a `2xx` status to acknowledge receipt.
