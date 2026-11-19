---
title: Self-Host with Docker
excerpt: Run MoneyUnify Switch in production on your own server with Docker, Postgres and automatic HTTPS.
date: 2026-10-19
category: Getting Started
---

This guide runs MoneyUnify Switch for real use on a server you control: no
demo data, your own domain with HTTPS, and your own live provider accounts.
To try it on your laptop instead, see [Run with Docker](/docs/docker).

## Summary

On a Linux server with Docker and a domain pointed at it:

```bash
git clone https://github.com/blessedjasonmwanza/mu-switch-paypal.git
cd mu-switch-paypal
cp .env.production.example .env.production
# Fill in APP_DOMAIN, APP_URL, APP_KEY, DB_PASSWORD and the MAIL_* settings
docker compose --env-file .env.production -f compose.prod.yaml up -d --build
```

Then open `https://your-domain`, register the first account, verify its email,
and add your providers on **Providers**.

| | Local Docker | Production Docker |
| --- | --- | --- |
| File | `compose.yaml` | `compose.prod.yaml` |
| Settings | `.env.docker` (optional) | `.env.production` (required) |
| Address | `http://localhost:8000` | `https://your-domain`, with a free certificate |
| Data | Demo account and sample activity | Empty: your own accounts |
| Payments | Sandbox, Simulated Payouts | Your live providers (Simulated Payouts is off in production) |
| Restarts | Manual | Automatic after a crash or reboot |

## What gets installed

| Container | What it does |
| --- | --- |
| `caddy` | Receives all traffic on ports 80 and 443, gets and renews the HTTPS certificate for your domain from Let's Encrypt, and forwards requests to the app. |
| `app` | The app (nginx and PHP-FPM with phpredis and OPcache), the queue worker (payouts, emails, refunds) and the scheduler (moves transfers forward every minute). Not reachable from outside except through Caddy. |
| `db` | Postgres 17. Its data is kept in the `db-data` volume. |
| `redis` | Redis 7 on port 6379, configured with native TCP keepalive (`--tcp-keepalive 60`, `--timeout 0`) and persistent connections for cache, sessions and queues. Its data is kept in the `redis-data` volume. |

## Before you start

- **A Linux server** (for example Ubuntu 24.04) with at least 2 GB of memory
  and 10 GB of free disk space.
