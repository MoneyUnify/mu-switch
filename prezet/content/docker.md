---
title: Run with Docker
excerpt: Run MoneyUnify Switch on your machine with one command, without installing PHP, Composer, Node.js or a database.
date: 2026-10-19
category: Getting Started
---

If you have Docker, you don't need to install PHP, Composer, Node.js or a
database. One command builds the app and starts it with its own Postgres
database, a demo account and a week of sample activity.

> This is for trying MoneyUnify on your own machine. To run it for real on a
> server, with your domain, HTTPS and live providers, see
> [Self-Host with Docker](/docs/docker-production).

## Summary

```bash
git clone https://github.com/blessedjasonmwanza/mu-switch-paypal.git
cd mu-switch-paypal
docker compose up --build
```

Then open [http://localhost:8000](http://localhost:8000) and sign in with
**demo@moneyunify.test** / **password**.

| Command | What it does |
| --- | --- |
| `docker compose up --build` | Build the app and start it (first time, or after changing the code). |
| `docker compose up -d` | Start it in the background. |
| `docker compose logs -f app` | Follow the app's logs. |
| `docker compose down` | Stop it. Your data is kept. |
| `docker compose down -v` | Stop it and delete all data, for a fresh start. |

## What you need

- **Docker Desktop** (Mac or Windows) or **Docker Engine with the Compose
  plugin** (Linux). [Install Docker](https://docs.docker.com/get-docker/).
  Check it's working with `docker compose version`.
- About 2 GB of free disk space.

Nothing else: PHP, Composer, Node.js and Postgres all run inside the
containers.

## Step by step

### 1. Get the code

```bash
git clone https://github.com/blessedjasonmwanza/mu-switch-paypal.git
cd mu-switch-paypal
```

### 2. Add your sandbox keys (optional)

The app runs without any keys: payments use the demo data and payouts use
**Simulated Payouts**. To make real sandbox payments, copy the example file
and fill in what you have:

```bash
cp .env.docker.example .env.docker
```

| Setting | Where to get it | Without it |
| --- | --- | --- |
| `PAYPAL_SANDBOX_CLIENT_ID` and `PAYPAL_SANDBOX_CLIENT_SECRET` | Your sandbox app in the [PayPal developer dashboard](https://developer.paypal.com/dashboard/applications/sandbox) | Add PayPal later on the **Providers** page. |
| `PAWAPAY_SANDBOX_TOKEN` | Your [pawaPay sandbox](https://dashboard.sandbox.pawapay.io) account | Payouts use Simulated Payouts. |
| `GEMINI_API_KEY` (or another provider with `AI_PROVIDER`) | [Google AI Studio](https://aistudio.google.com/apikey) | Add an AI provider later on the **AI & Agents** page. |

`.env.docker` is ignored by Git, so your keys stay on your machine.

> Add your keys **before the first start**: the demo account picks up the
> PayPal and pawaPay keys when it's created. If you add them later, add the
> providers on the **Providers** page instead.

### 3. Start it

```bash
docker compose up --build
```

The first build takes a few minutes while it installs everything. When it's
ready, the logs show:

```text
Demo login .................. demo@moneyunify.test / password
INFO success: nginx entered RUNNING state
INFO success: queue entered RUNNING state
INFO success: scheduler entered RUNNING state
```

### 4. Open the app

Go to [http://localhost:8000](http://localhost:8000) and sign in with
**demo@moneyunify.test** / **password**. The documentation is at
[http://localhost:8000/docs](http://localhost:8000/docs).

To try a payment, see [Run it yourself](https://github.com/blessedjasonmwanza/mu-switch-paypal#run-it-yourself)
in the README.

## What's running

| Container | What it is |
| --- | --- |
| `app` | The app (nginx and PHP-FPM), the queue worker (payouts, emails, refunds) and the scheduler (moves transfers forward every minute). It's the same image used for [Render](/docs/deploy-render). |
| `db` | Postgres 17, with its data kept in a Docker volume. |

On every start, the app runs the database migrations and adds the demo account
if it isn't there yet. An app key is generated on the first start and kept in
the `app-storage` volume.

## Common tasks

**Run an Artisan command:**

```bash
docker compose exec app php artisan about
```

**Use another port** (if 8000 is taken):

```bash
APP_PORT=8080 docker compose up
```

**Start without demo data:**

```bash
SEED_DEMO_DATA=false docker compose up --build
```

**See code changes:** the image contains a copy of the code, so rebuild after
changing it:

```bash
docker compose up --build
```

For day-to-day development with instant reloads, use the
[standard local setup](/docs/installation) instead.

**Start over** with an empty database and a new demo account:

```bash
docker compose down -v
docker compose up --build
```

## Troubleshooting

| Problem | Fix |
| --- | --- |
| `port is already allocated` | Another app uses port 8000. Start with `APP_PORT=8080 docker compose up`. |
| The page doesn't load right after starting | The app waits for the database, then migrates. Give it a few seconds and check `docker compose logs -f app`. |
| You changed `.env.docker` but nothing changed | Restart with `docker compose up -d`. Keys for the demo account are only read when it's first created: use `docker compose down -v` to recreate it, or add the provider on **Providers**. |
| The build fails while downloading | Check your internet connection and run `docker compose build` again. |
| You were signed out after `down -v` | Expected: `down -v` deletes the data, including the app key and sessions. |

This setup is for trying MoneyUnify locally with sandbox accounts. To host it
for others, see [Self-Host with Docker](/docs/docker-production) (your own
server) or [Deploy on Render](/docs/deploy-render).
