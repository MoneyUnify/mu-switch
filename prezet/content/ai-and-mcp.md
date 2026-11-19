---
title: AI & MCP Setup
excerpt: Add AI provider keys with failover and connect PayPal's MCP server, from the dashboard or from .env.
date: 2026-10-15
category: Configuration
---

The switch's AI features call a language model of your choice (it isn't tied to
any one AI company), and use PayPal's
tools over the [Model Context Protocol](https://modelcontextprotocol.io) (MCP).
Both are **optional**: payments, routing and every provider driver work without
any AI key. You only need this page if you want the AI features.

## Two places to configure

| Where | Who it's for | Scope |
| --- | --- | --- |
| **AI & Agents** in the dashboard sidebar | Each account, no server access needed | That account only |
| **`.env`** on the server | Whoever runs the server | Every account, as the fallback |

The dashboard is the main place: operators and users choose their provider,
model and key there, with no server changes. **What an account sets in the
dashboard wins, and anything it leaves blank falls back to `.env`.** An account can, for example, add OpenAI
without a key, and the server's `OPENAI_API_KEY` is used.

## Option A: the dashboard

Open **AI & Agents** in the sidebar. An account can add **several AI
providers**, and its agents **fail over** between them in order: they use the
first one and move to the next when a provider is rate-limited, overloaded,
unreachable or out of credit.

1. **Add AI provider.** Pick a provider, a **model** and an optional
   **API key** (blank means the provider's key from `.env`). Each provider can
   be added once. The model list shows the provider's default plus suggested
   faster and more capable models. Choose **Other model…** to enter any model
   ID the provider offers, exactly as the provider names it.
2. **Order.** Use the up and down arrows to set the failover order. The top
   provider is tried first.
3. **Test.** Each provider has a **Test** button that sends a tiny prompt
   through that provider alone and tells you whether its key and model work.
   A busy model is retried once. If it fails, you see the provider's own
   error. "High demand" or "overloaded" means the model is busy, not that the
   key is wrong: try again shortly or pick another model.
4. **Edit** a provider to change its model or key, or **remove** it.

Each provider shows where its key comes from:

| Badge | Meaning |
| --- | --- |
| **Key ••••1234** | A key saved in the dashboard |
| **Key from .env** | No saved key; the provider's `.env` key is used |
| **No key, skipped** | No key anywhere; agents skip this provider |

If an account has no providers, or none of them has a key, its agents use the
server default (`AI_PROVIDER` in `.env`).

How keys are handled:

- Keys are **encrypted at rest** and never shown again. The page only shows the
  last four characters.
- **Remove saved key and use .env** (in the edit dialog) returns a provider to
  its `.env` key.
- Each account's keys are used only for that account's agents. They never
  replace the server's `.env` keys for anyone else.

The same page shows how **PayPal MCP** is connected (see below).

## Option B: `.env`

The server-wide fallback is Google Gemini unless you choose another. Create a
key in [Google AI Studio](https://aistudio.google.com/apikey) and add it:

```ini
AI_PROVIDER=gemini
GEMINI_API_KEY=...
```

Any other supported provider works the same way: set `AI_PROVIDER` to its name
and add that provider's key instead:

| `AI_PROVIDER` | Key variable |
| --- | --- |
| `anthropic` | `ANTHROPIC_API_KEY` |
| `openai` | `OPENAI_API_KEY` |
| `gemini` | `GEMINI_API_KEY` |
| `mistral` | `MISTRAL_API_KEY` |
| `groq` | `GROQ_API_KEY` |
| `xai` | `XAI_API_KEY` |
| `deepseek` | `DEEPSEEK_API_KEY` |
| `openrouter` | `OPENROUTER_API_KEY` |

The full list of providers and their settings is in `config/ai.php`. If you
cache configuration, run `php artisan config:cache` after editing `.env`.

## Create the conversation tables

The AI features store conversations in two tables (`agent_conversations` and
`agent_conversation_messages`), and each account's providers in `ai_credentials`. They're
part of the normal migrations:

```bash
php artisan migrate
```

## Connect PayPal's Agent Toolkit

PayPal's [Agent Toolkit](https://github.com/paypal/agent-toolkit) exposes PayPal
actions (orders, refunds, transactions, disputes, invoices, subscriptions and
more) as tools an AI agent can call, through PayPal's MCP server.

### How it runs

By default, agents run **PayPal's official MCP server**, `@paypal/mcp` (built
on `@paypal/agent-toolkit`), locally over stdio. It's installed with the app's
npm packages (`npm install`, which `composer setup` runs) and needs Node.js 18+.
Each run gets a fresh access token and the provider's environment
(`PAYPAL_ENVIRONMENT` `SANDBOX` or `PRODUCTION`).

| `.env` | Default | Description |
| --- | --- | --- |
| `PAYPAL_MCP_TRANSPORT` | `local` | `local` runs PayPal's MCP server from `node_modules`. `remote` uses PayPal's hosted server over streamable HTTP. |
| `PAYPAL_MCP_NODE` | `node` | The Node.js binary, e.g. `/usr/bin/node` when the web server's `PATH` doesn't include Node. |

Why local by default: PayPal's hosted sandbox server answers only over SSE
(`/sse`); its streamable HTTP endpoint (`https://mcp.sandbox.paypal.com/http`)
returned 404 when we checked, and the MCP client here speaks streamable HTTP and
stdio, not SSE. If `@paypal/mcp` isn't installed, agents use the hosted server.

The Send Money assistant only gets PayPal's **read-only** tools (names starting
`get_`, `list_`, `show_`, `search_`, `retrieve_` or `track_`), such as
`get_order`, `list_transactions`, `get_refund` and `list_disputes`, so it can
check PayPal's own record but never move or refund money through PayPal.

### `npm audit`

`npm audit` reports advisories in packages that `@paypal/agent-toolkit` ships
for other agent frameworks (LangChain, LangSmith and the Vercel `ai` SDK, with
their `uuid` and `jsondiffpatch` dependencies). The MCP server never loads them:
its entry point only uses the toolkit's MCP adapter. The one package it does
load with an advisory, `mathjs`, is pinned to the fixed version through
`overrides` in `package.json`. None of the remaining advisories has a fix
within the toolkit's current version.

### From the dashboard (automatic)

If the account has an **active PayPal provider** on the
[Providers](/docs/providers) page, nothing else is needed. Its agents use the
Agent Toolkit with that provider's credentials:

- The environment matches the provider's: sandbox or live.
- Access tokens are fetched and refreshed automatically, so they never expire
  mid-use.
- With several PayPal providers, the oldest active one with MCP enabled is used.

Each PayPal provider has a **PayPal MCP for AI agents** setting (enabled by
default). Set it to **disabled** to switch PayPal MCP off for the account: its
agents then get no PayPal tools at all, and `.env` is **not** used in its place.

The **AI & Agents** page shows which PayPal provider and environment are in use,
or that MCP is turned off.

### From `.env` (fallback)

Only accounts **without an active PayPal provider** use the server's client,
configured with:

```ini
MCP_PAYPAL_URL=https://mcp.sandbox.paypal.com/http
MCP_PAYPAL_TOKEN=
```

| Environment | `MCP_PAYPAL_URL` |
| --- | --- |
| Sandbox | `https://mcp.sandbox.paypal.com/http` (the default) |
| Live | `https://mcp.paypal.com/http` |

`MCP_PAYPAL_TOKEN` is a PayPal **access token** for the same environment. Get
one with your PayPal app's client ID and secret:

```bash
curl -s https://api-m.sandbox.paypal.com/v1/oauth2/token \
  -u "CLIENT_ID:CLIENT_SECRET" \
  -d "grant_type=client_credentials"
```

Copy `access_token` from the response. For live, use
`https://api-m.paypal.com/v1/oauth2/token` with your live credentials.

> Tokens set in `.env` **expire after a few hours** and must be replaced by
> hand. Keep sandbox tokens with the sandbox URL and live tokens with the live
> URL: mixing them fails authentication. The dashboard route above avoids both
> problems.

## The Send Money assistant

On the **Send Money** page, the **Assistant** tab lets you send money by
describing it: "Send K2,500 to my mum on 0977123456", or "How much does $100
come to in Zambia?". It uses your account's AI providers, with failover.

What it can do:

| Tool | What it does | Needs your approval |
| --- | --- | --- |
| `ListCorridors` | Lists where you can pay out to, and the currencies senders can pay in | No |
| `QuoteTransfer` | Quotes a transfer (rate, what each side pays and gets, route) without creating anything | No |
| `StartTransfer` | Starts the transfer and creates the sender's PayPal checkout | **Always** |
| `GetTransferStatus` | Checks one of your transfers | No |
| PayPal MCP tools | PayPal's own **look-up** tools only (names starting `get_`, `list_`, `show_`, `search_`, `retrieve_` or `track_`), when PayPal MCP is on | **Always** |

The safeguards are in code, not in the prompt:

- **Only what you asked for.** The assistant can only quote or start a
  transfer for the amount and the mobile number you gave in the conversation.
  It can't reuse them from an earlier transfer: if you say "try again" without
  them, it asks. "K2,500" or "2,500 kwacha" means what the recipient receives;
  "$20" means what the sender pays.
- **Send only.** It can quote, start and check transfers, nothing else. It
  can't refund, cancel or reverse a payment; refunds are done from the
  **Payments** page, and a failed delivery is refunded automatically.
- **Nothing lost.** If a message can't be answered (for example the AI provider
  is busy), it's carried into your next message.

