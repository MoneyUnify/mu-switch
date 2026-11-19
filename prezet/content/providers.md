---
title: Payment Providers
excerpt: Configure the payment gateways the switch routes through, including credentials, supported countries, active state, and fallback order.
date: 2026-06-27
category: Configuration
---

A **provider** is a configured payment gateway that the switch can route requests
to. Each provider is scoped to your account, so the providers you configure are
the only ones the switch will ever use for your transactions.

> Looking for which countries and mobile networks each driver reaches? See the
> [Coverage reference](/docs/api/coverage).

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

### Payouts through Lenco

Lenco also sends **payouts** to mobile wallets in Zambia (MTN, Airtel, Zamtel)
and Malawi (Airtel, TNM), from one of your Lenco accounts. Payouts also power
[cross-border transfers](/docs/api/cross-border-transfers), where the sender
Payouts work automatically with your Lenco API key: the switch automatically
discovers your active Lenco funding account and silently registers recipient
mobile-money numbers on the fly, requiring no manual account or recipient ID setup.

| | |
| --- | --- |
| **Endpoint** | `POST /transfers/mobile-money`, checked with `GET /transfers/status/{reference}` |
| **Reference** | The switch's `transaction_id`, so a payout can always be looked up |
| **Network** | Detected from the number in Zambia. In Malawi, only `099…` (Airtel) and `088…` (TNM) are detected. For other numbers, send `network` with [Send a Payout](/docs/api/payouts), or the payout is refused rather than sent to a guessed network. |
| **Fee** | Estimated at K8.50 per transfer. |
| **Unknown outcomes** | A Lenco server error, or a "duplicate reference" (an earlier attempt already reached Lenco), stops the switch: verify the payout before retrying. A transfer Lenco never created is marked failed on verify. |

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

## Built-in driver: cGrate (Konse Konse 543)

The platform ships a **cGrate** driver (`CgrateController`) for mobile-money
collections in Zambia through cGrate's **Konse Konse (543)** merchant service —
covering MTN, Airtel, and Zamtel wallets. cGrate's Konik web service is
**SOAP/WSDL** (`<base-url>/Konik/KonikWs`) secured with a WS-Security
UsernameToken, so you provide:

| Credential | Description |
| --- | --- |
| **Base URL** | Your cGrate host (e.g. `https://543.cgrate.co.zm`). The `/Konik/KonikWs` service path is appended automatically. |
| **API Username** | Your cGrate account username (WS-Security UsernameToken). |
| **API Password** | Your cGrate account password. |

The driver builds the SOAP envelopes itself and posts them with the
`application/soap+xml` content type the official Konik Postman collection uses —
no `php-soap` extension required — so cGrate calls appear in the provider call
logs like every other gateway (with the password redacted). TLS verification is
disabled for these calls because cGrate's endpoints are commonly served with
certificates the default trust store rejects.

The two operations used are `processCustomerPayment` (request to pay —
`transactionAmount`, `customerMobile`, `paymentReference`) and
`queryCustomerPayment` (verification — by `paymentReference`).

A distinctive behaviour to know: cGrate's `processCustomerPayment` is
**synchronous** — the payer confirms the USSD prompt while the call is held
open. A `responseCode` of `0` therefore means the payment **completed** (the
API response reports `status: success` immediately), while `8` means it is
still processing (reported as `pending` — re-check it with the
[verify endpoint](/docs/api/verify-payment), which maps cGrate's
`queryCustomerPayment` result onto the final status).

To enable it, add a provider in the dashboard, choose the **cGrate (Konse
Konse 543)** driver, enter your base URL, username and password, and tick
Zambia.

## Built-in driver: Ting by Cellulant

The platform ships a **Ting** driver (`TingController`) for **Tingg by
Cellulant** — a pan-African gateway covering roughly **25 markets** across East,
West, and Central Africa. Tingg's Checkout API (v3) authenticates with OAuth
client-credentials plus an API-key header, so you provide:

| Credential | Description |
| --- | --- |
| **API Key** | Your Tingg `apiKey` (sent as a header on every call). |
| **Client ID** | Your OAuth client id. |
| **Client Secret** | Your OAuth client secret. |
| **Service Code** | Your registered Tingg service code (identifies the biller). |

