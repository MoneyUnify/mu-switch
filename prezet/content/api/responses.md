---
title: Responses & Errors
excerpt: The consistent response envelope used by the switch API, plus every status code and error message you can expect.
date: 2026-06-27
category: API Reference
---

Every API response — success or failure — uses the same JSON envelope, so your
client can parse them uniformly.

## The response envelope

```json
{
  "status": "success | error",
  "message": "Human-readable description",
  "data": {}
}
```

| Field | Description |
| --- | --- |
| `status` | `"success"` for 2xx responses, `"error"` otherwise. |
| `message` | A human-readable summary of the result. |
| `data` | Result payload on success; usually an empty array on error. |

## Success

`200 OK` — the payment was initiated by a provider:

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

## Errors

| Status | Example `message` | Meaning |
| --- | --- | --- |
| `401` | `Unauthorized` | Missing Bearer token. |
| `401` | `Invalid API token` | Token does not match any account. |
| `422` | `The amount field is required.` | Validation failed for `amount`, `account_number`, or `country`. |
| `400` | `Providers not configured yet. Please configure at least 1 (one) provider` | Your account has no providers. |
| `400` | `No active providers support the requested country` | No active provider serves the requested `country`. |
| `400` | `Unsupported mobile operator` | The account number didn't map to a supported operator (provider-level). |
| `4xx` | *(provider message)* | The provider that processed the request returned an error; the switch surfaces the last provider error after fallback. |
| `500` | `No provider could process the payment request` | Every eligible provider failed. |

### Validation errors (`422`)

Validation responses include an `errors` object alongside the envelope:

```json
{
  "message": "The country field must be 2 characters.",
  "errors": {
    "country": ["The country field must be 2 characters."]
  }
}
```

## Handling fallback in your client

Because the switch tries providers sequentially, a non-200 response means **all**
eligible providers were exhausted (or the request was rejected before routing).
Recommended client handling:

- **`200`** — record `transaction_id` and `reference`; reconcile later.
- **`400` / `422`** — fix configuration or input; retrying unchanged won't help.
- **`401`** — refresh or correct your [API token](/docs/api-tokens).
- **`500`** — safe to retry after a short backoff; a provider may recover.

> Always send the `Accept: application/json` header so error and validation
> responses are returned as JSON.
