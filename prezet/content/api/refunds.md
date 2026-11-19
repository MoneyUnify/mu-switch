---
title: Refund a Payment
excerpt: Refund a completed PayPal payment in full or in part, safely retried, and kept in step with refunds made in PayPal itself.
date: 2026-10-17
category: API Reference
---

Refund a completed payment to the person who paid it, through the provider
that collected it (PayPal today). A refund can be the whole payment or part of
it, and several partial refunds can be made until the payment is fully
refunded. The switch never lets refunds add up to more than was paid.

Each refund is recorded as its own transaction, linked to the payment, so the
[ledger](/docs/treasury) shows the money leaving your PayPal account.

```http
POST /api/v1/payment/refund
```

## Authentication

Required. Send your API token as a Bearer token. See
[Authentication](/docs/api/authentication).

## Request body

| Field | Type | Rules | Description |
| --- | --- | --- | --- |
| `transaction_id` | string | required | The payment's `transaction_id`, from [Request a Payment](/docs/api/request-payment). |
| `amount` | number | nullable, `> 0` | How much to refund, in the payment's currency. Leave it out to refund everything still refundable. |
| `reason` | string | nullable, max 255 | Shown to the payer by PayPal, and on the refund in your dashboard. |

Send an `Idempotency-Key` header to make retries safe: the same key returns
the original refund instead of refunding again.

## Example

```bash
curl -X POST https://your-domain.com/api/v1/payment/refund \
  -H "Authorization: Bearer YOUR_API_TOKEN" \
  -H "Idempotency-Key: refund-order-1042" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{ "transaction_id": "9b1d0c3e-…", "amount": 10, "reason": "One item out of stock" }'
```

```json
{
  "status": "success",
  "message": "Refund completed",
  "data": {
    "refund_id": "3f6c2a91-…",
    "reference": "1JU08902781691411",
    "transaction_id": "9b1d0c3e-…",
    "status": "success",
    "amount": "10.00",
    "currency": "USD",
    "reason": "One item out of stock",
    "failure": null,
    "payment": { "amount": "25.00", "refunded": "10.00", "refundable": "15.00" }
  }
}
```

| `status` | Meaning |
| --- | --- |
| `success` | PayPal refunded the payer. |
| `pending` | PayPal is processing the refund, or didn't answer. The amount stays reserved until you [check it](#check-a-refund). |
| `failed` | PayPal refused the refund (`failure` says why). The amount is free to refund again. |

## Check a refund

```http
POST /api/v1/payment/refund/verify
```

| Field | Type | Rules | Description |
| --- | --- | --- | --- |
| `refund_id` | string | required | The `refund_id` from the refund response. |

If PayPal never confirmed the refund, checking it asks PayPal again with the
same request, which returns the original refund rather than making a second
one.

## Refunds made in PayPal

Refunds you make in PayPal's own dashboard are picked up automatically when
the payment is next checked, for example by a [PayPal webhook](/docs/providers)
or [Verify a Payment](/docs/api/verify-payment). They appear on the payment
like any other refund and count towards what's still refundable.

## When a refund isn't allowed

| Status | Message |
| --- | --- |
| `404` | The payment doesn't exist or belongs to another account. |
| `409` | Another refund for the same payment is being processed. Try again in a moment. |
| `422` | The payment isn't completed, its provider can't refund, the amount is more than what's left, or the payment funds a [cross-border transfer](/docs/api/cross-border-transfers) (which refunds itself automatically if delivery fails). |

You can also refund from the dashboard: open a payment on the **Payments**
page and use **Refund**.
