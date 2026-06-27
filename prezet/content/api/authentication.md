---
title: Authentication
excerpt: Authenticate every API request with your account's API token sent as a Bearer token.
date: 2026-06-27
category: API Reference
---

The switch API uses **Bearer token** authentication. Every request must include
your [API token](/docs/api-tokens) in the `Authorization` header.

## Header

```http
Authorization: Bearer YOUR_API_TOKEN
```

The token is resolved to your account, and that account's
[providers](/docs/providers) are the only ones used to route the request.

## Example

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

## Authentication errors

| Status | `message` | Cause |
| --- | --- | --- |
| `401` | `Unauthorized` | No `Authorization: Bearer` token was sent. |
| `401` | `Invalid API token` | The token does not match any account. |

Both follow the standard [error envelope](/docs/api/responses):

```json
{
  "status": "error",
  "message": "Invalid API token",
  "data": []
}
```

> Always send `Accept: application/json` so validation and error responses are
> returned as JSON rather than a redirect.
