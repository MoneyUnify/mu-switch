---
title: Treasury
excerpt: See your money across provider accounts, get alerted when a float runs low, track transfers between accounts, and let the treasury agent suggest rebalances for you to approve.
date: 2026-10-15
category: Configuration
---

The switch holds no money. Each provider pays into **your own account at that
provider**. The **Treasury** page (in the sidebar) brings those accounts
together so you can see where your money is, keep payout floats topped up, and
move money between accounts with a full audit trail.

## Accounts and the ledger

Every settled payment, payout, transfer and opening balance is written to a
**double-entry ledger**. Each account's balance on the Treasury page, and in a
provider's **Accounts** dialog, is the ledger total for that provider and
currency:

- **Payments in** add to the provider's account.
- **Payouts**, **refunds**, **fees** and **transfers out** take money out of it.

[Cross-border transfers](/docs/api/cross-border-transfers) use both sides: the
sender's payment arrives in your PayPal account, and the recipient is paid from
your payout float. Keep a float rule on payout accounts so you're alerted
before one runs low.

Balances marked **Est.** include fees estimated from a published schedule
rather than reported by the provider.

If an account already held money before you used the switch, record it once so
the ledger matches the real account. See
[Self-Hosted Installation](/docs/installation#ledger).

## Float rules and alerts

A **float** is money kept at a provider so payouts can go out instantly. Use
**Set float** on any account to give it:

| Setting | Meaning |
| --- | --- |
| **Minimum** | You're alerted when the balance drops below this. |
| **Target** *(optional)* | The amount to top up to. |

When a balance falls below its minimum, the page shows **one** alert for that
account, with the balance, the minimum and the target. The alert clears as soon
as the balance is back above the minimum, for example after a top-up.

Balances are re-checked whenever money moves, and the scheduled
`treasury:check-floats` command re-checks every rule as a safety net. To run
it, make sure your server runs Laravel's scheduler (`php artisan schedule:run`
every minute).

## Transfers between accounts

Use **New transfer** (or **Top up** on a float alert) to move money from one of
your provider accounts to another. Nothing moves until you approve and send it:

| Step | What it means | Effect on the ledger |
| --- | --- | --- |
| **Requested** | You've asked for the transfer. | None |
| **Approved** | You've checked it. The source must hold the amount plus the fee. | None |
| **In transit** (*Mark sent*) | The money has left the source account, e.g. you made the bank transfer. Add its reference. | The amount and fee leave the source account |
| **Settled** (*Confirm received*) | The money arrived. Enter what actually arrived. | The amount arrives in the destination account |
| **Failed** | It never arrived. Give a reason. | The source account is restored |
| **Cancelled** | Withdrawn before it was sent. | None |

How this keeps your records accurate:

- **Shortfalls are reconciled.** If less arrives than was sent, for example a
  bank took a fee on the way, the difference is recorded as a fee, so both
  accounts still add up.
- **Money in transit is visible.** It shows against the source account and is
  never lost from the books.
- **Every step is recorded,** with who did it, when and any note. Open
  **History** on a transfer to see it.
- **Same currency only.** Moving money between currencies needs FX, which isn't
  available yet.

Transfers are tracked manually: you make the actual bank or provider transfer
yourself, then record each step here.

## Rebalancing suggestions (AI)

**Suggest rebalances** asks the treasury agent to propose transfers that bring
low floats back up. It reads each account's balance, float rule, transfers
already on the way, and the last 7 days of payouts (how many days of payouts
the float covers), then proposes which account should top up which, and why.

The agent **only proposes**. Each suggestion appears in **Transfers** as a
**Requested** transfer marked **AI suggested**, with its reasoning. You then
**Approve** it or **Dismiss** it, and it follows the usual steps from there.

Every suggestion is checked against the ledger before it's recorded. A
suggestion is refused, and listed with the reason, if it:

- uses an account that isn't yours, or the same account on both sides;
- needs a currency conversion (FX isn't available yet);
- is more than the destination needs to reach its target (or its minimum when
  it has no target), counting transfers already on the way;
- would take the source below its own minimum float, counting other transfers
  it has promised.

One run records at most five transfers. If every float is already at or above
its target, the agent isn't asked at all.

The agent uses your account's AI providers from **AI & Agents**, or the `.env`
default. See [AI & MCP Setup](/docs/ai-and-mcp).