The driver exchanges the Client ID/Secret for a short-lived **bearer token**
(cached), then calls Tingg's combined **`checkout-charge`** endpoint with
`is_offline: true` to push an **STK/USSD prompt straight to the payer's
handset** — no hosted payment link, exactly like the other push-to-pay
providers. The collection starts **pending**; verification calls Tingg's query
endpoint (`request_status_code` `178` → success, `99`/`129` → failed, otherwise
pending). Just as with every provider, a call needs only the **country**,
**phone number**, and amount — the customer name defaults to an unnamed customer
when not supplied.

Country codes are sent in ISO **alpha-3** form (e.g. `KE → KEN`), derived from
the central `App\Support\Market` registry along with the currency and E.164
MSISDN.

> **Per-market operator codes.** Tingg routes the STK prompt to a specific
> mobile-money operator by a code it assigns per operator (e.g. `SAFKE` for
> Safaricom Kenya, `VODACOMTZ` for Vodacom Tanzania) — there is no derivable
> global standard. So **one Ting provider can serve many markets**: when you tick
> a market in the provider dialog, an input appears to enter that market's
> **Payment Option Code**. At request time the switch picks the code for the
> request's `country`. Get each code from your Tingg dashboard (Fetch Payment
> Options).

To enable it, add a provider in the dashboard, choose the **Ting by Cellulant**
driver, paste the four credentials above, tick the markets you serve, and enter
each market's operator payment-option code.

## Built-in driver: Flutterwave

The platform ships a **Flutterwave** driver (`FlutterwaveController`) for v3
**mobile-money collections** (push to pay) across Flutterwave's markets:
**Kenya** (M-Pesa), **Ghana**, **Uganda**, **Rwanda**, **Zambia**, **Tanzania**,
and francophone **Cameroon**, **Côte d'Ivoire**, **Senegal** and **Burkina
Faso**. Flutterwave authenticates with a single Bearer secret key, so you
provide:

| Credential | Description |
| --- | --- |
| **Secret Key** | Your Flutterwave secret key (`FLWSECK-…`). |

The driver charges the right endpoint for each market — `POST
/v3/charges?type=…` (`mpesa` for Kenya, `mobile_money_ghana`,
`mobile_money_uganda`, `mobile_money_rwanda`, `mobile_money_zambia`,
`mobile_money_tanzania`, or `mobile_money_franco`) — deriving the `type`,
currency, and E.164 MSISDN from the request's country. The collection starts
**pending**; verification calls `GET /v3/transactions/{id}/verify`
(`successful` → success, `failed` → failed, otherwise pending). As with every
provider, a call needs only the country, phone number, and amount — the customer
name/email default when not supplied.

> **Networks (Ghana, Uganda, Zambia).** Flutterwave requires the mobile operator
> for these markets. The driver picks it from the caller's optional `network`
> field, otherwise infers it from the phone number's dialling prefix (e.g. a
> Ghana `024…` number → MTN), falling back to the market's primary operator.

To enable it, add a provider in the dashboard, choose the **Flutterwave**
driver, paste your secret key, and tick the markets you serve.

## Built-in driver: pawaPay

The platform ships a **pawaPay** driver (`PawapayController`) for mobile-money
collections ("deposits") across **pawaPay's official 20 markets**: Benin,
Burkina Faso, Cameroon, Congo-Brazzaville, DR Congo, Côte d'Ivoire, Ethiopia,
Gabon, Ghana, Kenya, Lesotho, Malawi, Mozambique, Nigeria, Rwanda, Senegal,
Sierra Leone, Tanzania, Uganda and Zambia. pawaPay authenticates with a single
Bearer API token, so you provide:

| Credential | Description |
| --- | --- |
| **API Token** | Your pawaPay API token (sent as `Authorization: Bearer …`). |

The driver uses **pawaPay API v2**. For each collection it first calls
pawaPay's **predict-provider** endpoint (`POST /v2/predict-provider`) to find
which mobile-money provider the payer's number belongs to (e.g.
`MTN_MOMO_ZMB`) and get the number back sanitised, then initiates the deposit
(`POST /v2/deposits`, the payer as an `MMO` account), which sends a **push
prompt straight to the payer's handset**. The collection starts **pending**
(status `ACCEPTED`); verification polls the deposit status
(`GET /v2/deposits/{depositId}`: `COMPLETED` is success, `FAILED` is failed,
anything else, or not found yet, stays pending). As with every provider, a call
needs only the country, phone number and amount.

