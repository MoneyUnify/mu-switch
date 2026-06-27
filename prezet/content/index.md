---
title: Introduction
excerpt: MoneyUnify Switch is a self-hosted payment switch that routes a single payment request across multiple providers with automatic fallback.
date: 2026-06-27
category: Getting Started
---

**MoneyUnify Switch** (`mu-switch`) is a self-hosted payment switch. It gives your
business a single, stable API to collect payments while it transparently routes
each request across the payment providers you have configured — automatically
falling back to the next provider when one is unavailable or declines the
transaction.

## Why a switch?

Mobile-money and card providers fail, throttle, or lack coverage in certain
countries. Integrating each one directly couples your application to their quirks
and downtime. The switch inverts that relationship:

- **One endpoint to integrate.** Your application calls a single endpoint —
  `POST /api/v1/payment/request` — and never changes when you add or remove a
  provider.
- **Automatic fallback.** The switch tries each eligible provider in turn until
  one succeeds, so a single provider outage doesn't become your outage.
- **Country-aware routing.** Providers advertise the countries they support, and
  the switch only routes a request to providers that can serve it.
- **You own the data.** It is self-hosted: providers, credentials, customers, and
  transactions all live in your own database.

## How it fits together

| Concept | Description |
| --- | --- |
| **Provider** | A configured payment gateway (e.g. Lenco) with credentials and supported countries. Managed from the dashboard. |
| **Switch** | The routing engine behind `POST /api/v1/payment/request` that filters and tries providers in order. |
| **API Token** | The bearer token that authenticates your server-to-server API calls. |
| **Transaction** | A persisted record of every payment attempt, including provider responses and status. |

## Next steps

1. [Install the platform](/docs/installation) on your own infrastructure.
2. [Configure one or more payment providers](/docs/providers).
3. Grab your [API token](/docs/api-tokens) and start
   [requesting payments](/docs/api/request-payment).
