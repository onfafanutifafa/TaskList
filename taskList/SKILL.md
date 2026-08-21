# SKILL.md — Node PSP

Capability map for building on this platform: what each module demands, the
patterns to apply, and the traps that will burn you. Use it to decide what to
load/understand before touching a module. Pairs with [CLAUDE.md](CLAUDE.md)
(the rules) and [INSTRUCTIONS.md](INSTRUCTIONS.md) (how to run it).

## 1. Money arithmetic (`app/Support/Money.php`)

- Integer minor units + currency, immutable, pure integer math. `fromMajor()`
  parses a decimal **string** (never a float) into minor units.
- Fees: `feeAtBps()` — basis points, integer round-half-up, floored at 0.
- **Traps:** introducing a float anywhere; mixing currencies in one operation
  (it throws — good); assuming ×100 for every currency (UGX minor factor is 1 —
  read `config('psp.currencies.minor_units')`).

## 2. Double-entry ledger (`app/Services/Ledger/*`)

- `post(JournalLeg[])` writes one balanced journal in a DB transaction; it
  refuses unbalanced sets, non-positive legs, and mixed currencies.
- Balances are **derived** from entries (`accountBalance`), never stored on a
  column — the entries are the truth.
- `AccountResolver` lazily creates system accounts (`momo_float`, `fee_revenue`,
  `provider_expense`) and per-merchant `merchant_payable` accounts.
- **Traps:** storing a running balance and trusting it; posting settlement twice
  (guarded by `alreadyPosted()` — keep it); editing/deleting entries instead of
  writing a reversing journal.

## 3. Transaction lifecycle (`app/Services/Transactions/*`)

- States: `pending → processing → (succeeded | failed)`. `pending`/`processing`
  are open; the other two terminal (`TransactionStatus::isTerminal()`).
- `CollectionService` / `PayoutService` create the record then call the provider;
  `TransactionReconciler::apply()` is the **only** place terminal transitions +
  ledger settlement + webhooks happen, and it is idempotent.
- Payout overspend guard: `availableBalance = settled − in-flight payouts`.
- **Traps:** settling in the controller or the service instead of the reconciler;
  forgetting a payout in-flight can't be double-spent; assuming a `202` from the
  provider means success — it means *accepted*, status is resolved by polling.

## 4. Provider abstraction (`app/Providers/MobileMoney/*`)

- Everything above depends only on `MobileMoneyProvider` (4 methods) + the DTOs.
- `MtnMomoProvider`: bearer-token cache per product, `requesttopay` / `transfer`
  initiation returns 202, status via GET, normalised to `ProviderStatus`.
- `ProviderManager` resolves a driver from `config/psp.php`; `->fake()` swaps in
  `FakeProvider` everywhere for tests.
- **Add a rail (M-Pesa/Airtel):** implement the interface under a new
  `MobileMoney/<Name>/`, add it to `psp.providers` + `psp.networks`. Nothing else
  should change. **Trap:** leaking a provider's field names above the interface.

## 5. API surface (`routes/api.php`, `Http/*`)

- `Authorization: Bearer sk_(test|live)_...`; key looked up by SHA-256 hash, never
  stored in plaintext (`AuthenticateApiKey`).
- `Idempotency-Key` on money POSTs: first call runs + stores the response; retries
  replay it; same key + different body → 422 (`EnforceIdempotency`).
- Amount in/out is minor units. Errors are `{error:{type,message}}` envelopes.
- **Traps:** returning HTML errors on the API (the exception handler prevents it —
  keep `/v1/*` in the JSON matcher); 403 instead of 404 on cross-merchant reads.

## 6. Webhooks (`app/Services/Webhooks/WebhookDispatcher.php`)

- Outbound to merchants, signed `X-Node-Signature: t=<ts>,v1=<hmac-sha256>`
  over `"{ts}.{body}"` with the merchant's `webhook_secret`. Exponential
  back-off; `webhooks:flush` retries the due ones.
- Inbound provider callbacks are **untrusted**: record + trigger a status poll,
  never settle from the body (`MtnMomoCallbackController`).
- **Trap:** telling merchants to compare signatures without constant-time compare
  on their side; signing over a re-encoded body (sign the exact bytes sent).

## 7. Crypto deposits (`app/Providers/Crypto/*`, `Services/Transactions/CryptoDepositService.php`)

- On-ramp: `POST /v1/crypto/deposits` reserves an address + creates a
  `crypto_deposit` transaction (status `processing`, awaiting funds). Amount is in
  the asset's minor units (USDT/USDC → 6 decimals).
