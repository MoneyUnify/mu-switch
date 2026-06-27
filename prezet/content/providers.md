---
title: Payment Providers
excerpt: Configure the payment gateways the switch routes through, including credentials, supported countries, active state, and fallback order.
date: 2026-06-27
category: Configuration
---

A **provider** is a configured payment gateway that the switch can route requests
to. Each provider is scoped to your account, so the providers you configure are
the only ones the switch will ever use for your transactions.

## Managing providers

Providers are managed from the **Providers** page in the dashboard
(`/providers`). For each provider you configure:

| Field | Description |
| --- | --- |
| **Name** | A unique, human-friendly label (e.g. `Lenco Production`). |
| **Driver** | The implementation class that talks to the gateway's API. Drivers are auto-discovered from `app/Http/Controllers/Providers`. |
| **API Key** | The secret credential issued by the gateway. Stored in the provider's `config`. |
| **Supported Countries** | A comma-separated list of ISO country codes (e.g. `ZM,MW`) the provider can serve. |
| **Active** | Whether the provider participates in routing. Inactive providers are skipped. |
| **Logo URL** | Optional logo shown in the dashboard. |

## How routing and fallback use this configuration

When a payment request arrives, the switch uses your provider configuration to
decide where to send it:

1. **Active only.** Providers with **Active** turned off are skipped entirely.
2. **Country match.** Only providers whose **Supported Countries** include the
   request's `country` are considered.
3. **Sequential fallback.** The remaining providers are tried one after another.
   The first provider to return a successful response wins; if one fails, the
   switch moves on to the next.

If no active provider supports the requested country, the request is rejected
before any gateway is called. See [How the Switch Works](/docs/api/overview)
for the full routing flow.

## Built-in driver: Lenco

The platform ships with a **Lenco** driver (`LencoController`) for mobile-money
collections in Zambia (`ZM`) and Malawi (`MW`). It:

- Resolves the mobile operator from the account number prefix (MTN, Airtel,
  Zamtel, TNM).
- Looks up the account holder's name and records the customer and account.
- Initiates a mobile-money collection and persists a transaction with the
  provider's reference.

To enable it, add a provider in the dashboard, choose the **Lenco** driver, paste
your Lenco API key, and set the supported countries to `ZM,MW`.

## Adding your own driver

Drivers are plain classes in `app/Http/Controllers/Providers` that implement
`App\Contracts\PaymentProviderInterface`:

```php
interface PaymentProviderInterface
{
    public function requestPayment(Request $request): JsonResponse;

    public function setProvider(PaymentProvider $provider): ?JsonResponse;
}
```

Optionally expose two constants the dashboard uses for discovery and defaults:

```php
public const PROVIDER_NAME = 'YourGateway';
public const DEFAULT_COUNTRIES = 'ZM,MW';
```

Any class in that directory implementing the interface is automatically offered
as a selectable driver when you add a provider — no route or registration
changes required.
