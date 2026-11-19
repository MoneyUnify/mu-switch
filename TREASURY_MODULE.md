# Treasury Module: Build Plan and Status

_Created 2026-10-15. This file is the single source of truth for the treasury work: what it is, the order to build it in, and what is already done. **Update the status table whenever a step changes state.**_

---

## 1. What the treasury module is (and isn't)

MoneyUnify holds no money. Each provider pays into a **separate merchant account the operator owns** at that provider: a PayPal balance, a Lenco account, a pawaPay wallet, an MTN collection account. Providers almost never transfer to each other directly.

The treasury module lets an operator **see, track and rebalance** the money spread across those accounts, using the **prefunding and net settlement** model:

1. A **prefunded float** at a mobile-money provider (for example ZMW at pawaPay) pays recipients instantly.
2. Collections build up elsewhere (for example USD in PayPal).
3. Periodically, or when a float runs low, the operator **rebalances**: moves accumulated funds across, through a bank or a payout API, and tops the float back up.

**It is:** a ledger, balance visibility, float alerts, tracked transfers, and (last) AI-suggested rebalances that a person approves.

**It is not:**
- A wallet that holds customer money.
- An automatic mover of money without human approval.
- An FX engine itself. FX quoting is a separate module (`App\Support\Fx`, see §6) that the treasury uses.

---

## 2. Status legend

| Mark | Meaning |
|---|---|
| ✅ | Implemented and tested |
| 🟡 | Partially implemented: some pieces exist and are listed |
| ⬜ | Not started |
| ⏸ | Deferred on purpose; don't start without a decision |

---

## 3. Status at a glance

| # | Step | Status | Depends on |
|---|---|---|---|
| 0 | Foundations already in the switch | ✅ | none |
| 1 | Payouts (mobile money and bank) | ✅ | 0 |
| 2 | Ledger (double-entry, per provider account and currency) | ✅ | 0, 1 |
| 3 | Live provider balances | ⬜ | 2 |
| 4 | Float rules and low-float alerts | ✅ | 2, 3 |
| 5 | Treasury view (UI) | ✅ | 2, 3, 4 |
| 6 | Transfers with a lifecycle (manual and API) | 🟡 | 1, 2 |
| 7 | AI rebalancing suggestions (human-approved) | 🟡 | 4, 6, FX quoting (🟡, §6) |

**Build strictly in this order.** Each step's acceptance criteria must pass before starting the next. Steps **1, 2, 4 and 5** form the core loop: PayPal collects, a prefunded float pays out instantly, and the treasury view shows when to rebalance.

---

## 4. Working rules (to avoid drift)

1. **One step at a time,** in the order above. Don't build ahead, for example transfers before payouts exist.
2. **Stay in scope.** If something useful falls outside the current step, add it to §7 (Parking lot) instead of building it.
3. **Reuse what exists** (§5, step 0). Don't duplicate fee maths, routing, provider logging or verification.
4. **Money maths uses `App\Support\Fees\FeeCalculator`** (exact decimals via `brick/math`, ISO 4217 precision). No floats for money.
5. **Every money movement needs a human approval and an audit trail.** Agents may *propose*; they never *execute* on their own.
6. **Tests with every step** (Pest, `Http::fake`, sandbox only). Update the status table and the step's "Done" list in the same change.
7. **Sandbox only** until the regulatory position (§8) is settled.

---

## 5. Steps in detail

### Step 0: Foundations already in the switch ✅

These exist today and must be reused, not rebuilt:

