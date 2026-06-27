---
title: Self-Hosted Installation
excerpt: Install and run MoneyUnify Switch on your own infrastructure, from cloning the repository to building assets and creating your first account.
date: 2026-06-27
category: Getting Started
---

MoneyUnify Switch is a standard, self-hosted PHP application. You host it
yourself, which keeps every credential, customer, and transaction inside
infrastructure you control.

## Requirements

| Dependency | Version | Download |
| --- | --- | --- |
| PHP | 8.3 or newer (with `dom` and `gd` extensions) | [php.net/downloads](https://www.php.net/downloads.php) |
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
brew install php       # PHP 8.3+ with dom, gd, mbstring, curl, sqlite3, …
brew install composer  # Composer 2.x
brew install node      # Node.js 20+
```

**Linux** — Debian / Ubuntu (`apt`). Extensions are shipped as separate
packages, so install them alongside the PHP CLI:

```bash
sudo apt update
sudo apt install -y php8.3-cli php8.3-dom php8.3-gd \
    php8.3-mbstring php8.3-curl php8.3-xml php8.3-sqlite3
```

> On Fedora / RHEL the equivalent is
> `sudo dnf install php-cli php-xml php-gd php-mbstring php-pdo`
> (the `dom` extension is provided by `php-xml`).

After installing, confirm the version and that the required extensions are
loaded:

```bash
php -v                  # should report 8.3 or newer
php -m | grep -E 'dom|gd'   # both "dom" and "gd" should be listed
```

If an extension is missing, install its package (e.g. `php8.3-gd`) and re-run
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

## Create your first account

1. Visit the app and register an account.
2. Verify your email if email verification is enabled.
3. Open the **Dashboard** — your [API token](/docs/api-tokens) is generated
   automatically and shown there.
4. Add at least one [payment provider](/docs/providers) before making API
   calls.
