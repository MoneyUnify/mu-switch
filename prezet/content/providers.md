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
| **Credentials** | Driver-specific secret(s), stored in the provider's `config`. Each driver declares which fields it needs — **Lenco** asks for an API Key, **Airtel Money** asks for a Client ID and Client Secret. The dashboard renders the right inputs for the driver you pick. |
| **Supported Countries** | Tick the markets this provider should serve. Only the countries the chosen driver supports are shown; each provider's **currency** and any market-specific routing (e.g. MTN's target environment) are derived automatically from the country. |
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

## Built-in driver: Airtel Money

The platform also ships an **Airtel Money** driver (`AirtelController`) for
collections across Airtel Africa's footprint. Airtel's Open API uses OAuth2
**client-credentials**, so instead of a single API key you provide:

| Credential | Description |
| --- | --- |
| **Client ID** | Your Airtel Open API consumer key. |
| **Client Secret** | Your Airtel Open API consumer secret. |

The driver handles the rest internally — it exchanges the Client ID and Secret
for a short-lived **bearer access token** (cached until it nears expiry), then
calls Airtel's collection and status endpoints with it. The currency and country
headers are derived from the request country, so one provider covers every market
you tick.

Airtel Money is available across Airtel Africa's **14 markets**: Nigeria, Kenya,
Tanzania, Uganda, Rwanda, Zambia, Malawi, DR Congo, Congo-Brazzaville, Gabon,
Chad, Niger, Madagascar, and Seychelles.

To enable it, add a provider in the dashboard, choose the **Airtel Money**
driver, paste your Client ID and Client Secret, and tick the markets you serve.

## Built-in driver: MTN MoMo

The platform also ships an **MTN MoMo** driver (`MtnController`) for MoMo
Collections (request-to-pay). MTN authenticates in two layers, so you provide
just your **production** credentials:

| Credential | Description |
| --- | --- |
| **Collections Subscription Key** | The `Ocp-Apim-Subscription-Key` for your Collections product. |
| **API User ID** | The API User (UUID) created in the MoMo portal. |
| **API Key** | The API Key generated for that API User. |

The driver exchanges the API User + API Key (via Basic auth) for a short-lived
**bearer access token** (cached until it nears expiry) and uses it for the
collection and status calls.

You don't configure a target environment or currency — the driver derives MTN's
`X-Target-Environment`, the currency, and the international MSISDN format from the
request's **country** (e.g. `ZM → mtnzambia` / `ZMW`, `GH → mtnghana` / `GHS`).
So one MTN provider works across every MTN MoMo market you tick: Zambia, Uganda,
Ghana, Côte d'Ivoire, Cameroon, Rwanda, Benin, Guinea, Guinea-Bissau, Liberia,
Congo-Brazzaville, Nigeria, South Africa, Eswatini, and South Sudan.

Market data (name, currency, calling code) lives centrally in
`App\Support\Market`; MTN's per-country target environments live in the driver's
`TARGET_ENVIRONMENTS` map. To enable it, add a provider in the dashboard, choose
the **MTN MoMo** driver, paste the three credentials above, and tick the markets.

## Built-in driver: Lipila

The platform also ships a **Lipila** driver (`LipilaController`) for mobile-money
collections in Zambia (MTN, Airtel, and Zamtel). Lipila is a Zambian aggregator
that authenticates with a single secret key — there is no token exchange — so you
provide just:

| Credential | Description |
| --- | --- |
| **API Key** | Your Lipila secret key, sent as the `x-api-key` header. |

The driver initiates a collection (request to pay) against Lipila's production
host (`https://blz.lipila.io`), normalising the payer's number to the
international `260…` account format and using `ZMW`. The collection starts as
**pending** while the payer authorises on their handset; verification re-checks
the status (`Pending` → pending, `Successful` → success, `Failed` → failed).

To enable it, add a provider in the dashboard, choose the **Lipila** driver,
paste your API key, and tick Zambia.

## Built-in driver: M-Pesa (Kenya)

The platform ships an **M-Pesa (Kenya)** driver (`MpesaController`) for Safaricom
**Daraja STK Push** (M-Pesa Express / Lipa Na M-Pesa Online) — a push-to-pay
prompt the customer approves with their PIN. Daraja uses OAuth
client-credentials, so you provide:

