# HANDOFF.md — Node PSP session log

Read this first in every session; update it before ending one. Newest entry on
top. Keep entries short: what changed, what's verified, what's next, what's blocked.

---

## 2026-08-18 — Deployment (Docker + Render) + Masenu fraud screening

**State:** On `feat/deploy-and-fraud` (off `main`). `php artisan test` → **57 passed
(163 assertions)**. Docker image built AND run locally (web + worker verified).

**Done (verified):**
- **Deployment.** One image (`Dockerfile`, FrankenPHP + PHP 8.4), two roles via
  `docker/entrypoint.sh` (web default / `horizon` / `artisan …`). `docker/Caddyfile`
  binds `$PORT`. `.dockerignore` excludes `.env` and dev-time `bootstrap/cache/*.php`
  (else `--no-dev` boot fails on `Laravel\Pail\PailServiceProvider`). `render.yaml`
  blueprint = web + worker + managed Postgres + Redis (`DB_URL`/`REDIS_URL` injected,
  migrate via preDeploy). `docker-compose.yml` gained `app`+`worker` under the `app`
  profile for a local dry-run. [DEPLOY.md](DEPLOY.md) written.
- **Verified locally:** `docker compose --profile app up` → `/up` 200 on **:8088**
  (8080 collides with local Jenkins), created a merchant, `GET /v1/balance` 200,
  `POST /v1/virtual-accounts` 201, unauth 401; worker logged "Horizon started".
- **Fraud screening (Masenu).** `MasenuClient::assertAllowed($msisdn,$context)` on
  collections + payouts before anything is created; block → `FraudBlockedException`
  → `422 fraud_blocked`, nothing reserved/written. Entity **edge-hashed** with the
  consortium pepper (raw MSISDN never sent) → Masenu `/v1/lookups`; maps
  `recommended_action`/`risk_score`. Config `psp.fraud` ← `MASENU_*`. Disabled by
  default; `force()` hook for tests/local. Singleton-bound.

**Verified how:** +5 fraud tests (blocked payer → 422 + no txn, allowed, disabled
default, blocked recipient no-reserve, real-response mapping + hash assertion) and a
full local container run. Full suite 57 green.