> **Provider selection.** pawaPay routes to a specific provider by its code.
> The driver derives it from the phone number (pawaPay's own prediction), so no
> per-market configuration is needed; a caller may still pass `provider` (or
> the older `correspondent`) to override it.

**Payouts** (`POST /v2/payouts`) use our transaction id as the `payoutId`, so a
repeated request is ignored by pawaPay (`DUPLICATE_IGNORED`) instead of paying
twice. A refusal's `failureReason` is kept and shown on the transfer. A payout
pawaPay accepted is never treated as lost; one whose request got no answer only
counts as never received (`NOT_FOUND`) after two minutes, so a slow pawaPay
can't lead to a second payout elsewhere. The text shown to the recipient
(`customerMessage`) is the narration, cut to pawaPay's 4 to 22 letters, digits
and spaces.

To enable it, add a provider in the dashboard, choose the **pawaPay** driver,
paste your API token, and tick the markets you serve.

pawaPay also sends **payouts** to mobile wallets. See
[Send a Payout](/docs/api/payouts). Its **Environment** setting chooses `live`
(the default) or `sandbox`. Use sandbox with a sandbox API token to test both
collections and payouts.

### pawaPay Sandbox Test Numbers (Zambia)

In pawaPay's sandbox, payouts behave based on specific test numbers. Note that arbitrary numbers (including those ending in `1111` or `11111`) do not fail on pawaPay—they will complete. Always use these exact numbers to test outcomes:

| Outcome | Network | Local (10 digits) | International (12 digits) | Notes |
| --- | --- | --- | --- | --- |
| **Completed** | MTN | `0763456789` | `260763456789` | Payout completes |
| | Airtel | `0973456789` | `260973456789` | Payout completes |
| | Zamtel | `0953456700` | `260953456700` | Payout completes |
| **Failed** (Refunded) | MTN | `0763456079` | `260763456079` | Refused ("exceeds max balance"); triggers PayPal refund |
| | Airtel | `0973456089` | `260973456089` | Refused ("exceeds max balance"); triggers PayPal refund |
| **Pending** | MTN | `0763456129` | `260763456129` | Stays submitted for status polling |
| | Airtel | `0973456129` | `260973456129` | Stays submitted for status polling |

## Built-in driver: Simulated Payouts (sandbox only)

A payout driver for local demos and testing when no external payout provider is configured. It is never available in production and
isn't counted in coverage. It needs no credentials. The recipient number decides
the outcome (supports 10-digit local or 12-digit international format):

| Outcome | Full Local (10 digits) | Full International (12 digits) | Suffix Rule | Result |
| --- | --- | --- | --- | --- |
| **Completed** | `0977123456` | `260977123456` | Any normal number | Accepted and completed on verification |
| **Refused** | `0977120000` | `260977120000` | Ends in `0000` (or custom setting) | Refused; switch tries fallback or refunds |
| **Fails Later** | `0966551111` | `260966551111` | Ends in `1111` | Accepted initially, fails on verification; refunds |
| **Timeout / Unknown** | `0977129999` | `260977129999` | Ends in `9999` | No definite answer; requires verification |

It charges an illustrative 1% payout fee, so fees and the ledger show up in demos.

## Built-in driver: DPO Pay

The platform ships a **DPO Pay** driver (`DpoController`) for **direct
mobile-money push to pay** across DPO Group's markets — no hosted redirect, the
payer approves the prompt on their handset. DPO's v6 API is XML, so the driver
builds the envelopes itself (no `php-soap` extension) and every call flows
through the provider call logs. You provide:

| Credential | Description |
| --- | --- |
| **Company Token** | Your DPO account's `CompanyToken`. |
| **Service Type** | The DPO service type id configured for your account (e.g. `5525`). |

Each collection is a two-step, server-to-server sequence against
`https://secure.3gdirectpay.com/API/v6/`: **`createToken`** creates the
transaction (returning a `TransToken`), then **`ChargeTokenMobile`** charges it —
sending the **STK/USSD prompt straight to the payer**. The collection starts
**pending**; verification calls **`verifyToken`** (`Result` `000` → success,
`904`/`999` → failed, otherwise pending — e.g. `900` "not paid yet"). As with
every provider, a call needs only the country, phone number and amount.

DPO mobile money is available in **Kenya, Tanzania, Uganda, Rwanda, Zambia,
Ghana, Malawi, Nigeria** and **Mozambique**.

> **Per-market operator codes.** DPO routes the prompt to a specific
> mobile-money operator by an **MNO code** it assigns per operator (e.g.
> `AirtelZM`, `MPESA`) — there is no derivable global standard. So **one DPO
> provider can serve many markets**: when you tick a market in the provider
> dialog, an input appears to enter that market's **MNO code**. At request time
> the switch picks the code for the request's `country` (a caller may also pass
> an explicit `mno` to override it). Get each code from your DPO dashboard
> (Get Mobile Payment Options).

