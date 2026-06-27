---
title: Request a Payment
excerpt: The single endpoint your application calls to collect a payment. Parameters, examples, and the success response.
date: 2026-06-27
category: API Reference
---

This is the one endpoint your application needs. Call it to collect a payment;
the switch chooses an eligible provider and falls back automatically if the first
attempt fails.

```http
POST /api/v1/payment/request
```

## Authentication

Required. Send your API token as a Bearer token — see
[Authentication](/docs/api/authentication).

```http
Authorization: Bearer YOUR_API_TOKEN
```

## Request body

### Required fields

| Field | Type | Rules | Description |
| --- | --- | --- | --- |
| `amount` | number | required, numeric, `>= 0.01` | The amount to collect. |
| `account_number` | string | required | The payer's account / mobile-money number. |
| `country` | string | required, 2 letters, one of `ZM`, `MW` | ISO country code used for provider routing. |

### Optional fields

| Field | Type | Rules | Description |
| --- | --- | --- | --- |
| `callback_url` | string | nullable, valid URL | A webhook URL. Once you [verify](/docs/api/verify-payment) the transaction and it settles (succeeds or fails), the switch POSTs the final result here exactly once. |

> The supported `country` values reflect the providers shipped with the
> platform. As you add providers and drivers for other markets, the accepted set
> grows accordingly.

## Example request

```bash
curl -X POST https://your-domain.com/api/v1/payment/request \
  -H "Authorization: Bearer YOUR_API_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "amount": 50.00,
    "account_number": "0976000000",
    "country": "ZM",
    "callback_url": "https://your-app.com/webhooks/payments"
  }'
```

## Success response

`200 OK`

```json
{
  "status": "success",
  "message": "Payment request initiated successfully",
  "data": {
    "transaction_id": "9b6c2f1e-2a4d-4c7e-9f3a-1b2c3d4e5f6a",
    "reference": "lenco_ref_abc123",
    "status": "pending"
  }
}
```

| Field | Description |
| --- | --- |
| `transaction_id` | The switch's own UUID for the transaction. Use it to reconcile against your records and to [verify](/docs/api/verify-payment). |
| `reference` | The reference returned by the provider that processed the payment. |
| `data.status` | The transaction's current state — `pending` for mobile money, since the charge isn't settled yet. |

> The **envelope** `status` is `success` because the request was *initiated*
> successfully. The **`data.status`** (`pending`) is the transaction's actual
> state — mobile-money charges settle asynchronously once the payer approves.
> [Verify the payment](/docs/api/verify-payment) to get the final
> `success` / `failed` outcome (and to trigger your [callback](/docs/api/callbacks)).

## What can go wrong

If the switch can't initiate the payment — invalid input, no eligible provider,
or **every** provider declines (including a provider that declines with HTTP
`200`) — it returns `status: "error"` with a `4xx`/`5xx` code, never a `2xx`. The
full list of status codes and messages is documented in
[Responses & Errors](/docs/api/responses).
