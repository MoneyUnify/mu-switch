---
title: MCP Servers
excerpt: Connect AI assistants and apps to MoneyUnify over MCP, as an operator running its switch or as a merchant on it.
date: 2026-10-18
category: Configuration
---

MoneyUnify runs two [Model Context Protocol](https://modelcontextprotocol.io)
servers, so an AI assistant (Claude, ChatGPT, Cursor and any MCP client) or an
app can use the switch without integrating its REST API.

| Server | URL | For | Signs in with |
| --- | --- | --- | --- |
| **Operator** | `/mcp/operator` | The DFSP, aggregator or mobile-money operator that runs MoneyUnify | The account's [API token](/docs/api-tokens) |
| **Merchant** | `/mcp/merchant` | A merchant the operator collects and settles for | The merchant's own MCP key |

Both take the token as a Bearer token over MCP's streamable HTTP transport.
Every tool calls the same action as the REST API, with the same validation,
provider routing and fallback, spending limits, idempotency and logging, so an
agent can do no more than an integration could. Calls appear on the **Logs**
page like any API request.

## One switch, three ways to run it

- **As your own payment tool.** Collect from your customers, pay out from your
  float, refund, and send PayPal-funded transfers to mobile money from your own
  platform or assistant, with the operator server.
- **As a gateway for others.** Onboard merchants, charge their customers and
  settle them to their wallets less your commission, from the operator server.
  Each merchant can also build on it directly with the merchant server.
- **Both.** The same account does both. The operator server sees everything;
  each merchant key sees only that merchant.

## Operator server

```json
{
  "mcpServers": {
    "moneyunify": {
      "type": "http",
      "url": "https://your-domain.com/mcp/operator",
      "headers": { "Authorization": "Bearer YOUR_API_TOKEN" }
    }
  }
}
```

The **AI & Agents** page shows the URL and this config.

| Area | Tools |
| --- | --- |
| Direct payments | `quote_payment`, `request_payment`, `check_payment`, `refund_payment`, `check_refund` |
| Payouts | `request_payout`, `check_payout` |
| Transfers (PayPal in, mobile money out) | `quote_transfer`, `start_transfer`, `check_transfer`, `list_transfers` |
| Gateway for merchants | `list_merchants`, `onboard_merchant`, `update_merchant`, `charge_merchant_customer`, `merchant_settlements`, `create_pay_link`, `list_pay_links` |
| FX and treasury | `get_exchange_rates`, `lock_fx_quote`, `treasury_balances` |

`request_payment` and `start_transfer` accept `pay_with: card` for
[guest card checkout](/docs/providers#guest-card-checkout), and quotes say
whether the rate is [PayPal's](/docs/api/fx#paypal-fx) in `rate_source`.

## Merchant server

On the **Merchants** page, open a merchant's **AI & apps** and choose **Issue
key**. The key (`mu_mk_…`) is shown once; only a hint is kept. **Replace key**
stops the old key at once, and **Revoke** ends access.

```json
{
  "mcpServers": {
    "kitwe-crafts": {
      "type": "http",
      "url": "https://your-domain.com/mcp/merchant",
      "headers": { "Authorization": "Bearer mu_mk_..." }
    }
  }
}
```

| Tool | What it does |
| --- | --- |
| `merchant_profile` | The merchant's account: wallet, currency, commission, whether it's taking payments. |
| `quote_payment` | What a customer pays for an amount, and what the merchant receives. |
| `create_payment` | Starts a payment: returns `checkout_url` for the customer and `receipt_url`. |
| `list_payments`, `check_payment` | Payments and how each was settled. |
| `list_pay_links`, `create_pay_link`, `set_pay_link_active`, `delete_pay_link` | Saved pay links. |

A merchant key only reaches that merchant: another merchant's payments and
links are reported as not found. Refunds, payouts and onboarding stay with the
operator.

## Safety

- **Money moves only on request.** `request_payout` and `refund_payment` move
  money; payments and transfers move it only when the payer approves on PayPal.
  The server's instructions tell agents to confirm amounts and recipients with
  the user first, and tools carry MCP's destructive and read-only hints.
- **Retries never act twice.** Pass `idempotency_key` to `start_transfer`,
  `request_payout`, `refund_payment`, `charge_merchant_customer` and
  `create_payment`.
- **Rate limited** to 120 calls a minute per client.

## Try it

With demo data (`composer setup --demo-data`, or `php artisan demo:seed`), sign
in as the demo account, copy the API token from the dashboard, and point an MCP client (or the
[MCP Inspector](https://modelcontextprotocol.io/docs/tools/inspector)) at
`/mcp/operator`. Ask it to "quote K2,500 to Zambia" or "list my merchants".