To enable it, add a provider in the dashboard, choose the **DPO Pay** driver,
paste your Company Token and Service Type, tick the markets you serve, and enter
each market's MNO code.

## Built-in driver: MobiPay

The platform ships a **MobiPay** driver (`MobipayController`) for **mobile-money
push to pay in Malawi** through MobiPay's Malawi gateway (the *Malipo* product,
`app.malipo.mw`) — covering **Airtel Money** and **TNM Mpamba**. MobiPay
authenticates with an API key and an app id, so you provide:

| Credential | Description |
| --- | --- |
| **API Key** | Your Malipo `x-api-key` (from the MobiPay dashboard). |
| **App ID** | Your Malipo project/app id (`x-app-id`). |

The driver initiates a collection with `POST /api/v1/paymentrequest` — which
pushes a **request-to-pay prompt straight to the payer's wallet** — and confirms
it by polling `GET /api/v1/payment/enquire/{reference}` (`Completed` → success,
`Failed` → failed, otherwise pending). As with every provider, a call needs only
the country, phone number and amount.

> **Operator selection.** MobiPay routes to an operator with a numeric `bankId`
> (`1` = Airtel Money, `2` = TNM Mpamba). The driver derives it from the payer's
> number (Airtel `099x`/`098x`, TNM `088x`), falling back to Airtel; a caller may
> override it with an explicit `bank_id` or `operator` (`airtel`/`tnm`).

MobiPay's mobile-money push is **Malawi-only** — MobiPay's other market (Namibia,
via *Mobipaid*) is card- and payment-link-based, not wallet push-to-pay.

To enable it, add a provider in the dashboard, choose the **MobiPay** driver,
paste your API Key and App ID, and tick Malawi.

## Built-in driver: Kazang

The platform ships a **Kazang** driver (`KazangController`) for **mobile-money
request-to-pay** through Kazang's **ContentReady** API — a wallet debit that
pushes an approval prompt to the payer's handset and credits your Kazang wallet
once they approve. ContentReady is session-based, so you provide your API user
credentials plus the operator product IDs Kazang issues you:

| Credential | Description |
| --- | --- |
| **API Username** | Your ContentReady API user (client id or account number). |
| **API Password** | Your ContentReady API password. |
| **API Channel** | The access channel Kazang created for you. |
| **API Host** | Your ContentReady host (e.g. `testapi.kazang.net` for the test server). |
| **MTN MoMo Product ID** | The `product_id` for the MTN wallet-debit product (Zambia). |
| **Airtel Pay Product ID** | The `product_id` for the Airtel Pay product (Zambia). |
| **Zamtel Money Product ID** | The `product_id` for the Zamtel Money Pay product (Zambia). |

The driver logs in with `authClient` (caching the `session_uuid` for the session
lifetime) and posts to `https://<host>/apimanager/api_rest/v1/<method>`.
Zambia's three major wallets are implemented exactly as documented:

- **MTN MoMo** — `mtnDebit` creates the pending debit and pushes the MTN prompt;
  verification runs `mtnDebitApproval` → `mtnDebitApprovalConfirm` (keyed on the
  stable `supplier_transaction_id`).
- **Airtel Pay** — `airtelPayPayment` → `airtelPayPaymentConfirm` pushes the
  Airtel prompt; verification runs `airtelPayQuery` → `airtelPayQueryConfirm`
  (keyed on `airtel_reference`, which Airtel lets you retry until it completes).
- **Zamtel Money** — `zamtelMoneyPay` prompts the payer for their PIN on
  Zamtel's USSD interface; `zamtelMoneyPayConfirm` returns the final "Payment
  Successful" receipt, completing the payment immediately. If the confirm isn't
  successful yet the collection stays pending and verification retries it.

