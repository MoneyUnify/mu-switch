# MoneyUnify Switch

![MoneyUnify Logo](public/moneyunify-logo-horizontal.png)

## Table of Contents

- [Introduction and Objective](#introduction-and-objective)
  - [What does MoneyUnify do?](#what-does-moneyunify-do)
  - [Documentation](#documentation)
- [Supported Countries and Mobile Networks](#supported-countries-and-mobile-networks)
- [Requirements](#requirements)
  - [Installing PHP and its extensions](#installing-php-and-its-extensions)
- [Local Setup](#local-setup)
- [Documentation Site (`/docs`)](#documentation-site-docs)
- [API Documentation & Consumption](#api-documentation--consumption)
- [Authors](#authors)
- [Contributing](#contributing)
- [Show Your Support](#show-your-support)
- [License](#license)

## Introduction and Objective

MoneyUnify Switch is a source-available, unified payment switch API platform designed to orchestrate payment provider integrations, manage customer accounts, and process transaction flows from a centralized dashboard. The platform provides a standardized interface for multiple payment gateways, allowing applications to initiate collections and automatically failover/retry across configured providers sequentially without modifying core business logic.

With dynamic provider routing, real-time transaction tracking, and automatic provider credential configuration from the admin panel, MoneyUnify Switch ensures high transaction success rates and eliminates single-gateway dependency.

### What does MoneyUnify do?

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

It's **multi-currency and multi-country** by design. Providers advertise the
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
with mobile money" in five markets, a SaaS billing customers across regions, or an
e-commerce checkout that needs MTN in one country and M-Pesa in another — all
through the same integration.

### Documentation

MoneyUnify Switch ships with a built-in documentation site served at the **`/docs`** route (installation, provider configuration, and the full API reference). It is generated from the Markdown files in [`prezet/content`](prezet/content). See [Documentation Site (`/docs`)](#documentation-site-docs) below for how to bring it up.

A standalone [API Documentation](API_DOCUMENTATION.md) file is also available for quick reference.

## Supported Countries and Mobile Networks

The built-in provider drivers collectively cover **32 countries** across Africa. The switch reaches a country only when you configure (and activate) a provider that supports it — coverage is the **union of the providers you configure**. See the full [Coverage reference](prezet/content/api/coverage.md) in the docs.

### Countries and currencies

Networks (MNOs) are the principal mobile-money operators reachable in each market through the listed providers — aggregators (pawaPay, Ting) auto-select the payer's operator, while operator-specific drivers route to a single network.

| Country | Code | Currency | Networks (MNOs) | Providers |
| --- | --- | --- | --- | --- |
| Benin | BJ | XOF | MTN MoMo, Moov Money | MTN MoMo, pawaPay, Ting |
| Botswana | BW | BWP | Orange Money, Mascom MyZaka, BTC Smega | Kazang |
| Burkina Faso | BF | XOF | Orange Money, Moov Money | Flutterwave, pawaPay |
| Cameroon | CM | XAF | MTN MoMo, Orange Money | Flutterwave, MTN MoMo, pawaPay, Ting |
| Chad | TD | XAF | Airtel Money, Moov Money | Airtel Money, Ting |
| Congo-Brazzaville | CG | XAF | MTN MoMo, Airtel Money | Airtel Money, MTN MoMo, pawaPay, Ting |
| Côte d'Ivoire | CI | XOF | MTN MoMo, Orange Money, Moov Money, Wave | Flutterwave, MTN MoMo, pawaPay, Ting |
| DR Congo | CD | CDF | Vodacom M-Pesa, Airtel Money, Orange Money | Airtel Money, M-Pesa (Vodacom), pawaPay, Ting |
| Eswatini | SZ | SZL | MTN MoMo | MTN MoMo, Ting |
| Ethiopia | ET | ETB | Safaricom M-Pesa, Telebirr | pawaPay |
| Gabon | GA | XAF | Airtel Money, Moov Money | Airtel Money, pawaPay, Ting |
| Ghana | GH | GHS | MTN MoMo, AirtelTigo Money, Telecel Cash | DPO Pay, Flutterwave, M-Pesa (Vodacom), MTN MoMo, pawaPay, Ting |
| Guinea | GN | GNF | MTN MoMo, Orange Money | MTN MoMo, Ting |
| Guinea-Bissau | GW | XOF | MTN MoMo, Orange Money | MTN MoMo, Ting |
| Kenya | KE | KES | Safaricom M-Pesa, Airtel Money, T-Kash | Airtel Money, DPO Pay, Flutterwave, M-Pesa (Kenya), pawaPay, Ting |
| Lesotho | LS | LSL | Vodacom M-Pesa, EcoCash | M-Pesa (Vodacom), pawaPay, Ting |
| Liberia | LR | LRD | MTN MoMo, Orange Money | MTN MoMo, Ting |
| Madagascar | MG | MGA | Airtel Money, Orange Money, MVola | Airtel Money |
| Malawi | MW | MWK | Airtel Money, TNM Mpamba | Airtel Money, DPO Pay, Lenco, MobiPay, pawaPay, Ting |
| Mozambique | MZ | MZN | Vodacom M-Pesa, e-Mola, mKesh | DPO Pay, M-Pesa (Vodacom), pawaPay, Ting |
| Namibia | NA | NAD | MTC Money | Kazang |
| Niger | NE | XOF | Airtel Money, Moov Money, Orange Money | Airtel Money, Ting |
| Nigeria | NG | NGN | MTN MoMo, Airtel Money | Airtel Money, DPO Pay, MTN MoMo, pawaPay, Ting |
| Rwanda | RW | RWF | MTN MoMo, Airtel Money | Airtel Money, DPO Pay, Flutterwave, MTN MoMo, pawaPay, Ting |
| Senegal | SN | XOF | Orange Money, Free Money, Wave | Flutterwave, pawaPay |
| Seychelles | SC | SCR | Airtel Money | Airtel Money, Ting |
| Sierra Leone | SL | SLE | Orange Money, Africell Money | pawaPay |
| South Africa | ZA | ZAR | MTN MoMo, Vodacom | Kazang, MTN MoMo, Ting |
| South Sudan | SS | SSP | MTN MoMo | MTN MoMo, Ting |
| Tanzania | TZ | TZS | Vodacom M-Pesa, Airtel Money, Tigo Pesa, Halopesa | Airtel Money, DPO Pay, Flutterwave, M-Pesa (Vodacom), pawaPay, Ting |
| Uganda | UG | UGX | MTN MoMo, Airtel Money | Airtel Money, DPO Pay, Flutterwave, MTN MoMo, pawaPay, Ting |
| Zambia | ZM | ZMW | MTN MoMo, Airtel Money, Zamtel Kwacha | Airtel Money, cGrate, DPO Pay, Flutterwave, Kazang, Lenco, Lipila, MTN MoMo, pawaPay, Ting |

### Providers and mobile networks

Aggregators (pawaPay, Ting) reach the major mobile-money operators (MMOs) in each market and pick the right one automatically; operator-specific drivers route to a single network.

| Provider driver | Markets | Mobile networks reached |
| --- | --- | --- |
| **MTN MoMo** | 15 | MTN Mobile Money |
| **Airtel Money** | 14 | Airtel Money |
| **M-Pesa (Kenya)** | 1 | Safaricom M-Pesa (STK Push) |
| **M-Pesa (Vodafone/Vodacom)** | 5 | Vodacom / Vodafone M-Pesa |
| **Lenco** | 2 | ZM: MTN, Airtel, Zamtel · MW: Airtel, TNM |
| **Lipila** | 1 | Zambia: MTN, Airtel, Zamtel |
| **cGrate** (Konse Konse 543) | 1 | Zambia: MTN, Airtel, Zamtel |
| **MobiPay** (Malipo) | 1 | Malawi: Airtel Money, TNM Mpamba |
| **Kazang** (ContentReady) | 4 | ZM: MTN, Airtel, Zamtel · ZA/NA/BW: operator configured per market |
| **Flutterwave** | 10 | M-Pesa (KE); MTN, Vodafone, AirtelTigo (GH); MTN, Airtel (UG, RW); MTN, Airtel, Zamtel (ZM); Vodacom, Airtel, Tigo (TZ); MTN, Orange (CM, CI, SN, BF) |
| **DPO Pay** | 9 | Operator configured per market (MNO code) — e.g. Safaricom, Vodacom, Tigo, MTN, Airtel |
| **pawaPay** | 20 | All major MMOs per market — auto-detected (e.g. MTN, Airtel, Vodafone, Orange, Tigo, Moov) |
| **Ting by Cellulant** | 25 | All major MMOs per market — operator code configured per market |

## Requirements

- **PHP 8.4+** (8.5 recommended) with the `dom` and `gd` extensions enabled — [download](https://www.php.net/downloads.php)
- **Composer** 2.x — [download](https://getcomposer.org/download/)
- **Node.js 20+** with npm (pnpm or yarn also work) — [download](https://nodejs.org/en/download)
- A database — **SQLite is the zero-config default**; [MySQL](https://dev.mysql.com/downloads/) or [PostgreSQL](https://www.postgresql.org/download/) also work (any engine with native JSON support), configured via `.env`
- **Git** — [download](https://git-scm.com/downloads)

### Installing PHP and its extensions

You can download PHP from [php.net/downloads](https://www.php.net/downloads.php),
but on macOS and Linux it's easiest to install PHP (and the `dom` + `gd`
extensions this app needs) through your package manager.

**macOS** — using [Homebrew](https://brew.sh). The Homebrew PHP formula already
bundles `dom` and `gd`, so one install is enough:

```bash
brew install php       # PHP 8.4+ with dom, gd, mbstring, curl, sqlite3, …
brew install composer  # Composer 2.x
brew install node      # Node.js 20+
```

**Linux** — Debian / Ubuntu (`apt`). Extensions ship as separate packages, so
install them alongside the PHP CLI:

```bash
sudo apt update
sudo apt install -y php8.4-cli php8.4-dom php8.4-gd \
    php8.4-mbstring php8.4-curl php8.4-xml php8.4-sqlite3
```

> On Fedora / RHEL the equivalent is
> `sudo dnf install php-cli php-xml php-gd php-mbstring php-pdo`
> (the `dom` extension is provided by `php-xml`).

After installing, confirm the version and that the required extensions are loaded:

```bash
php -v                      # should report 8.4 or newer
php -m | grep -E 'dom|gd'   # both "dom" and "gd" should be listed
```

If an extension is missing, install its package (e.g. `php8.4-gd`) and re-run the
check — no PHP reconfiguration is needed.

## Local Setup

1. Clone the repository:

```bash
git clone https://github.com/MoneyUnify/mu-switch.git
cd mu-switch
```

2. Run the automated setup command:

```bash
composer setup
```

   This single command bootstraps everything:
   - installs Composer and npm dependencies,
   - creates your `.env` file (from `.env.example`) and the SQLite database file,
   - generates the application key,
   - runs the database migrations,
   - **builds the documentation index** so the `/docs` site is live,
   - compiles the front-end assets (`npm run build`).

   > **Using MySQL/PostgreSQL instead of SQLite?** Edit the `DB_*` values in `.env`
   > before running `composer setup` (or run `php artisan migrate` again afterward).

3. Start the development environment (runs the PHP server, Vite, queue worker, and log viewer concurrently):

```bash
composer dev
```

4. Open the app and create your first account:
   - Application: <http://localhost:8000>
   - Documentation: <http://localhost:8000/docs>

   After registering, your **API token** is shown on the dashboard, and you can add a
   payment provider from the **Providers** page.

## Documentation Site (`/docs`)

The in-app documentation lives at the **`/docs`** route and is powered by Markdown
files in [`prezet/content`](prezet/content), indexed into a small SQLite catalogue
for search and navigation.

`composer setup` already builds this index, so after setup the docs are live at
<http://localhost:8000/docs>. You only need to rebuild the index when you **add or
edit** documentation:

```bash
php artisan docs:index --fresh
```

Tips:

- While running `composer dev` (or `npm run dev`), the docs index **rebuilds
  automatically** whenever you change a file under `prezet/`.
- In **production**, run `php artisan docs:index --fresh` as part of your deploy
  (after pulling new content) so `/docs` reflects the latest documentation.
- To edit the docs, update the Markdown in `prezet/content/` and the sidebar
  navigation in `prezet/SUMMARY.md`.

## API Documentation & Consumption

To consume the payment switch APIs from external client applications, read the
**API Reference** at [`/docs`](http://localhost:8000/docs) (or the standalone
[API Documentation](API_DOCUMENTATION.md)) for endpoint specifications, payload
parameters, responses, and integration examples. In short: your application sends
a single authenticated request to `POST /api/v1/payment/request`, and the switch
routes it across your active providers with automatic fallback.

## Authors

- 👤 **Blessed Jason Mwanza**
  - GitHub: [@blessedjasonmwanza](https://github.com/blessedjasonmwanza)
  - Email: [blessed.jason.mwanza@example.com](mailto:blessed.jason.mwanza@example.com)
  - Website: [https://blessedjasonmwanza.github.io](https://blessedjasonmwanza.github.io)
  - X (Twitter): [@mwanzabj](https://twitter.com/mwanzabj)
  - LinkedIn: [Blessedjasonmwanza](https://linkedin.com/in/blessedjasonmwanza)


## Contributing

We welcome contributions! If you want to help improve MoneyUnify Switch:

- open an issue for bugs or feature requests
- send a pull request with a clear description and tests
- follow existing code style and architecture patterns

When contributing, please:

- include a meaningful commit message
- write or update tests for your changes
- keep new features small and incremental

## Show Your Support

If this project helps you, please consider supporting the development by:

- starring the repository on GitHub
- sharing it with the community
- suggesting improvements or use cases

If you want to donate, please use the MoneyUnify team or sponsorship links associated with this repository.
- Donate via Buy Me a Coffee: [https://buymeacoffee.com/mwanzabj](https://www.buymeacoffee.com/mwanzabj)
<!-- - Sponsor on GitHub: [https://github.com/sponsors/MoneyUnify](https://github.com/sponsors/MoneyUnify) -->

## License

This project is licensed under a Source-Available Proprietary License. See the [LICENSE.txt](LICENSE.txt) file for full details.
