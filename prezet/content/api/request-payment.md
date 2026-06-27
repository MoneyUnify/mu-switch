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

| Field | Type | Rules | Description |
| --- | --- | --- | --- |
| `amount` | number | required, numeric, `>= 0.01` | The amount to collect. |
| `account_number` | string | required | The payer's account / mobile-money number. |
| `country` | string | required, 2 letters, one of `ZM`, `MW` | ISO country code used for provider routing. |

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
    "country": "ZM"
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
    "reference": "lenco_ref_abc123"
  }
}
```

| Field | Description |
| --- | --- |
| `transaction_id` | The switch's own UUID for the transaction. Use it to reconcile against your records. |
| `reference` | The reference returned by the provider that processed the payment. |

> A successful response means the collection was **initiated** with a provider.
> For mobile money the payer may still need to approve the charge; track the
> final state via the provider and your transaction records.

## What can go wrong

The switch validates input, ensures you have eligible providers, and reports the
underlying provider error if every attempt fails. The full list of status codes
and messages is documented in [Responses & Errors](/docs/api/responses).