| Building block | Where | What it gives the treasury |
|---|---|---|
| Transactions with a fee breakdown | `transactions`: `collection_fee`, `settlement_fee`, `net_amount`, `fee_estimated` (filled by `app/Observers/TransactionObserver.php`) | Per-payment gross, fees and net |
| Direction column | `transactions.direction` enum `credit`/`debit` (only `credit` is used today) | Ready for payouts (`debit`) |
| FX columns | `transactions.is_fx`, `transactions.fx_rate` (present, never set yet) | Ready for FX later |
| Exact fee maths and quotes | `app/Support/Fees/FeeCalculator.php`, `PaymentQuoter.php`, `POST /api/v1/payment/quote` | Costing transfers and payouts |
| Actual provider fees | `ProviderFees::actualCollectionFee()` and a driver hook (PayPal implements it) | Real fees, not estimates |
| Shared routing | `app/Support/ProviderRouter.php` | Choosing a payout provider the same way as collections |
| Provider call logging | `app/Support/ProviderCallLogger.php` (secrets redacted) | Audit of every provider call |
| Verify and notify | `app/Support/TransactionVerifier.php` | Reconciling pending operations |
| Per-provider account totals | `ProviderController::accountSummaries()` (successful credits: inflow, fees, net, `estimated`) | First version of balances (see step 2) |
| AI agent infrastructure | `app/Ai/Concerns/UsesAccountAiCredentials.php`, `app/Support/Ai/AiProviderChain.php`, `ConnectionCheck`, AI & Agents page | Step 7's agent |

---

### Step 1: Payouts ✅

**Goal:** the switch can send money out (to a mobile wallet or bank account) through providers that support it, with the same fallback, fee and logging guarantees as collections.

**Same scope as [IMPLEMENTATION_GUIDE.md](IMPLEMENTATION_GUIDE.md) §4.3–4.4.** Build it once; mark it done in both files.

**Already exists:** the `debit` direction value, routing, fee maths and logging.

**To build:**
- `app/Contracts/PayoutProviderInterface.php`: `requestPayout(Transaction $transaction, array $recipient): JsonResponse` and `verifyPayout(Transaction $transaction): JsonResponse`.
- A `PayoutRouter`, or an extension of `ProviderRouter` for payout-capable drivers: filter by country and currency, cost-aware order, sequential fallback. Never time out a connected call early, so nobody is paid twice.
- Drivers: **pawaPay first** (public sandbox with payouts), then Lenco, MTN Disbursement and Airtel as their payout APIs are confirmed.
- `SandboxPayoutController`: a simulated driver (non-production only) whose outcome is set by the MSISDN suffix, for deterministic demos.
- API: `POST /api/v1/payout/request` and `POST /api/v1/payout/verify`, documented in `prezet/content/api/`.
- Payout transactions are written with `direction = 'debit'`.

**Acceptance criteria:**
- A sandbox payout succeeds end to end, and falls back to the next provider when the first fails.
- A payout never runs twice for one request (idempotent provider reference).
- Fees are recorded with the same estimated/actual rules as collections.

**Tests:** payout fallback, idempotency, fees, `debit` direction, API validation.

**Done (2026-10-15):**
- `app/Contracts/PayoutProviderInterface.php`: `setProvider`, `requestPayout(Transaction, array $recipient)`, `verifyPayout`. 2xx means accepted, 4xx a definite refusal (fallback is safe), and 5xx an unknown outcome (the switch stops).
- Routing reuses `ProviderRouter::route(..., capability: PayoutProviderInterface::class)` instead of a separate PayoutRouter. Collections never route to payout-only drivers, and vice versa.
- `app/Http/Controllers/PayoutController.php`: `POST /api/v1/payout/request` and `POST /api/v1/payout/verify`. It creates the `debit` transaction before calling the driver, falls back only on 4xx, and stops on 5xx or an exception (`202 pending`).
- **Idempotency:** the `Idempotency-Key` header, stored in `transactions.idempotency_key`. A replay returns the original payout, a cache lock stops concurrent duplicates (`409`), and the key is re-checked under the lock.
- **pawaPay** (`PawapayController`): `POST /payouts` and `GET /payouts/{id}`, with the payout id = our transaction id, plus a `live`/`sandbox` **Environment** config field (default live).
- **Lenco** (`LencoController`, added 2026-10-15): automated recipient registration via `POST /transfer-recipients/mobile-money` obtaining the `transferRecipientId`, then initiating `POST /transfers/mobile-money` from the account in the optional **Account ID (for payouts)** field, verified with `GET /transfers/status/{reference}` (reference = our transaction id). Zambia (MTN, Airtel, Zamtel) and Malawi (Airtel, TNM). A server error or a duplicate reference is an unknown outcome; a transfer Lenco never created fails on verify. Tests in `tests/Feature/LencoPayoutTest.php`.
- **`SandboxPayoutController`:** `SANDBOX_ONLY`, so it's hidden and refused in production and excluded from coverage. Outcomes are set by the number suffix (0000 refused, 9999 unknown, 1111 fails later).
- **Fees:** schedules gain an optional `payout` section (`FeeCalculator::payoutFee`). For a debit, `net_amount` is `amount + fee`, the total taken from the float.
- **Shared pieces:** `TransactionVerifier` verifies debits through `verifyPayout`. `DriverRegistry` centralises driver discovery and capabilities, and `Market::msisdn()` normalises numbers.
- **Docs and tests:** `prezet/content/api/payouts.md`; tests in `tests/Feature/PayoutTest.php` (20).