- A chain **watcher** (node/indexer, off-box) reports the on-chain payment to the
  signed webhook `POST /webhooks/crypto/{reference}`; `CryptoWatcherCallbackController`
  verifies HMAC + replay window, then `applyWatcherUpdate` settles if confirmed.
  `crypto:poll-deposits` is the safety net.
- Settlement reuses the reconciler + ledger: debit `crypto_float`, credit merchant
  net of fee. Balance shows the asset alongside fiat (multi-currency `merchant_payable`).
- **Add an asset/chain:** extend `config('psp.crypto.assets')` (address +
  confirmations). **Add a real provider:** implement `CryptoProvider`, register it in
  `CryptoProviderManager`, have `poll()` query a chain explorer.
- **Traps:** settling from the payer's word (only the signed watcher/poll settles);
  treating USDT as 2-decimal (it's 6); no FX to fiat yet — the merchant holds a USDT
  balance, not GHS. Payouts are mobile-money only; there is no crypto withdrawal.

## 7b. Virtual accounts (`app/Providers/Banking/*`, `Services/Transactions/VirtualAccountService.php`)

- `POST /v1/virtual-accounts` opens a virtual USD/GBP/EUR receiving account (one per
  currency per merchant; re-opening returns the same one). `GET` to list/show.
  Coordinates (account no., routing/sort/IBAN, SWIFT) come from the BaaS provider.
- Incoming payments arrive via the signed **banking webhook**
  `POST /webhooks/banking/{account}` (HMAC-SHA256 + replay window); they create a
  `bank_deposit` transaction and settle immediately (debit `bank_float`, credit
  merchant net of fee). Idempotent on the partner's `payment_reference`.
- **Outbound:** `POST /v1/bank-payouts` wires USD/GBP/EUR out to an external
  beneficiary (stored in tx `meta.beneficiary`). Mirrors mobile payout — settles on
  confirmation (poll via `bank:poll-payouts`), and `BalanceService::available`
  subtracts in-flight payouts of BOTH rails so a merchant can't overspend.
- **Add a currency/rail:** extend `config('psp.banking.currencies'|'rails')` and the
  BaaS driver's `match`. **Real BaaS:** implement `BankingProvider` (issuance +
  payout) and register it in `BankingProviderManager`.
- **Traps:** trusting the payer instead of the signed partner; double-crediting a
  re-delivered webhook (guard on `payment_reference`); currency mismatch between the
  payload and the account.

## 8. FX conversion (`app/Services/Fx/*`)

- `POST /v1/fx/quote` (read-only) returns rate + spread + net; `POST /v1/fx/conversions`
  executes, moving one wallet balance to another. Amounts in the source asset's
  minor units.
- Rates via `RateProviderManager` (`config` provider; `->fake()` for tests). All
  math is **bcmath on decimal strings** — no floats. Spread (`psp.fx.spread_bps`)
  is booked to `fx_revenue`.