**Next:** open PR → main. Then: run Masenu Pro locally (`MASENU_BASE_URL`) for a real
cross-service demo; real MTN sandbox creds (PR #7 tooling); FX-on-payout; liquidity
partner. Consider upgrading web server settings / plans before real traffic.

**Blocked / not done (by design):** provider/BaaS/rates still stubbed/sandboxed;
Render free PG/Redis expire (upgrade for prod); fraud fails **open** by default.

---

## 2026-08-12 — Settlement + webhook queues (Horizon)

**State:** On `feat/horizon-queue` (off `main`). `php artisan test` → **52 passed
(152 assertions)**. Verified live on real Redis with a worker.

**Done (verified):**
- **Laravel Horizon** installed + configured. Two Redis queues: **settlement**
  (`ReconcileTransaction` — polls provider, settles; `WithoutOverlapping` per txn,
  5 tries + backoff) and **webhooks** (`DeliverWebhook`). Supervisor priority
  settlement → webhooks → default. `config/queue.php` redis `after_commit = true`.
- **Slow work moved off the request path:** MTN callback now enqueues a reconcile
  (returns instantly) instead of a blocking GET; `WebhookDispatcher::dispatch`
  enqueues `DeliverWebhook`; the `psp:poll-pending` / `crypto:poll-deposits` /
  `bank:poll-payouts` / `webhooks:flush` commands now enqueue jobs instead of
  working inline. `horizon:snapshot` scheduled.
- **Infra:** Redis added to `docker-compose.yml`; `.env.example` QUEUE_CONNECTION=redis
  + `HORIZON_DASHBOARD_EMAILS`; dashboard gated by a `viewHorizon` gate. Tests use
  the `sync` queue (jobs inline), so no Redis needed for CI.

**Verified how:** +5 queue tests (MTN callback enqueues reconcile / nothing when
terminal; status change enqueues DeliverWebhook; DeliverWebhook signs+POSTs via
Http::fake; poll command enqueues jobs). **Live on Redis:** enqueued a settlement
job (`queues:settlement`=1), ran one `queue:work` pass → job DONE → bank payout
`succeeded`, USD balance drawn down, reservation released.

**Next:** open PR → main. Then: real provider/BaaS/watcher/rates + liquidity partner
behind the seams; a payout webhook to replace the BaaS poll; per-corridor currency
allow-list on the MTN driver.

**Blocked / not done (by design):** provider integrations still stubbed/sandboxed;
prod needs `horizon` + `schedule:run` under a process supervisor.

---

## 2026-08-08 — Concurrency hardening: Postgres + row-locked reservations

**State:** On `feat/concurrency-reservations` (off `main`). `php artisan test` →
**47 passed (142 assertions)**. Migrations + a live concurrency race verified on
real Postgres.

**Done (verified):**
- **Row-locked balance reservations** replace the old check-then-write guard.
  `balance_reservations (merchant_id, currency, reserved_minor)` unique row is the
  lock anchor. `BalanceService::reserve()` (inside a DB txn) `lockForUpdate`s the row,
  re-checks `available = settled − reserved`, and raises the hold or throws 422.
  `release()` frees it. `available()` = settled − reserved.
- **Wired into every debit:** Payout, BankPayout, FX-out reserve on initiate;
  `TransactionReconciler` releases on any debit terminal (settled → moved, failed →
  returned). Removed the inflight-sum query.
- **Postgres:** `docker-compose.yml` (postgres:16), `.env` pgsql block documented.
  All 17 migrations run clean on PG. Stack: SQLite dev/CI, **Postgres prod** (the
  lock is a no-op on SQLite, which serialises writes anyway).

**Verified how:** +4 reservation tests (reserve↓available/release↑, over-reserve
throws & holds nothing, sequential reserves can't exceed, failed payout releases).
**Live race on Postgres:** funded $1,000 USD, fired **10 parallel** FX conversions
of $300 → exactly **3× 201, 7× 422**, USD left $100.00, 3 conversions, never negative.
This is the actual proof `FOR UPDATE` serialises concurrent spends.

**Next:** open PR → main. Then: settlement/webhook queue (Horizon), per-corridor
currency allow-list on the MTN driver, real provider/BaaS/watcher/rates + liquidity
partner behind the existing seams.

**Blocked / not done (by design):** provider integrations still stubbed/sandboxed;
FX rates are config; no crypto withdrawal.

---

## 2026-08-08 — Outbound foreign-currency (bank) payout

**State:** On `feat/outbound-payout` (branched off merged `main`). `php artisan test`
→ **43 passed (131 assertions)**. Live corridor smoke test passed.

**Done (verified):**
- **`POST/GET /v1/bank-payouts`** — wire USD/GBP/EUR out of a merchant balance to an
  external bank beneficiary (beneficiary stored in tx `meta`). Mirrors the mobile
  payout: created Pending → provider submits → Processing → poll/settle on success
  (debit `merchant_payable` amount+fee, credit `bank_float`, fee→revenue). Failure
  moves no money.
- **Banking provider is now bidirectional:** `BankingProvider` composite interface
  (`VirtualAccountProvider` + `BankPayoutProvider`); BaaS + Fake implement `payout`
  + `payoutStatus`. `TransactionType::BankPayout` + `isDebit()`; `bank:poll-payouts`
  command (scheduled).
- **Overspend guard generalised:** `BalanceService::available` now subtracts in-flight
  payouts of BOTH rails (mobile + bank).
- Scopes `bank_payouts:read/write`; `beneficiary` surfaced in the transaction payload.

**Verified how:** +4 tests (insufficient balance, success draws down, in-flight
reduces available across rails, provider rejection moves no money). Live: funded a
USD virtual account ($2,000 in → $1,970 net), wired $500 out to a Chase beneficiary,
polled → settled, balance $1,470.00. Round trip (receive → FX → pay out) now complete.

**Next:** open PR → main; real BaaS partner (issuance + payout + a payout webhook to
replace the poll stub); FX-on-payout (auto-convert local→USD at send); liquidity/FX
partner integration behind the rate + banking seams (see the "liquidity is the moat"
note — our provider abstractions are exactly where a partner like that plugs in).

**Blocked / not done (by design):** BaaS payout is a stub (real wire needs a licensed
partner); no payout webhook yet (poll only); balance guard still check-then-write.

---

## 2026-08-07 — Virtual USD/GBP/EUR receiving accounts

**State:** On `feat/psp-core` (PR #3 → main). `php artisan test` → **39 passed
(118 assertions)**. Live smoke test passed.

**Done (verified):**
- **Virtual foreign-currency accounts** (grey.co-style inbound). `POST /v1/virtual-
  accounts` opens a USD/GBP/EUR account (one per currency per merchant); `GET` list/show.
  Coordinates (account no., ABA routing / UK sort code / IBAN, SWIFT) issued by a
  BaaS provider abstraction.
- **Banking provider layer** (`Providers/Banking/*`): `VirtualAccountProvider` +
  DTOs, `BaasVirtualAccountProvider` (deterministic stub for a real BaaS API),
  `FakeBankingProvider`, `BankingProviderManager` (singleton, `->fake()`).
- **Incoming payments** via signed webhook `POST /webhooks/banking/{account}`
  (HMAC-SHA256 over `"{ts}.{body}"` + replay window). Creates a `bank_deposit`
  transaction and settles it through the shared reconciler/ledger (debit `bank_float`,
  credit merchant net of fee). Idempotent on the partner's `payment_reference`.
- Added `USD`/`GBP` currencies; `TransactionType::BankDeposit`; `bank_float` account;
  `recordBankDepositSettlement`; `virtual_accounts` table + model; scopes
  `virtual_accounts:read/write`.

**Verified how:** +5 tests (open account, re-open returns same, incoming payment
credits net of fee, duplicate webhook no double-credit, bad signature rejected).
Live: opened a USD account (real account #/ABA/SWIFT); signed webhook → `succeeded`,
merchant USD balance = 98500 ($985.00 net of 1.5% fee). Full suite 39 green.

**Next:** merge PR #3; real BaaS partner (account issuance + inbound settlement);
FX pair so a USD/GBP/EUR balance converts to local for MoMo payout (corridor now
complete end-to-end once rates cover these pairs).

**Blocked / not done (by design):** BaaS partner is stubbed (real issuance needs a
licensed bank); no outbound foreign-currency payout; balance guard still check-then-write.

---

## 2026-08-07 — Grey-style FX corridor + security hardening + security review

**State:** On `feat/psp-core` (PR #3 → main). Core + crypto already pushed; this
work committed on top. `php artisan test` → **34 passed (98 assertions)**. Security
review run (subagent) → no ≥8-confidence vulnerabilities; one correctness fix applied.

**Done (verified):**
- **FX conversion (the grey.co corridor).** `POST /v1/fx/quote` + `/v1/fx/conversions`.
  Converts one wallet balance to another (e.g. USDT→GHS) at a quoted rate; spread →
  `fx_revenue`. bcmath on decimal strings (no floats). Posts TWO single-currency
  journals joined by `fx_clearing`. Completes: receive USDT → convert → MoMo payout.
  Rate provider abstraction (`config`/`fake`). `BalanceService` now the shared
  "available balance" (payouts + FX agree).
- **Security hardening:**
  - **Scoped API keys** — `abilities` column + `RequireAbility`; every `/v1` route
    tagged `ability:*`. Restricted keys (read-only, collections-only) enforced.
  - **Rate limiting** — `throttle:api` per key (120/min), `throttle:webhooks` per IP.
  - **Security headers** middleware; **force HTTPS** in prod; `trustProxies`.
  - **Idempotency** now consumes a key only on 2xx — errored attempts release it
    (no permanent 409 after a provider blip); never double-processes.
- **Security review** (subagent, adversarial): confirmed authn/scopes, IDOR→404,
  idempotency scoping, HMAC webhook verification, no SQLi/mass-assignment. Applied
  the one actionable finding (idempotency lock release on error).

**Verified how:** +9 tests (FX quote/convert/insufficient/spread; scopes read-only
vs collections-only vs full; security headers; idempotency-key-released-on-failure).
Full suite 34 green.

**Next:**
1. Merge PR #3.
2. Real rates feed (swap the `config` FX provider); quote TTL enforcement on execute.
3. Per-network currency allow-list (MTN driver currently sends one configured
   currency regardless of txn currency — fine single-corridor, gate before multi).
4. Real chain watcher; per-deposit HD addresses; crypto→fiat as an FX pair.

**Blocked / not done (by design):** payout/FX balance check is check-then-write
(single-node safe; needs row locks/reserved entries for horizontal scale); no
card/bank rails; no merchant UI.

---

## 2026-08-07 — Add crypto deposits (stablecoin on-ramp)

**State:** On `feat/psp-core` (core already committed `9a97e3d`), crypto work not yet
committed. `php artisan test` → **25 passed (69 assertions)** (+5 crypto). Live
smoke test passed end-to-end.

**Done (verified):**
- **New rail: crypto deposits.** USDT/USDC (6-decimal) added as currencies/assets.
  `TransactionType::CryptoDeposit`. Deposits credit the merchant's balance **in the
  asset** — no FX to fiat (deliberate v1 scope).
- **Provider abstraction** (`Providers/Crypto/*`): `CryptoProvider` interface +
  DTOs, `WatcherCryptoProvider` (assigns configured address + memo, TTL),
  `FakeCryptoProvider` (simulates on-chain funds), `CryptoProviderManager`
  (singleton, `->fake()`), `DepositEvaluation` (row → ProviderResult).
- **Ledger:** `crypto_float` asset account; `recordDepositSettlement` shares the
  credit-settlement path with collections (debit float, credit merchant net, fee).
  Merchant now holds multi-currency balances (GHS + USDT).
- **Reconciler:** `apply()` gained a `crypto_deposit` branch; mobile `poll()` skips
  crypto (crypto has `CryptoDepositService::poll` + `crypto:poll-deposits`).
- **HTTP:** `POST/GET /v1/crypto/deposits`; signed inbound webhook
  `POST /webhooks/crypto/{ref}` (HMAC-SHA256 over `"{ts}.{body}"` + 300s replay
  window; payer untrusted, watcher trusted-but-verified).
- **Migrations:** `transactions.msisdn` made nullable; `crypto_deposits` table.

**Verified how:**
- Tests: deposit intent + address, unsupported asset/chain 422, confirmed deposit
  credits net of fee + ledger balanced, watcher webhook settles on valid signature,
  rejects bad signature (no money moved).
- Live: created a 1.00 USDT deposit → address returned; signed watcher webhook →
  `succeeded`; bad signature → 401; merchant USDT balance = `985000` (0.985000),
  ledger debits==credits==1_000_000. (Local `.env` smoke values, not real wallets.)

**Next:**
1. Commit crypto work on `feat/psp-core`; push; open PR to `main`.
2. Build/point a real chain watcher (TRON/EVM node or indexer) at the webhook.
3. Unique per-deposit HD addresses (xpub) instead of shared-address+memo.
4. FX: settle a crypto balance into a fiat balance at a quoted rate; crypto payouts.

**Blocked / not done (by design):** no crypto→fiat FX, no crypto withdrawal, shared
receiving address (memo-disambiguated), watcher itself is off-box.

---

## 2026-08-07 — Repurpose stock Laravel skeleton into a mobile-money PSP core

**State:** On `main`, not yet committed/pushed. `php artisan test` → **20 passed
(49 assertions)**. Live smoke test (php artisan serve) passed for auth, validation,
provider-error handling, and balance.

**What this was:** the repo was a fresh Laravel 12 install (a "PHP hands-on
project") with a stray `route('/contact', …)` typo in `routes/web.php` that broke
`artisan` boot. Rebuilt into the **Node PSP** core per the chosen scope: direct
mobile-money integration, core API + double-entry ledger, MTN MoMo first.

**Done (verified):**
- **Fixed boot:** replaced the `web.php` typo; enabled API routing + JSON error
  envelopes in `bootstrap/app.php`; added `config/psp.php` + PSP env vars.
- **Domain (8 migrations, 8 models, 5 enums):** merchants, api_keys, ledger_accounts,
  ledger_entries, transactions, webhook_deliveries, provider_callbacks,
  idempotency_keys. `App\Support\Money` = integer-minor-unit value object (unit-tested).
- **Double-entry ledger** (`Services/Ledger/*`): balanced-journal posting (rejects
  unbalanced/mixed-currency/non-positive), derived balances, collection & payout
  settlement helpers, idempotent per transaction. Tested.
- **Provider layer** (`Providers/MobileMoney/*`): `MobileMoneyProvider` interface +
  DTOs; real **MTN MoMo** driver (token cache, requesttopay, transfer, status);
  `FakeProvider`; `ProviderManager` (singleton, `->fake()` for tests).
- **Services + HTTP:** Collection/Payout services, `TransactionReconciler` (the only
  place money hits the ledger; idempotent; poll+callback safe), signed
  `WebhookDispatcher`. Middleware: `AuthenticateApiKey` (SHA-256 lookup),
  `EnforceIdempotency`. Controllers for collections, payouts, transactions, balance,
  MTN callback. `/v1` API live.
- **Commands:** `momo:provision-sandbox`, `psp:poll-pending` (scheduled 1/min),
  `webhooks:flush` (scheduled 1/min), `psp:create-merchant`. Seeder → demo merchant.
- **Hardening:** provider failures now return a clean `502 provider_error` JSON
  (was a raw 500). Cross-merchant access returns 404. Strict Eloquent in non-prod.
- **Docs:** authored `CLAUDE.md`, `SKILL.md`, `INSTRUCTIONS.md`, this file.

**Verified how:**
- `php artisan test` green (Money, Ledger, Collection lifecycle incl. fee/failure,
  Payout incl. insufficient-funds + in-flight guard, idempotency replay + reuse).
- Live: `401` unauth, `422` validation envelope, real MTN token call attempted
  (reached MTN, rejected — no sandbox keys), `502` clean provider error, `200` balance.

**Next:**
1. `git add` (NOT `.env`) + commit + push. Repo remote: `onfafanutifafa/TaskList`.
2. Add MTN sandbox keys to `.env`, run `momo:provision-sandbox`, do a real
   sandbox collection with MTN's test MSISDNs and confirm callback + poll settle it.
3. Wire outbound webhooks onto a queue (currently inline in the request/poll path).
4. Move to Postgres for a real deployment; add `refunds/reversals` + per-corridor fees.

**Blocked / not done (by design):**
- Payout concurrency is check-then-write, not row-locked/reserved — safe on a single
  node, needs hardening before horizontal scale.
- No card/bank rails, no merchant dashboard, no real licences/PCI/KYC (business layer).