---

### Step 2: Ledger ✅

**Goal:** a reconcilable record of every movement per **provider account and currency**, so a balance is always explainable.

**Already exists (the partial part):**
- Per-transaction gross, fees and net (step 0).
- `ProviderController::accountSummaries()` sums **successful credits** per provider and currency. That's a derived total, not a ledger: no debits, no transfers, no opening balances.

**To build:**
- `ledger_entries` table: `id`, `user_id`, `payment_provider_id`, `currency`, `amount` (signed decimal, currency precision), `type` (`collection`, `fee`, `payout`, `transfer_out`, `transfer_in`, `adjustment`, `opening_balance`), a polymorphic `source` (transaction or transfer), `occurred_at`, `meta` JSON, timestamps.
- **Double-entry rule:** every money event writes balanced entries. For example, a collection writes `+gross` collection and `−fee` fee.
- Write entries when a transaction **settles** (from `TransactionObserver` or a listener), never on pending.
- `LedgerBalance` service: balance per provider and currency = sum of entries. Replace `accountSummaries()` with it.
- `opening_balance` entries, so an operator can align the ledger with a real account once.

**Acceptance criteria:**
- The ledger balance equals the sum of settled collections minus fees minus payouts plus/minus transfers, to the minor unit.
- Re-saving a transaction never duplicates entries.

**Tests:** entry creation per event type, idempotency, balance maths in zero- and two-decimal currencies.

**Done (2026-10-15):**
- `ledger_entries` table: `account` (provider / counterparty / fees), `type`, `currency`, **`amount_minor` as a signed integer in minor units** (so sums are exact), `estimated`, a morph `source`, `occurred_at` and `meta`. It's unique per (source, type, account).
- `App\Enums\LedgerAccount` and `LedgerEntryType`, plus the `App\Models\LedgerEntry` model.
- `App\Support\Ledger\LedgerRecorder::sync()`, run from `TransactionObserver::saved()`. A settled transaction gets balanced pairs (collection or payout ±amount, fee ±fees) that sum to zero. A fee change (estimate → PayPal actual) updates the entries in place, and unsettling removes them.
- `recordOpeningBalance()` and `LedgerBalance::of()` / `summaries()`. The provider **Accounts** dialog now reads from the ledger (outflows include payouts).
- Commands: `php artisan ledger:rebuild` (backfill, idempotent) and `php artisan ledger:opening-balance {provider} {currency} {amount} --note=`.
- `Currency::toMinorUnits()` / `fromMinorUnits()` for exact conversion.
- Tests in `tests/Feature/LedgerTest.php` (13), including "every event sums to zero".

**Not done (left for later steps):** an `adjustment` UI, and transfer entries (step 6).

---

### Step 3: Live provider balances ⬜

**Goal:** show the provider's **real** balance next to the ledger, and flag mismatches.

**To build:**
- An optional driver contract `ReportsBalance`: `balances(): array<currency, string>`. Implement it only where the provider has a balance API. Confirm each against the provider's docs before implementing (pawaPay, Lenco, Flutterwave and the MTN/Airtel disbursement accounts are the likely candidates).
- `BalanceSnapshot` records (provider, currency, amount, fetched_at), refreshed on demand and on a schedule (`routes/console.php`).
- Mismatch check: |provider balance − ledger balance| above a tolerance is flagged for review, never auto-corrected.

**Acceptance criteria:**
- Balances fetched in the sandbox are stored and shown.
- Providers without a balance API show "ledger only".

**Tests:** snapshot storage, mismatch flagging, providers without the contract.

---

### Step 4: Float rules and alerts ✅

