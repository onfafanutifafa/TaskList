# HANDOFF.md — Node PSP session log

Read this first in every session; update it before ending one. Newest entry on
top. Keep entries short: what changed, what's verified, what's next, what's blocked.

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