The operator is chosen from the payer's prefix (MTN `096x`/`076x`, Airtel
`097x`/`077x`, Zamtel `095x`/`075x`), overridable with an explicit `operator`
(`mtn`/`airtel`/`zamtel`). Amounts are converted to the currency's smallest
unit (K5.00 → `500`) automatically, and a call needs only the country, phone
number and amount.

> **Configurable markets (South Africa, Namibia, Botswana).** Kazang's other
> markets ride the **same** ContentReady session and envelope — one `authClient`
> login, one JSON POST per method, an operator-assigned `product_id` — but each
> wallet's *pay-direction method names* are assigned per account (e.g. Namibia's
> MTC Maris family). So these markets are **config-driven**: ticking one in the
> provider dialog opens an **integration dialog** asking for that wallet's
> Product ID and method names (payment, optional confirm, optional status
> query/confirm, plus the phone-parameter and reference-field names, which
> default to `wallet_msisdn` and `transaction_reference_str`). Get these from
> your Kazang product list (`productList`) or account manager. With a status
> query configured the push settles on verification (the Airtel pattern);
> without one the confirmed receipt is final (the Zamtel/MTC pattern).

> **Pending is the safe default.** To never mis-report money, the switch
> promotes a transaction to **success only on an explicit success** of the
> flow's final step, and otherwise leaves it **pending** (re-check it with the
> [verify endpoint](/docs/api/verify-payment)); a failed *initiation* is
> reported as failed so the switch can fall back. A transaction that has already
> settled is never re-queried or downgraded.

To enable it, add a provider in the dashboard, choose the **Kazang** driver,
paste your credentials, host and product IDs, tick the markets you serve, and
complete the integration dialog for any non-Zambia market.

## Built-in driver: PayPal

The platform ships a **PayPal** driver (`PaypalController`) for **hosted
checkout** through PayPal's Orders v2 API. The payer approves the payment on
PayPal (wallet or card), so the switch returns a link instead of pushing a
prompt to a phone. You provide:

| Credential | Description |
| --- | --- |
| **Client ID** | Your PayPal REST app's client ID. |
| **Client Secret** | The matching secret. |
| **Environment** | `sandbox` for testing, `live` for real payments. |
| **Webhook ID** *(optional)* | The ID of the webhook you registered in the PayPal app (see below). |