- A conversion posts **two** single-currency journals meeting at `fx_clearing`
  (a journal can't span currencies). Balance check reuses `BalanceService::available`.
- **Traps:** trying to post one cross-currency journal (LedgerService rejects it);
  float math on rates; forgetting differing minor-unit factors (USDT 6dp ↔ GHS 2dp);
  converting more than the available (not just settled) balance.

## 9. API security (`app/Http/Middleware/*`, `AppServiceProvider`)

- **Scoped keys:** `RequireAbility` enforces a per-route `ability:*`; keys minted
  with a restricted `abilities` array can't exceed their scope. NULL abilities =
  full access. Issue restricted keys via `ApiKey::issue($m, $mode, $name, ['collections:write'])`.
- **Rate limits:** `throttle:api` per API key (fallback IP), `throttle:webhooks`
  per IP. Defined in `AppServiceProvider::boot`.
- **Idempotency consumes only on success (2xx):** an errored attempt releases the
  key so retries work and never double-process.
- **Headers:** `SecurityHeaders` sets nosniff/DENY/Referrer-Policy/HSTS(TLS).
  Prod forces HTTPS; `trustProxies` reads the real client behind the LB.
- **Traps:** adding a `/v1` route without an `ability:*`; putting `throttle:api`
  before `api.key` (the limiter needs the resolved key); returning 403 instead of
  404 on cross-merchant reads.

## 9b. Balance reservations / concurrency (`app/Services/Transactions/BalanceService.php`)

- Every debit (payout, bank payout, FX-out) holds funds via `BalanceService::reserve()`
  **inside a DB transaction**: it `lockForUpdate`s the `(merchant, currency)` row in
  `balance_reservations`, re-checks `available = settled − reserved` under the lock,
  and raises the hold — so N concurrent spenders serialise and can't oversell.
- `available()` = settled − reserved; `TransactionReconciler` calls `release()` on any
  debit reaching a terminal state (settled → funds already moved; failed → returned).
- **Traps:** calling `reserve()/release()` outside a transaction (the FOR UPDATE lock
  only holds for the enclosing txn); reserving in one currency and releasing another;
  testing the race on SQLite (it serialises writes — prove it on Postgres). Verified
  live on Postgres: 10 parallel conversions over a 3-fit balance → exactly 3 settle.

## 9c. Queues (Horizon) (`app/Jobs/*`, `config/horizon.php`)

- Two Redis queues: **settlement** (`ReconcileTransaction` — polls the provider and
  settles) and **webhooks** (`DeliverWebhook` — signs + POSTs to the merchant).
  Horizon supervisor processes them in priority order settlement → webhooks → default.
- Enqueued by: inbound MTN callback (fast 200, worker polls), the `psp:poll-pending`
  / `crypto:poll-deposits` / `bank:poll-payouts` schedulers, `webhooks:flush`, and
  `WebhookDispatcher::dispatch`. `redis.after_commit = true` defers dispatch to
  post-commit.
- Run workers with `php artisan horizon`; dashboard at `/horizon` (gated by
  `HORIZON_DASHBOARD_EMAILS` outside local). Tests use the `sync` queue, so jobs run
  inline — assert with `Queue::fake()`.
- **Traps:** doing a blocking provider call in the request instead of enqueuing;
  a non-idempotent job (settlement must no-op on terminal); forgetting `after_commit`
  and racing the DB write; not scoping `WithoutOverlapping` per transaction id.

## 9d. Fraud screening (`app/Services/Fraud/*`)

- `MasenuClient::assertAllowed($msisdn, $context)` runs before a collection/payout
  is created; a block throws `FraudBlockedException` → `422 fraud_blocked`, and
  nothing is reserved or written. `assess()` returns the `RiskDecision` if you want
  the verdict without throwing.
- Calls Masenu's `/v1/lookups` with the entity **edge-hashed** (`hash_hmac(pepper)`)
  + `pepper_v` — raw MSISDNs never leave the box. Maps `recommended_action`/`risk_score`
  to allow/review/block (`block_on`, `block_threshold`). Fails open/closed per `fail_open`.
- Config `psp.fraud` ← `MASENU_*` env. **Disabled by default** (`MASENU_ENABLED=false`);
  run Masenu Pro locally (`MASENU_BASE_URL`) to test for real, or
  `app(MasenuClient::class)->force(RiskDecision::block(...))` in tests/demos.
- **Traps:** sending a raw phone number; screening after creating the transaction
  (screen first); failing closed by accident (default is open) — decide per corridor.

## 9e. Deployment (`Dockerfile`, `render.yaml`, `DEPLOY.md`)

- One image (FrankenPHP + PHP 8.4), two roles via the entrypoint: web (default) and
  `horizon`. Build context is `taskList/`. See [DEPLOY.md](DEPLOY.md).
- Local dry-run of the exact Render image: `docker compose --profile app up --build`
  (Postgres + Redis + app on :8088 + worker). `render.yaml` deploys web + worker +
  managed Postgres/Redis; `DB_URL`/`REDIS_URL` injected; migrate via preDeploy.
- **Traps:** baking `.env` or dev-time `bootstrap/cache/*.php` into the image (both
  are in `.dockerignore` — the cached manifest would drag in dev-only providers like
  Pail under `--no-dev`); forgetting the worker + a `schedule:run` cron in prod.

## 10. Reconciliation & ops (`app/Console/Commands/*`)

- `psp:poll-pending` (scheduled every minute) settles mobile-money transactions
  whose callback was missed; `crypto:poll-deposits` does the same for crypto.
- `webhooks:flush` retries failed deliveries.
- `momo:provision-sandbox` mints MTN sandbox API user/key; `psp:create-merchant`
  onboards a merchant and prints a key once.
- **Trap:** relying on callbacks alone — always keep the poller running.

## What's deliberately NOT built yet

Multi-node payout concurrency uses a check-then-write guard, not row locks/reserved
ledger entries; refunds/reversals; per-corridor pricing; card & bank rails; a
merchant dashboard; queue-backed webhook sending. **Crypto specifically:** no
FX/settlement of a crypto balance into fiat, no crypto withdrawal, and address
assignment is one-address-per-asset+memo (not a unique per-deposit HD address).
See [HANDOFF.md](HANDOFF.md).
