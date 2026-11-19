---
title: Self-Hosted Installation
excerpt: Install and run MoneyUnify Switch on your own infrastructure, from cloning the repository to building assets and creating your first account.
date: 2026-06-27
category: Getting Started
---

MoneyUnify Switch is a standard, self-hosted PHP application. You host it
yourself, which keeps every credential, customer, and transaction inside
infrastructure you control.

> **Have Docker?** Skip installing PHP, Composer, Node.js and a database:
> `docker compose up --build` runs everything, with a demo account. See
> [Run with Docker](/docs/docker). To host it for others, see
> [Self-Host with Docker](/docs/docker-production) (your own server) or
> [Deploy on Render](/docs/deploy-render).

## Requirements

| Dependency | Version | Download |
| --- | --- | --- |
| PHP | 8.4 or newer (with `dom` and `gd` extensions) | [php.net/downloads](https://www.php.net/downloads.php) |
| Composer | 2.x | [getcomposer.org/download](https://getcomposer.org/download/) |
| Node.js | 20 or newer | [nodejs.org/download](https://nodejs.org/en/download) |
| Database | SQLite (default), MySQL, or PostgreSQL | [SQLite](https://www.sqlite.org/download.html) · [MySQL](https://dev.mysql.com/downloads/) · [PostgreSQL](https://www.postgresql.org/download/) |

> The default configuration uses SQLite, so no separate database server is
> required to get started.

### Installing PHP and its extensions

You can grab the PHP source or a Windows build from
[php.net/downloads](https://www.php.net/downloads.php), but on macOS and Linux
it's easiest to install PHP (and the `dom` + `gd` extensions this app needs)
through your package manager.

**macOS** — using [Homebrew](https://brew.sh). The Homebrew PHP formula already
bundles the `dom` and `gd` extensions, so a single install is enough:

```bash
brew install php       # PHP 8.4+ with dom, gd, mbstring, curl, sqlite3, …
brew install composer  # Composer 2.x
brew install node      # Node.js 20+
```

**Linux** — Debian / Ubuntu (`apt`). Extensions are shipped as separate
packages, so install them alongside the PHP CLI:

```bash
sudo apt update
sudo apt install -y php8.4-cli php8.4-dom php8.4-gd \
    php8.4-mbstring php8.4-curl php8.4-xml php8.4-sqlite3
```

> On Fedora / RHEL the equivalent is
> `sudo dnf install php-cli php-xml php-gd php-mbstring php-pdo`
> (the `dom` extension is provided by `php-xml`).

After installing, confirm the version and that the required extensions are
loaded:

```bash
php -v                  # should report 8.4 or newer
php -m | grep -E 'dom|gd'   # both "dom" and "gd" should be listed
```

If an extension is missing, install its package (e.g. `php8.4-gd`) and re-run
the check — no PHP reconfiguration is needed.

## Quick start

Clone the repository and run the bundled `setup` script, which installs
dependencies, prepares your environment file, generates an app key, runs the
migrations, and builds the front-end assets:

```bash
git clone <your-fork-url> mu-switch
cd mu-switch
composer setup
```

`composer setup` runs the following for you:

```bash
composer install
cp .env.example .env          # only if .env does not exist yet
php artisan key:generate
php artisan migrate --force
npm install
npm run build
```

## Database

The application ships with `DB_CONNECTION=sqlite`. Create the database file (the
migrations will populate it):

```bash
touch database/database.sqlite
php artisan migrate
```

To use MySQL or PostgreSQL instead, set the `DB_*` values in `.env` before
running `php artisan migrate`.

## Building the documentation index

This documentation site is served under `/docs`. Its search and navigation are
backed by a small SQLite index that you build once after installation (and
whenever you change the docs):

```bash
php artisan docs:index --fresh
```

## Running the app

For local development, the `dev` script runs the web server, queue worker, log
viewer, and Vite together:

```bash
composer dev
```

Then open the app in your browser:

- Application: `http://localhost:8000`
- Documentation: `http://localhost:8000/docs`

In production, serve the app with your usual PHP stack (Nginx or Apache with
PHP-FPM, or a managed PHP host) and run `npm run build` to compile assets.
Also run a queue worker and the scheduler (`php artisan schedule:run`
every minute): payouts, refunds and cross-border transfers depend on both.

### Gateway wait times

Some gateways hold the connection open while they move the money, so the
switch waits for them rather than cutting them off and risking a double charge:

| `.env` setting | Default | Meaning |
| --- | --- | --- |
| `GATEWAY_CONNECT_TIMEOUT` | `8` | Seconds to open a connection. Fails fast so the switch can try the next provider. |
| `GATEWAY_TIMEOUT` | `180` | Seconds to wait for an answer once connected (3 minutes). |

The app raises PHP's own time limit to cover that wait, but your **web server
cuts requests first** unless you raise its limits too. Nginx's
`fastcgi_read_timeout` and PHP-FPM's `request_terminate_timeout` default to 60
seconds. Set both a little above `GATEWAY_CONNECT_TIMEOUT + GATEWAY_TIMEOUT`,
for example 210 seconds, or long gateway calls will be cut off mid-payment.

### Ledger

Every settled payment and payout is recorded in a double-entry ledger, which
the provider **Accounts** balances are read from. After upgrading from a version
without the ledger, build it once from your existing transactions. The command
is safe to run again at any time:

```bash
php artisan migrate
php artisan ledger:rebuild
```

If a provider account already held money before you started using the switch,
record it so the ledger matches the real account:

```bash
php artisan ledger:opening-balance {provider_id} ZMW 5000 --note="Float on go-live"
```

## Create your first account

1. Visit the app and register an account.
2. Verify your email if email verification is enabled.
3. Open the **Dashboard** — your [API token](/docs/api-tokens) is generated
   automatically and shown there.
4. Add at least one [payment provider](/docs/providers) before making API
   calls.
