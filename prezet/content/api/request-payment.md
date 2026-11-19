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
| `country` | string | required, 2 letters, a supported market | ISO country code used for provider routing. See [Coverage](/docs/api/coverage) for the 32 markets. |

### Optional fields

| Field | Type | Rules | Description |
| --- | --- | --- | --- |
| `callback_url` | string | nullable, valid URL | A webhook URL. Once you [verify](/docs/api/verify-payment) the transaction and it settles (succeeds or fails), the switch POSTs the final result here exactly once. |
| `currency` | string | nullable, 3 letters | Charge in this currency instead of the country's own (e.g. `USD` via PayPal). Only providers that can charge it are tried, so a fallback never switches currency. |
| `email` | string | nullable, valid email | The payer's email, passed to providers that accept one (PayPal pre-fills its login with it). |
| `return_url` | string | nullable, valid URL | For hosted checkouts (PayPal): where the payer is sent after paying or cancelling, with `transaction_id` and `status` appended to the query string. |
| `pay_with` | string | nullable, `paypal` or `card` | PayPal only: `card` opens PayPal on its guest debit or credit card form, for payers without a PayPal account. |
| `allow_fx` | boolean | nullable | Allow providers that charge in another currency (e.g. PayPal for an African currency). |
| `fx_quote_id` | string | nullable | Use this locked [FX quote](/docs/api/fx) (implies `allow_fx`). |

> A country is accepted when it's one of the switch's markets; a payment only
> goes through if one of your active providers serves it.

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
| `data.checkout_url` | Hosted checkouts only (PayPal): send the payer here to approve the payment. |

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