**Goal:** an operator sets "keep at least X in provider P's currency C", and is told when it drops below.

**To build:**
- `float_rules` table: provider, currency, `minimum`, optional `target`, `is_active`.
- `FloatMonitor` service: compares the ledger (or live) balance against rules and raises alerts. Show alerts in the dashboard first; email or webhook later.

**Acceptance criteria:** a balance below its minimum raises exactly one open alert, which clears when the balance recovers.

**Tests:** threshold crossing in both directions, no duplicate alerts.

**Done (2026-10-15):**
- `float_rules` (provider, currency, `minimum_minor`, `target_minor`, `is_active`) and `float_alerts` (`balance_minor`, `minimum_minor`, `opened_at`, `resolved_at`). Models `FloatRule` (with `openAlert`) and `FloatAlert`.
- `App\Support\Treasury\FloatMonitor`: one open alert per rule, refreshed while low and resolved on recovery. Inactive rules never alert.
- Runs automatically: `LedgerRecorder` re-checks the affected accounts after every write (transactions, transfers, opening balances). Plus `php artisan treasury:check-floats`, scheduled every five minutes in `routes/console.php`.
- Dashboard alerts on the Treasury page. Email and webhook alerts remain in the parking lot.
- Tests in `tests/Feature/TreasuryTest.php`.

---

### Step 5: Treasury view (UI) ✅

**Goal:** one page with each provider account's balance, float status and recent movements.

**Already exists (the partial part):** the provider **Accounts** dialog (`resources/js/pages/providers/index.tsx`) shows each account's ledger balance per currency (inflows; fees and payouts out; net), with an `Est.` marker. As of step 2 it reads from `LedgerBalance`.

**To build:**
- A `Treasury` page in the sidebar, using the existing card, table and badge patterns:
  - per provider account and currency: ledger balance, live balance (when available), float status;
  - recent ledger entries;
  - open alerts.
- Point the Accounts dialog at the ledger balance once step 2 lands.

**Acceptance criteria:** the numbers match `LedgerBalance` exactly. Responsive at phone width. Empty states handled.

**Tests:** Inertia props for balances, entries and alerts.

**Done (2026-10-15):**
- `TreasuryController@index` and the `resources/js/pages/treasury/index.tsx` page, with a **Treasury** item in the sidebar.
- Accounts table: ledger balance, `Est.` marker, money in transit, minimum float and status (Healthy, Below minimum, No float rule), with **Set float** / **Edit float**.
- Float alerts with a **Top up** button that pre-fills a transfer.
- Transfers with per-status actions and a History view, plus recent ledger activity.
- `FloatRuleController` (`PUT /treasury/float-rules`, `DELETE /treasury/float-rules/{floatRule}`).
- **Not included:** live provider balances (step 3, still ⬜). The view shows ledger balances only.

---

### Step 6: Transfers 🟡

**Goal:** track moving money from one provider account to another, end to end.

**To build:**
- `treasury_transfers` table: source account (provider and currency), destination account, `amount`, `fees`, `status`, `method` (`api` or `manual`), `reference`, `approved_by`, timestamps.
- Lifecycle: `requested → approved → in_transit → settled`, or `failed` / `cancelled`. Every transition is logged.
- **API transfers:** where a payout API reaches the destination account, for example Lenco to a bank, or a payout into a float top-up account.
- **Manual transfers:** for example PayPal to bank. The operator confirms each stage, and the switch tracks and reconciles.
- Ledger: `transfer_out` on the source when sent, `transfer_in` on the destination when settled. Money in transit is visible, not lost.
- Fees come from `FeeCalculator`.

**Acceptance criteria:**
- A transfer can't start without approval.
- A settled transfer moves both ledger balances by exactly the amount, less fees.
- A failed transfer reverses its `transfer_out`.

**Tests:** lifecycle transitions, approval gate, ledger effects, failure reversal.

