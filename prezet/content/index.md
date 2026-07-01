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

## What does MoneyUnify do?

Think of it like a **universal adapter for mobile money and cards**. Normally, to
collect payments you have to plug into each provider separately — MTN, Airtel,
M-Pesa, Lenco, and so on — and manage them all yourself. MoneyUnify is the single
socket you plug into once: you ask it to collect money from a customer, and it
decides which provider to use behind the scenes. If one provider is down or
declines, it automatically tries the next until the payment goes through. One
connection, all providers.

That also fixes the everyday frustration of **failed payments**. Instead of your
app talking to a single provider and getting stuck when that provider is having a
bad day, it talks to one system that quietly routes each payment across several
providers, falling back instantly when one fails — so more payments succeed on
the first try. Every attempt is recorded in one dashboard, so you always know
what happened and why: fewer failed payments, less reconciliation headache.

It is **multi-currency and multi-country** by design. Providers advertise the
markets they serve, and the switch routes each request to a provider that can
settle it in the right country and currency. Cross-currency flows are treated as
first-class: every transaction records whether it crossed currencies and the FX
rate applied, so ongoing foreign-exchange movements are tracked and reconciled
correctly rather than silently lost — a foundation for collecting in one currency
and settling in another as your markets grow.

**Provision payment gateways and power multi-region apps from one endpoint.**
Because everything sits behind a single API — `POST /api/v1/payment/request` —
MoneyUnify can act as your payment-gateway layer: point any application or website
at that one endpoint, and add, remove, or re-order the underlying providers from
the dashboard without touching a line of your code. That makes it a practical way
to roll out payments across several countries at once — a fintech offering "pay
with mobile money" across markets, a SaaS billing customers across regions, or an
e-commerce checkout that needs MTN in one country and M-Pesa in another — all
through the same integration.

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
