---
title: How the Switch Works
excerpt: One endpoint in, automatic provider fallback behind the scenes. Understand how the switch filters and tries providers until a payment succeeds.
date: 2026-06-27
category: API Reference
---

The defining idea of MoneyUnify Switch is simple: **your application calls one
endpoint, and the switch handles the rest.** You never call individual providers
directly — the switch selects them, tries them in order, and falls back
automatically.

## The single entry point

All payment collection goes through one endpoint:

```http
POST /api/v1/payment/request
```

Your integration targets this endpoint and nothing else. Adding, removing, or
reordering providers happens entirely in the dashboard and never changes your
client code.

## The routing pipeline

When a request arrives, the switch runs it through the following pipeline:

1. **Authenticate.** The Bearer token is resolved to your account. Without a
   valid token the request is rejected with `401`. See
   [Authentication](/docs/api/authentication).
2. **Validate.** The request body is validated (`amount`, `account_number`,
   `country`). Invalid input returns `422`.
3. **Check providers exist.** If your account has no providers configured, the
   request returns `400` asking you to configure at least one.
4. **Filter by eligibility.** The switch keeps only providers that are both:
   - **Active**, and
   - configured to support the request's **country**.

   If none qualify, it returns `400` — *"No active providers support the
   requested country."*
5. **Try in sequence (fallback).** The eligible providers are attempted one at a
   time:
   - The first provider to return a **success (HTTP 200)** response ends the
     pipeline — that response is returned to you.
   - If a provider errors or declines, the switch records the error and moves on
     to the next provider.
6. **Exhausted.** If every eligible provider fails, the switch returns the last
   error it saw, or a generic `500` if none could process the request.

## What fallback means in practice

Because the switch tries providers sequentially until one succeeds:

- A single provider's downtime or decline does **not** fail the customer's
  payment if another eligible provider can complete it.
- You control the candidate pool and behaviour by toggling providers
  **Active/Inactive** and setting their **Supported Countries**.

> **Idempotency note:** each attempt is a distinct call to the underlying
> gateway, and the switch persists a transaction record for attempts it makes.
> Design your reconciliation around the returned `transaction_id` and provider
> `reference`.

## Response shape

Every response — success or error — uses a consistent envelope. See
[Responses & Errors](/docs/api/responses) for the full reference, and
[Request a Payment](/docs/api/request-payment) for the endpoint's parameters
and examples.