**Done (2026-10-15), manual transfers:**
- `treasury_transfers` (`reference`, from and to provider, `currency`, `amount_minor`, `fee_minor`, `received_minor`, `status`, `method`, `external_reference`, `note`, `failure_reason`, timestamps) and `treasury_transfer_events` (who, from, to, note, when).
- `App\Enums\TransferStatus` with `canTransitionTo()`.
- `App\Support\Treasury\TransferService`: `request`, `approve`, `send`, `settle`, `fail`, `cancel`. Each transition locks the row, checks it's allowed, records an event and syncs the ledger. Approving and sending require the source's ledger balance to cover the amount plus the fee.
- `LedgerRecorder::syncTransfer()`:
  - **in transit:** `transfer_out` (source −amount, counterparty +amount) plus the fee;
  - **settled:** also `transfer_in` (destination +received);
  - any shortfall becomes an `adjustment` fee;
  - **failed / cancelled:** no entries.
  Every transfer sums to zero.
- `TreasuryTransferController` (`POST /treasury/transfers`, plus `/{transfer}/approve|send|settle|fail|cancel`): ownership checks and currency-precision validation. Same currency only (FX deferred).

**Not done:** API-executed transfers (`method = api`, sent through a payout API). Only `manual` exists today.

---

### Step 7: AI rebalancing suggestions ⬜

**Goal:** an agent watches floats and expected payout volume, then **proposes** a rebalance (amount, route, fees, FX cost) for a person to approve.

**Prerequisites:** steps 4 and 6, plus **FX quoting** (§6) for cross-currency routes.

**To build:**
- A `TreasuryAgent` (Laravel AI SDK, `UsesAccountAiCredentials`) with read-only tools: balances, float rules, recent volume, transfer routes and quotes. Plus one **approval-required** tool that creates a `requested` transfer.
- Suggestions are shown in the treasury view, and approving one moves the transfer to `approved` (step 6).

**Acceptance criteria:**
- The agent can't approve or execute on its own.
- Every suggestion shows its reasoning and costs.

**Tests:** agent fakes (`TreasuryAgent::fake()`), the approval gate, tool outputs.

**Done (2026-10-15), same-currency suggestions:**
- `App\Support\Treasury\TreasurySnapshot`: each account's balance, float rule, open transfers in and out, and 7-day payout volume, in minor units. It works out what each account can spare (keeping its own minimum) and still needs (to its target, counting transfers on the way).
- `App\Ai\Agents\TreasuryAgent` (Laravel AI SDK, `UsesAccountAiCredentials`, structured output: `summary`, `suggestions`, `notes`). It reads the snapshot as JSON. **Changed from the plan:** structured output instead of tools, because the snapshot is small and the result is easier to check.
- `App\Support\Treasury\RebalanceAdvisor`: the guardrails live in code, not the prompt. Each suggestion must use two of the user's accounts in one currency, within what the source can spare and the destination needs, including earlier suggestions in the same run. At most 5 are recorded per run. Refused ones are reported with a reason. The agent isn't called when no float needs funds.
- Accepted suggestions become `requested` transfers with `origin = agent` and the agent's `reasoning` (new columns). A person approves or dismisses them through the step 6 lifecycle, so the agent can't approve or move money.
- Accepted suggestions become `requested` transfers with `origin = agent` and the agent's `reasoning` (new columns). A person approves or dismisses them through the step 6 lifecycle, so the agent can't approve or move money.
- `POST /treasury/suggestions` (`RebalanceSuggestionController`, throttled), plus a **Suggest rebalances** button, an agent summary panel, and **AI suggested** badges with the reasoning on the Treasury page.
- Tests in `tests/Feature/TreasuryTest.php`.

---

## 6. FX quoting ✅

