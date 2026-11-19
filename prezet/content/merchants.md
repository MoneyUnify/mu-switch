---
title: Merchants (for Aggregators)
excerpt: Onboard merchants, give each one a PayPal pay link, and settle every payment to the merchant's mobile-money wallet, with your commission built in.
date: 2026-10-17
category: Configuration
---

MoneyUnify lets an **aggregator** (or a bank, a payment service provider, or a
mobile-money operator) collect for many **merchants**. A merchant's customers
pay from anywhere with **PayPal**, and the merchant is settled to its own
**mobile-money wallet**, in its own currency, seconds after PayPal confirms the
payment. Nobody has to integrate PayPal, an FX source or a mobile-money
operator themselves.

## How a merchant payment works

1. The customer opens the merchant's **pay link** (or your app charges them
   through the [merchant API](/docs/api/merchants)) and sees the price, what
   they pay in their currency, and the rate.
2. They pay with **PayPal**.
3. When PayPal confirms, the switch pays the merchant's wallet from your
   payout float (Lenco or pawaPay), at the locked rate, **minus your
   commission**.
4. If the merchant can't be paid, the customer is **refunded through PayPal**
   automatically. Every step is on the payment's timeline, and the customer
   has a live receipt page.

| Who | Gets |
| --- | --- |
| Customer | Pays exactly the price shown, with PayPal. |
| Merchant | The price, in its currency, less your commission, in its wallet. |
| You (aggregator) | The FX margin plus your commission, per payment. You pay the PayPal and payout provider fees. |

## Before you start

On the **Providers** page, add:

- an active **PayPal** account, which collects the customers' payments;
- at least one **payout** provider for each country your merchants are in, for
  example **Lenco** (with its **Account ID (for payouts)** set) or **pawaPay**.

Keep a float in each payout account, and set a [float rule](/docs/treasury) so
you're alerted before one runs low.

## Add a merchant

On the **Merchants** page, choose **Add merchant**:

| Field | Meaning |
| --- | --- |
| **Business name** | Shown to customers on the pay page. |
| **Settle in** | The merchant's country. Its currency is set from it. Only countries your payout providers serve are listed. |
| **Mobile-money number** | The wallet the merchant is paid into. |
| **Network** | Optional; detected from the number where possible. |
| **Your commission** | 0 to 20%, kept from each settlement. Rounded in the merchant's favour. |

## Pay links

Each merchant has an open pay page, and as many saved **pay links** as you
need, for example one per product, invoice or campaign. Choose **Links** next
to a merchant to see them all and to create new ones:

| Setting | Meaning |
| --- | --- |
| **Name** | Your label, also shown to the customer. |
| **Customer pays with PayPal in** | One or more currencies your PayPal account collects (for example USD and EUR). Choose none to allow any. |
| **Settle the merchant in** | The currency the merchant is paid in. Only currencies your payout providers settle in its country are offered; usually that's just the local one. |
| **Fixed price** | Optional. When set, the customer can't change the amount. |
| **Reference** | Optional, for example an invoice number. |

Each link gets a short address such as `https://your-domain.com/pay/l/k3x9q2m7ab`.
Its settings are enforced by the server, not just shown on the page. For each
link you see what it has collected and settled, and you can:

- **Copy** or **open** it.
- **Disable** it: it stops taking payments at once. **Enable** it to reopen it.
- **Delete** it. A link that has never taken a payment is deleted. A link that
  has taken payments is **archived** instead: it stops working and leaves the
  list, and its payments keep their full history, for reconciliation and
  refunds.

On PayPal's checkout, the customer sees the **merchant's name** as the
business they're paying.

**Pause** a merchant to stop new payments; payments already made still settle.

## What the Merchants page shows

- **Merchants:** how many, how many are active, and how many have been paid.
- **Payments settled** and the total **settled to mobile money**, per
  currency, with your commission.
- **Average cost to merchants:** how much less merchants received than their
  customers' payments were worth at the mid-market rate, all in. Set
  `IMPACT_BENCHMARK_COST_PERCENT` (for example to the World Bank's Remittance
  Prices Worldwide average for your region) to compare against it.
- Each merchant's payments, settled total and commission, and recent merchant
  payments.

## Limits

Merchant payments are checked against the per-payment cap
(`TRANSFER_MAX_PER_TRANSACTION_USD`). They don't count towards your account's
daily send limit, which is for money you send yourself. See
[Cross-Border Transfers](/docs/api/cross-border-transfers).
