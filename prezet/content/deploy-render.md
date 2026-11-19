---
title: Deploy on Render
excerpt: Host MoneyUnify Switch on Render with Postgres, a queue worker and the scheduler, from one Blueprint.
date: 2026-10-19
category: Getting Started
---

The repository includes everything Render needs: a `Dockerfile` and a
`render.yaml` Blueprint. One deploy gives you:

| Part | What it runs |
| --- | --- |
| **Web service** (`moneyunify`) | One container with nginx, PHP-FPM, the queue worker (payouts, emails, refunds) and the scheduler (moves transfers forward every minute, keeps PayPal's rates warm). Node.js is included for PayPal's MCP server. |
| **Postgres** (`moneyunify-db`) | The database, connected to the web service automatically. |

On every start the container runs the database migrations, adds the demo
account if you asked for it, caches the configuration and starts all four
processes.

> The app runs on Postgres, SQLite and MySQL. The whole test suite passes on
> both SQLite and Postgres.

## Before you start

You need:

1. The code pushed to a GitHub or GitLab repository that Render can read,
   including `Dockerfile` and `render.yaml`.
2. A [Render](https://render.com) account.
3. An application key. Generate one on your machine and keep it for step 2:

   ```bash
   php artisan key:generate --show
   ```

   It starts with `base64:`. Copy the whole value.
4. Your **PayPal sandbox** app's client ID and secret, from the
   [PayPal developer dashboard](https://developer.paypal.com/dashboard/applications/sandbox).
5. Optional: a **pawaPay sandbox** API token (real sandbox payouts). Without
   it, payouts use Simulated Payouts.
6. Optional: an AI key for the Send Money assistant. Accounts normally add their
   own provider, model and key on the **AI & Agents** page after deploying, so
   you can skip this. To set a server-wide fallback instead, use a
   [Gemini API key](https://aistudio.google.com/apikey) (or any supported
   provider's key with `AI_PROVIDER`).

## 1. Create the Blueprint

1. In the Render dashboard, choose **New → Blueprint**.
2. Connect your repository.
3. Render reads `render.yaml` and shows the web service and the database.

The Blueprint creates the service with Render's own auto-deploy turned off
(`autoDeployTrigger: off`). After the first deploy, GitHub Actions deploys
every push to `main` once its checks pass (see
[Deploy from GitHub](#deploy-from-github)).

## 2. Fill in the secrets

Render asks for the values marked `sync: false` in `render.yaml`:

| Variable | Value |
| --- | --- |
| `APP_KEY` | The `base64:…` key from **Before you start**. |
| `APP_URL` | Your service's address, e.g. `https://moneyunify.onrender.com`. If you don't know it yet, enter a placeholder and fix it in step 4. |
| `PAYPAL_SANDBOX_CLIENT_ID` | Your PayPal sandbox client ID. |
| `PAYPAL_SANDBOX_CLIENT_SECRET` | Your PayPal sandbox secret. |
| `PAWAPAY_SANDBOX_TOKEN` | Optional. Leave empty to use Simulated Payouts only. |
| `GEMINI_API_KEY` | Optional server-wide AI fallback. Leave it empty and add AI providers on **AI & Agents** instead. |

Then choose **Apply**. Render creates the database, builds the image and
deploys it. The first build takes about 10 minutes; later builds reuse cached
layers.

## 3. Watch the first deploy

In the web service's **Logs** you'll see, in order:

1. The migrations running against Postgres.
2. `Demo login … demo@moneyunify.test / password`, from the demo account.
3. `INFO Caching framework bootstrap, configuration, and metadata.`
4. supervisord starting `php-fpm`, `nginx`, `queue` and `scheduler`.

Render marks the deploy live once `/up` answers. Open the service's URL: you
should see the landing page.

## 4. Set the real address

If you used a placeholder for `APP_URL`, set it now under **Environment** to
the exact URL Render shows (with `https://`, no trailing slash). Saving the
change redeploys the service.

`APP_URL` matters: PayPal sends payers back to it, receipt links and emails
use it, and the docs and OpenAPI spec point at it.

## 5. Sign in and check the providers

1. Sign in with **demo@moneyunify.test** / **password**.
2. Open **Providers**. You should see **PayPal** (from your sandbox keys) and
   **Simulated Payouts**, plus **pawaPay** if you set its token.
3. On **Dashboard**, **Send Money** and **Merchants**, the demo account has a
   week of transfers, merchant payments and refunds to explore.

> Change the demo password (**Settings → Security**) if the URL will be public
> for a long time.

## 6. Register PayPal's webhook (recommended)

Webhooks move payments forward even when the payer never returns to your site.

1. On **Providers**, edit the PayPal provider and **Copy** its **Webhook URL**.
2. In the PayPal developer dashboard, open your sandbox app, choose
   **Add Webhook** and paste the URL.
3. Events: `CHECKOUT.ORDER.APPROVED`, `PAYMENT.CAPTURE.COMPLETED`,
   `PAYMENT.CAPTURE.DENIED`, `PAYMENT.CAPTURE.REFUNDED` and
   `PAYMENT.CAPTURE.REVERSED` (the edit dialog lists them too).
4. Paste the new webhook's ID into the PayPal provider's **Webhook ID** field.

Without a webhook, the scheduler still checks every in-flight transfer every
minute.

## 7. Check that everything works

| Check | How |
| --- | --- |
| The app is healthy | `https://YOUR-URL/up` shows the green "Application up" page. |
| Links are https | Start a payment on a pay link: PayPal's return URL and the receipt link begin with `https://`. |
| Payments complete | Pay a pay link with a [PayPal sandbox buyer](https://developer.paypal.com/dashboard/accounts) and watch the receipt move to **Delivered**. |
| The queue and scheduler run | The logs show `queue` and `scheduler` running, and transfers move forward without refreshing. |
| Docs and API reference | `https://YOUR-URL/docs` and `https://YOUR-URL/openapi.yaml`. |
| MCP | Connect an MCP client to `https://YOUR-URL/mcp/operator` with the API token from **API Token** (see [MCP Servers](/docs/mcp)). |

## Deploy from GitHub

`.github/workflows/deploy.yml` runs on every push to `main`:

1. **Checks:** code style (Pint, Prettier, ESLint), TypeScript types, the test
   suite on SQLite and on Postgres, and a build of the Docker image.
2. **Deploy:** if every check passes, it asks Render to deploy that exact
   commit, through Render's API.
3. **Wait and verify:** it follows the deploy until Render reports it live,
   then checks `/up` on the live site.
4. **Record:** each run appears under **Deployments** on the repository's
   GitHub page, as the `production` environment with the live URL, so you can
   see what's deployed and when.

A commit that fails a check is never deployed. You can also run it by hand from
the **Actions** tab (**deploy → Run workflow**).

Set it up once:

1. **Create a Render API key:** Render dashboard → **Account Settings → API
   Keys → Create API Key**.
2. **Find the service ID:** open the web service. Its ID starts with `srv-`
   and is in the page's URL.
3. **Add both as GitHub secrets:** in the repository, **Settings → Secrets and
   variables → Actions → New repository secret**:

   | Name | Value |
   | --- | --- |
   | `RENDER_API_KEY` | The API key from step 1. |
   | `RENDER_SERVICE_ID` | The `srv-…` ID from step 2. |

4. **Optional:** if your URL isn't `https://mu-switch-paypal.onrender.com`,
   add a repository **variable** (same page, **Variables** tab) named
   `APP_URL` with your URL.
5. **Turn off Render's own auto-deploy,** so each commit deploys once: the
   service's **Settings → Build & Deploy → Auto-Deploy → Off**. (A Blueprint
   already sets this.)

Until the secrets are added, the deploy step stops with a message saying which
secret is missing. Checks still run.

## Settings in `render.yaml`

| Variable | Set to | Why |
| --- | --- | --- |
| `APP_ENV` | `staging` | A sandbox deployment, not production: Simulated Payouts and the demo account are only available outside production. Use `production` only for a live deployment. |
| `APP_DEBUG` | `false` | Never show stack traces on a public URL. |
| `LOG_CHANNEL` | `stderr` | Logs appear in Render's **Logs** tab. |
| `DB_CONNECTION` / `DB_URL` | `pgsql` / the database's connection string | Render fills in `DB_URL` from the database. |
| `SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION` | `database` | No Redis needed. |
| `MAIL_MAILER` | `log` | Emails are written to the logs. To send them, set `MAIL_MAILER=smtp` and your provider's `MAIL_*` settings. |
| `SEED_DEMO_DATA` | `true` | Adds the demo account on deploy. Running it again adds nothing. Set it to `false` for a clean install. |
| `TRUSTED_PROXIES` | not set (trusts all) | Render ends HTTPS at its load balancer. Trusting its forwarded headers keeps every link on `https://`. |

## Plans and cost

`render.yaml` doesn't pin a plan, so Render creates the web service and the
database on its smallest paid instances. Check current prices on
[render.com/pricing](https://render.com/pricing).

Free instances aren't a good fit for a demo that others will try:

- A free web service sleeps after a period without traffic. The first visit
  then waits for it to wake, and the scheduler stops while it sleeps.
- Free Postgres databases expire after a limited time
  ([details](https://render.com/docs/free)).

## Troubleshooting

| Problem | Fix |
| --- | --- |
| `No application encryption key` or `Unsupported cipher` | `APP_KEY` is missing or isn't the full `base64:…` value. |
| Links or PayPal returns use `http://` | Set `APP_URL` to the `https://` address. If you run behind a proxy you trust explicitly, set `TRUSTED_PROXIES` to its addresses. |
| PayPal sends payers to the wrong site | `APP_URL` doesn't match the address payers use. |
| `Demo data is never added in production` | `APP_ENV` is `production`. Use `staging` for a sandbox demo. |
| Payments stay pending | Check the logs for the `queue` and `scheduler` processes, and register PayPal's webhook (step 6). |
| The assistant is off | Add an AI provider, model and key on **AI & Agents**, or set a server-wide fallback (`AI_PROVIDER` and that provider's key). |

## Going live

A live deployment uses the same Blueprint with:

- `APP_ENV=production` and `SEED_DEMO_DATA=false`;
- your **live** PayPal app and live payout providers, added on **Providers**;
- your own licences, KYC and compliance, as set out in the
  [terms](/terms) for deployments.

Keep `LICENSE.txt` and `NOTICE` with every deployment.

To run on your own server instead of Render, see
[Self-Host with Docker](/docs/docker-production).