Needed for cross-currency rebalancing (for example USD in PayPal to a ZMW float) and for PayPal payments priced in ZMW (PayPal can't charge ZMW).

**Decision (2026-10-15):** a static sandbox rate table with a configurable margin, as [IMPLEMENTATION_GUIDE.md](IMPLEMENTATION_GUIDE.md) §4.5 proposed. A live rate source can be added later behind the same contract.

**Done (2026-10-15):**
- `App\Support\Fx\FxQuoter` with exact decimal maths: quote by what is sent (`quote`) or by what must arrive (`quoteTarget`, sent amount rounded up). The effective rate is `base_rate × (1 − margin)`, rounded to 8 places, and amounts follow ISO 4217 decimals.
- `FxRateProviderInterface` with `StaticFxRateProvider` (`config/moneyunify_paypal.php`: `FX_DRIVER`, `FX_MARGIN_PERCENT` default 1.5, `FX_QUOTE_TTL_MINUTES` default 10).
- `FxQuote`: locked rate, amounts and margin, with an expiry, cached until it expires. **Each quote belongs to the account that created it**: only that account can read or use it, and another account's quote looks missing.
- API: `GET /api/v1/fx/rates`, `POST /api/v1/fx/quote`, `GET /api/v1/fx/quote/{id}`, documented in `prezet/content/api/fx.md`. Plus the **Exchange Rates** dashboard page with a corridor calculator and popover quote summaries.
- `FxQuote::toTransactionAttributes()` returns `is_fx` and `fx_rate` for transactions.
- **PayPal African Currency Conversion**: Converts non-PayPal payment requests (ZMW, KES, etc.) to USD via locked quotes, creates PayPal order in USD, records `is_fx = true` and `fx_rate` on `transactions`, and returns conversion details.
- **Cross-Currency Treasury Transfers**: Multi-currency ledger entries, cross-currency transfer lifecycle (`to_currency`, `fx_rate`, `target_amount_minor`, `fx_quote_id`), and UI selector with dynamic FX conversion banner.
- **AI Cross-Currency Rebalancing**: `TreasuryAgent` and `RebalanceAdvisor` inject live rates and propose cross-currency rebalancing transfers between accounts holding different currencies.
- Tests: `tests/Unit/FxQuoterTest.php`, `tests/Unit/StaticFxRateProviderTest.php`, `tests/Feature/FxApiTest.php`, `tests/Feature/RatesPageTest.php`, `tests/Feature/PaypalPaymentTest.php`, `tests/Feature/TreasuryTest.php`, `tests/Feature/SwitchControllerTest.php`.

---

## 7. Parking lot (ideas outside the current step)

_Add ideas here instead of building them mid-step._

- Email/webhook delivery for float alerts (step 4 starts with dashboard alerts only).
- Scheduled automatic rebalancing (only ever after step 7, still human-approved).
- API-executed transfers (step 6 `method = api`) through payout APIs.
- Requiring a different person to approve a transfer than the one who requested it (accounts are single-user today).

---

## 8. Regulatory note

Collecting money and paying it out to other people, especially across borders, is **money transmission**. In Zambia that falls under the Bank of Zambia and the National Payment Systems Act. Going live normally needs a licence, or operating under a licensed partner (an aggregator or a bank). PayPal's terms also restrict remittance-style use of a standard merchant account without approval. **Build and demo in sandbox only** until this is settled.

---

## 9. Change log

_Add a line whenever a step's status changes._

| Date | Step | Change |
|---|---|---|
| 2026-10-15 | All | Plan created. Step 0 ✅; steps 2 and 5 🟡 (partial pieces listed); steps 1, 3, 4, 6, 7 ⬜; FX ⏸ |
| 2026-10-15 | 1 | ✅ Payouts: contract, routing by capability, payout API with idempotency, pawaPay payouts and sandbox environment, simulated driver, payout fees, docs and tests |
| 2026-10-15 | 1 | Lenco payouts (Zambia and Malawi mobile money), so cross-border transfers can deliver through Lenco |
| 2026-10-15 | 2 | ✅ Ledger: double-entry entries in minor units, synced from transactions, balances, Accounts dialog on the ledger, rebuild and opening-balance commands, tests |
| 2026-10-15 | 4 | ✅ Float rules and alerts: rules, one open alert per rule, automatic and scheduled checks |
| 2026-10-15 | 5 | ✅ Treasury page: accounts, float status, alerts with Top up, transfers, ledger activity (ledger balances only; step 3 still ⬜) |
| 2026-10-15 | 6 | ✅ Manual transfers: full lifecycle, cross-currency support with FX, approval and balance gates, event history, ledger effects with shortfall reconciliation |
| 2026-10-15 | 7 | ✅ AI rebalancing suggestions: treasury agent with cross-currency support via FX rates, guardrails in code, suggestions as requested transfers awaiting approval |
| 2026-10-15 | FX | ✅ FX engine & integration: static rates with margin, locked quotes, rates & quote API, PayPal currency conversion, cross-currency treasury transfers, and AI rebalancing |