- **Nothing moves without you.** Starting a transfer always shows an approval
  card with the recipient, what they receive and what the sender pays, so a
  wrong amount is obvious before you approve. Rejecting it stops it. The sender then
  pays on PayPal, which is a second approval.
- **Spending limits** apply to every transfer, as for the API. See
  [Cross-Border Transfers](/docs/api/cross-border-transfers).
- **Your account only.** The tools only see your providers and your transfers,
  and a conversation can only be continued by the account that started it.
- **Audit.** A transfer the assistant starts is marked as coming from the
  assistant and linked to its conversation, which is stored in the
  conversation tables.

### Recent conversations

Under the **New transfer** card, **Recent conversations** lists the latest
assistant conversations: who had them, when, how many messages, and the
transfers each one started with their status. Open one to read it, then
**Continue this conversation** to pick it up in the assistant.

## Production checklist

- Keys live in the dashboard or in the server's `.env` (or your host's secret
  manager), never in the repository.
- `APP_KEY` is set and stable: dashboard keys are encrypted with it, and
  changing it makes saved keys unreadable.
- **Test** succeeds for each AI provider an account relies on.
- PayPal providers, `MCP_PAYPAL_URL` and `MCP_PAYPAL_TOKEN` use the same
  environment (`sandbox` or `live`).
- A spending limit is set on each AI provider account.
- `php artisan migrate` has run, and `php artisan config:cache` after any
  `.env` change.
