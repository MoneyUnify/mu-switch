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

| Dependency | Version |
| --- | --- |
| PHP | 8.3 or newer (with `dom` and `gd` extensions) |
| Composer | 2.x |
| Node.js | 20 or newer |
| Database | SQLite (default), MySQL, or PostgreSQL |

> The default configuration uses SQLite, so no separate database server is
> required to get started.

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