| Credential | Description |
| --- | --- |
| **Consumer Key** | Your Daraja app consumer key. |
| **Consumer Secret** | Your Daraja app consumer secret. |
| **Business Short Code** | Your Paybill number (`BusinessShortCode`). |
| **Lipa Na M-Pesa Passkey** | The online passkey for that shortcode. |

The driver exchanges the key/secret for a short-lived **bearer token** (cached),
builds the timestamped `base64(Shortcode + Passkey + Timestamp)` password, and
calls `POST /mpesa/stkpush/v1/processrequest` against
`https://api.safaricom.co.ke`. The collection starts **pending** while the payer
enters their PIN; verification re-checks it with `POST /mpesa/stkpushquery/v1/query`
(`ResultCode 0` → success, `1032`/`1037`/… → failed, *still processing* → pending).

> M-Pesa (Kenya) is Safaricom-only and serves **Kenya** (`KE` / `KES`). It uses
> the **Paybill** transaction type (`CustomerPayBillOnline`).

To enable it, add a provider in the dashboard, choose the **M-Pesa (Kenya)**
driver, paste the four credentials above, and tick Kenya.

## Built-in driver: M-Pesa (Vodafone/Vodacom)

Outside Kenya, M-Pesa runs on Vodafone/Vodacom's **M-Pesa Open API** (G2,
`openapi.m-pesa.com`). The **M-Pesa (Vodafone/Vodacom)** driver
(`MpesaVodacomController`) does a C2B "single stage" push across those markets.
Authentication is a two-step RSA handshake, so you provide:

| Credential | Description |
| --- | --- |
| **API Key** | Your application's API key from the Open API portal. |
| **Public Key** | The Open API platform public key (used to encrypt the above). |
| **Service Provider Code** | Your shortcode (`input_ServiceProviderCode`). |

The driver RSA-encrypts the API key with the public key to fetch a **Session ID**
(`/getSession/`), re-encrypts that Session ID as the bearer for the push
(`/c2bPayment/singleStage/`), and verifies via `/queryTransactionStatus/`
(`Completed` → success, `Failed` → failed). The session is cached until shortly
before it expires.

Each market's path segment, country code and currency are derived from the
request country and live in the driver's `MARKETS` map:

| Country | Market segment | Currency |
| --- | --- | --- |
| Tanzania (`TZ`) | `vodacomTZN` | `TZS` |
| Ghana (`GH`) | `vodafoneGHA` | `GHS` |
| Mozambique (`MZ`) | `vodafoneMOZ` | `MZN` |
| Lesotho (`LS`) | `vodacomLES` | `LSL` |
| DR Congo (`CD`) | `vodacomDRC` | `USD` |

> Confirm your market's exact segment on the M-Pesa Open API portal before going
> live; the `MARKETS` map in `MpesaVodacomController` is the single place to add
> or correct a market.

To enable it, add a provider in the dashboard, choose the **M-Pesa
(Vodafone/Vodacom)** driver, paste the three credentials above, and tick the
markets you serve.

## Adding your own driver

Drivers are plain classes in `app/Http/Controllers/Providers` that implement
`App\Contracts\PaymentProviderInterface`:

```php
interface PaymentProviderInterface
{
    public function requestPayment(Request $request): JsonResponse;

    public function setProvider(PaymentProvider $provider): ?JsonResponse;

    public function verifyPayment(Transaction $transaction): JsonResponse;
}
```

Optionally expose constants the dashboard uses for discovery, defaults, and the
credential inputs to render:

```php
public const PROVIDER_NAME = 'YourGateway';
public const DEFAULT_COUNTRIES = 'ZM,MW';

// Which credential inputs the dashboard collects for this driver.
public const CONFIG_FIELDS = [
    ['key' => 'client_id', 'label' => 'Client ID', 'type' => 'text'],
    ['key' => 'client_secret', 'label' => 'Client Secret', 'type' => 'password'],
];
```

Keep any token/secret handling **private** inside the driver (see the Airtel
driver's access-token method) so operators only ever supply the credentials
declared in `CONFIG_FIELDS`.

Any class in that directory implementing the interface is automatically offered
as a selectable driver when you add a provider — no route or registration
changes required.