- **Docker Engine and the Compose plugin.** On Ubuntu, follow
  [Docker's install guide](https://docs.docker.com/engine/install/ubuntu/),
  then check with `docker compose version`.
- **A domain** (or subdomain, like `pay.example.com`) with a DNS **A** record
  pointing at the server's IP address.
- **Ports 80 and 443 open** in the server's firewall. Caddy needs both to get
  the certificate.
- **An email (SMTP) account** for sign-up verification, password resets and
  receipts, from any provider.
- **Your live provider accounts** (PayPal business app, payout providers) and
  the licences to operate in your markets. See [Who is responsible for a
  deployment](/terms).

## 1. Get the code

```bash
git clone https://github.com/blessedjasonmwanza/mu-switch-paypal.git
cd mu-switch-paypal
```

## 2. Configure

```bash
cp .env.production.example .env.production
```

Generate the two secrets on the server:

```bash
echo "base64:$(openssl rand -base64 32)"
```

```bash
openssl rand -hex 24
```

Then edit `.env.production`:

| Setting | Value |
| --- | --- |
| `APP_DOMAIN` | Your domain, e.g. `pay.example.com` (no `https://`). |
| `APP_URL` | The same domain with `https://`, e.g. `https://pay.example.com`. |
| `APP_KEY` | The `base64:…` value from the first command. |
| `DB_PASSWORD` | The value from the second command. |
| `MAIL_*` | Your SMTP provider's host, port, username and password, and the address emails come from. |
| `TRANSFER_MAX_*` | Your spending limits per transfer and per day, in USD. |
| `AI_PROVIDER`, `GEMINI_API_KEY` | Optional server-wide AI fallback. Accounts normally choose their own on **AI & Agents**. |

> **Keep `APP_KEY` safe and never change it.** It encrypts the provider and AI
> keys saved in the app. If it changes, those keys can't be read and must be
> entered again. Store a copy in your password manager.

`.env.production` is ignored by Git and never copied into the image, so your
secrets stay on the server.

## 3. Start it

```bash
docker compose --env-file .env.production -f compose.prod.yaml up -d --build
```

The first build takes a few minutes. Follow the start-up with:

```bash
docker compose --env-file .env.production -f compose.prod.yaml logs -f app
```

You'll see the migrations run, then supervisord start `nginx`, `php-fpm`,
`queue` and `scheduler`. If a setting is missing, Compose stops straight away
and names it (for example `Set APP_KEY in .env.production`).

> Tip: to avoid typing the flags every time, set them once in your shell:
>
> ```bash
> export COMPOSE_FILE=compose.prod.yaml COMPOSE_ENV_FILES=.env.production
> ```
>
> Then `docker compose up -d`, `docker compose logs -f app` and the other
> commands below work as they are.

## 4. Create the first account

1. Open `https://your-domain`. The first visit can take a few seconds while
   Caddy gets the certificate.
2. Choose **Get Started** and register.
3. Open the verification email and confirm your address.

## 5. Add your providers

Everything else is set up in the app, not in files:

1. **Providers:** add **PayPal** with your **live** app's client ID and secret
   (environment `live`), then your payout providers (pawaPay, Lenco, MTN,
   Airtel and the others you have contracts with).
2. **PayPal webhook:** edit the PayPal provider and copy its **Webhook URL**.
   In your live PayPal app, add a webhook with that URL and the events listed
   in the dialog, then paste its ID into the provider. See
   [Providers](/docs/providers).
3. **AI & Agents** (optional): add your AI provider, model and key.
4. **Fee policy** and **Treasury** (optional): set your fees and float rules.
5. **API Token:** copy the token for your apps and MCP clients.

> These are live accounts. Every payment and payout moves real money. Test
> with small amounts first.

## Production performance & OPcache

The production Docker container is optimized for low latency and high throughput out of the box:

- **Native OPcache with JIT:** PHP's native OPcache is enabled with a 256 MB memory pool, 16 MB interned strings, and 30,000 max accelerated files. PHP 8 tracing JIT compilation is enabled (`opcache.jit=tracing`, 64 MB buffer) for both web requests and CLI workers (`opcache.enable_cli=1`).
- **Redis with Native Keepalive:** Redis 7 runs on port 6379 with `--tcp-keepalive 60` and `--timeout 0`. In PHP, `phpredis` is compiled natively with persistent connection pooling (`redis.pconnect.pooling_enabled=1`, `REDIS_PERSISTENT=true`, and `REDIS_TCP_KEEPALIVE=60`) to keep sockets alive and eliminate connection renegotiation on cache and session reads.
- **Zero timestamp I/O overhead:** In production (`APP_ENV=production`), `opcache.validate_timestamps=0` disables filesystem checks on every request, eliminating stat system calls.
- **Framework & View Optimization:** The container automatically runs `php artisan optimize` (caching configuration, routes, and events) and `php artisan view:cache` on start-up.
- **Authoritative Autoloader:** Composer generates an authoritative classmap (`--classmap-authoritative --optimize --no-dev`) so class lookups never hit the filesystem.
- **Nginx Gzip & Asset Caching:** Built frontend bundles in `/build/` are served with immutable cache headers (`Cache-Control: public, max-age=31536000, immutable`), and JSON, SVG, CSS, and JS responses are gzip-compressed.

## Everyday operations

The commands below assume you set `COMPOSE_FILE` and `COMPOSE_ENV_FILES` (see
step 3). Otherwise add `--env-file .env.production -f compose.prod.yaml`.

| Task | Command |
| --- | --- |
| See what's running | `docker compose ps` |
| Follow the logs | `docker compose logs -f app` |
| Run an Artisan command | `docker compose exec app php artisan about` |
| Restart | `docker compose restart app` |
| Stop (data is kept) | `docker compose down` |

### Update to a new version

```bash
git pull
docker compose up -d --build
```

The app migrates the database on start. Back up first (below).

### Back up the database

```bash
docker compose exec -T db pg_dump -U moneyunify moneyunify | gzip > moneyunify-$(date +%F).sql.gz
```

Copy backups off the server, and schedule this command (for example with
`cron`) to run daily. Keep `.env.production` backed up too: without its
`APP_KEY`, saved provider keys can't be read.

### Restore a backup

```bash
gunzip -c moneyunify-2026-10-19.sql.gz | docker compose exec -T db psql -U moneyunify moneyunify
```

Restore into a fresh database (after `docker compose down -v` and a new
`up -d`), not on top of live data.

## Security checklist

- [ ] `APP_KEY` and `DB_PASSWORD` are long, random and stored safely.
- [ ] `.env.production` is readable only by you: `chmod 600 .env.production`.
- [ ] The firewall allows only SSH, 80 and 443. The database isn't exposed.
- [ ] Backups run daily and are copied off the server.
- [ ] Every account uses a strong password and two-factor authentication
      (**Settings → Security**).
- [ ] Limits (`TRANSFER_MAX_*`) match your risk appetite.
- [ ] You have the licences, KYC and compliance your markets require.
- [ ] `LICENSE.txt` and `NOTICE` stay with the deployment, crediting the
      author (see the [terms](/terms)).

## Troubleshooting

| Problem | Fix |
| --- | --- |
| `required variable … is missing a value` | Fill in the named setting in `.env.production`. |
| The site doesn't load over HTTPS | Check the DNS record points at the server and ports 80 and 443 are open, then `docker compose logs caddy`. |
| Links or PayPal returns go to the wrong address | `APP_URL` must be exactly `https://your-domain`. Restart after changing it. |
| No verification email arrives | Check the `MAIL_*` settings and `docker compose logs app`. |
| Saved provider keys stopped working | `APP_KEY` changed. Restore the original, or enter the keys again. |
| Payments stay pending | Check that `queue` and `scheduler` are running in the logs, and register PayPal's webhook. |

To host on a platform instead of your own server, see
[Deploy on Render](/docs/deploy-render).