Create the app at [developer.paypal.com](https://developer.paypal.com/dashboard/applications)
to get the client ID and secret.

**How a payment flows**

1. Call [Request a Payment](/docs/api/request-payment) with a PayPal currency,
   e.g. `"currency": "USD"`. PayPal only takes part when the request names a
   currency it supports. Without one, the switch charges the country's own
   currency, which PayPal can't settle.
2. The response's `data.checkout_url` is where the payer approves the payment.
   The transaction is **pending** until then.
3. When the payer returns, the switch **captures** the order and sends them to
   your `return_url` (or shows a confirmation page if you didn't pass one).
   [Verifying](/docs/api/verify-payment) also captures an approved order, so
   you never depend on the payer's browser coming back.

Every call is idempotent (PayPal's `PayPal-Request-Id`), so a retry never
charges twice. Orders the payer abandons or cancels end **failed**, and a
cancelled order is never captured later.

**Webhooks (recommended)**

1. On **Providers**, edit the PayPal provider. The dialog shows its
   **Webhook URL** (`https://your-domain.com/api/webhooks/paypal/{provider_id}`)
   with a **Copy** button.
2. In your PayPal app, choose **Add Webhook**, paste the URL and subscribe to:
   `CHECKOUT.ORDER.APPROVED`, `PAYMENT.CAPTURE.COMPLETED`,
   `PAYMENT.CAPTURE.DENIED`, `PAYMENT.CAPTURE.REFUNDED` and
   `PAYMENT.CAPTURE.REVERSED`.
3. Paste the new webhook's ID into the provider's **Webhook ID** and save.

Each delivery's signature is checked with PayPal before anything happens, and
duplicate deliveries are ignored. The switch answers straight away and handles
the event in the background (retrying if PayPal can't be reached): it re-reads
the order from PayPal, so the transaction settles even if neither the payer nor
your app comes back, and refunds made in PayPal's dashboard are recorded. If
PayPal reverses a payment that funded a transfer (a chargeback or bank
reversal), the transfer's timeline gets a note to follow up with the sender.

PayPal can charge AUD, BRL, CAD, CNY, CZK, DKK, EUR, GBP, HKD, HUF, ILS, JPY,
MXN, MYR, NOK, NZD, PHP, PLN, SEK, SGD, CHF, THB, TWD and USD. HUF, JPY and TWD
amounts must be whole numbers.

**Fees.** Before capture, PayPal's fee is estimated from its published Checkout
rate: 3.49% plus a fixed fee in the charged currency (0.49 USD, 0.39 EUR, 0.39
GBP and so on). Once captured, the switch records the **exact** fee PayPal
reports, and the transaction is marked **Actual**. If your PayPal rate differs,
set a `fee_schedule` on the provider. Use [Quote a Payment](/docs/api/quote-payment)
to see the fees before charging.

### PayPal FX (exchange rates)

When the PayPal business account has **FX as a Service**, the account's quotes
use **PayPal's own exchange rates** (USD, EUR, GBP and other PayPal currencies
into 100+ currencies, including ZMW, KES, GHS and NGN), and payments in another
currency are converted by PayPal at a locked rate. Two optional settings:

| Setting | Default | Description |
| --- | --- | --- |
| **Exchange rates** | `paypal` | `paypal` uses PayPal's rates once FX is enabled on the account, and MoneyUnify's until then; `moneyunify` always uses MoneyUnify's. |
| **PayPal settlement currency** | `USD` | The currency the PayPal account holds payments in. |

See [PayPal FX](/docs/api/fx#paypal-fx) for how quotes, locking and the
fallback work.

### PayPal's button (JS SDK v6)

Merchant pay pages show **PayPal's own button**, from PayPal's JS SDK v6, so
the customer approves in PayPal's window without leaving the page. The page
loads the SDK with the collecting PayPal account's **client ID** (public; the
secret never leaves the server). The order is created on the server, PayPal's
window approves it, and the server captures it before showing the receipt:

1. `POST /pay/{merchant}/order` (or `/pay/l/{link}/order`) creates the order
   and returns its `order_id`.
2. PayPal's window opens from the click, and the customer approves.
3. `POST /pay/receipt/{reference}/capture` captures it; the merchant is then
   settled as usual.

If the SDK can't load, or PayPal isn't available for the payer's currency, the
page shows the classic **Pay with PayPal** button, which redirects to PayPal.
**Debit or credit card** keeps using PayPal's guest card checkout.

### Guest card checkout

Payers don't need a PayPal account. Pass `"pay_with": "card"` to
[Request a Payment](/docs/api/request-payment), a cross-border transfer or a
merchant charge, and PayPal opens on its **guest debit or credit card form**
(`landing_page: GUEST_CHECKOUT`) instead of its login. The pay links and the
Send Money page offer both: **Pay with PayPal** and **Debit or credit card**.

The card is charged by PayPal, so nothing else changes: the same capture,
webhooks, refunds, receipt and payout. Where PayPal can't offer guest checkout
(the payer's country, or **PayPal account optional** is off in the business
account's *Website preferences*), PayPal shows its usual login, and the payment
goes through as it always does.

### Refund emails

When a refund completes (from the dashboard, the API, PayPal itself, or a
cross-border transfer that couldn't be delivered), the payer is emailed once
with the amount, the reference and their PayPal-verified receipt. The email goes
to the address given for the payment; payments without one aren't emailed.

### PayPal-verified receipts

After paying, the payer sees a receipt built from **PayPal's own record** of
the payment, re-checked with PayPal (at most once a minute):

- PayPal's **transaction ID** (the capture) and order ID, and the time PayPal
  recorded the payment;
- the payer as PayPal identified them (name, masked email, verified account);
- the status (**Completed**, **Partially refunded** or **Refunded**, including
  refunds made in PayPal) and **seller protection**;
- for cross-border and merchant payments, what the recipient receives and the
  delivery status.

PayPal's fee is never shown to the payer. The receipt can be printed or saved
as a PDF, and has a permanent signed link (`/receipts/paypal/{transaction}`).
PayPal also emails the payer its official receipt; this page says so and points
to their PayPal activity, where the same transaction ID appears. Merchant
customers see the same details on their live receipt page.

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
