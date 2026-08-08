# CLAUDE.md — Node PSP

Instructions for any AI agent (Claude Code, Cursor, Copilot, …) working in this
repository. Read this before making changes.

## What this project is

**Node** is a mobile-money **payment service provider (PSP)** for Africa: an
API-first platform that lets merchants **collect** (pull) and **pay out** (push)
money over mobile-money rails, plus **receive stablecoin deposits** — all with a
**double-entry ledger** as the source of truth for every unit that moves.

- **Stack:** PHP 8.4, **Laravel 12**, SQLite in dev/CI (Postgres in prod).
- **Mobile-money rail:** **MTN MoMo** (Collections + Disbursements), wired end-to-end
  against the sandbox. Others (M-Pesa, Airtel) slot in behind the same interface.
- **Crypto rail:** stablecoin deposits (USDT/USDC on TRON/EVM), confirmed by an
  off-box chain watcher via a signed webhook. Credits the merchant's balance **in
  the asset**.
- **Virtual accounts:** merchants get virtual USD/GBP/EUR receiving accounts (issued
  by a banking-as-a-service partner); incoming international payments credit the
  balance via a signed BaaS webhook.
- **Outbound bank payout:** merchants wire USD/GBP/EUR out of their balance to an
  external bank beneficiary via the same BaaS partner (settles on confirmation).
- **FX:** merchants convert one wallet balance into another (e.g. USD → GHS) at a
  quoted rate with a spread. Together these are the grey.co-style flow: receive
  foreign currency (bank or stablecoin) → convert to local → pay out to MTN MoMo.
- **Surface:** JSON API under `routes/api.php` (`/v1/*`), scoped API keys,
  per-key rate limiting. No merchant UI yet.

> Node is the software layer. Moving **real** money additionally requires a
> payment/EMI licence per country, PCI/KYC/AML, and live provider contracts —
> business steps outside this repo. Build and test against provider **sandboxes**.

## Hard rules (do not violate)

1. **Never commit `.env`.** `.env.example` only. Enforced globally by
   `~/.claude/hooks/block-env-leaks.sh`. MoMo subscription/API keys are secrets.

2. **Money is an integer in MINOR units** (GHS pesewas, KES cents), always with
   a currency. Use `App\Support\Money` — never float, never `Decimal`, never
   float math in the request path. The API accepts and returns `amount` in minor
   units (`1050` = GHS 10.50).

3. **The ledger is append-only and must always balance.** All money movement
   goes through `LedgerService::post()`, which rejects any journal whose debits
   ≠ credits. Never `UPDATE`/`DELETE` a `ledger_entries` row — correct with a new
   balancing journal.

4. **Money hits the ledger in exactly one place:** `TransactionReconciler::apply()`,
   on the transition to a terminal state. It is idempotent per transaction
   (guarded by `LedgerService::alreadyPosted()`), so callback + poll racing is safe.

5. **Provider callbacks never move money on their own.** An inbound MTN callback
   only triggers an authoritative **GET status** poll. A spoofed callback must be
   harmless. See `MtnMomoCallbackController`.

6. **The transaction id IS the provider `X-Reference-Id`.** Re-sending the same
   request to MTN is naturally idempotent. Merchant-facing idempotency is the
   `Idempotency-Key` header (`EnforceIdempotency` middleware).

7. **Everything below the provider interface is provider-agnostic.** Only
   `app/Providers/MobileMoney/<Provider>/` may know a provider's wire format.
   Add a rail by implementing `MobileMoneyProvider` and registering it in
   `config/psp.php` — touch nothing else.

8. **Merchant scoping is mandatory.** Every `/v1` read/write is scoped to the
   authenticated merchant. Cross-merchant access returns **404**, never 403
   (don't leak existence).

10. **Least privilege by scope + always confirm the money can't double-move.**
    Every `/v1` route declares an `ability:*` scope (`RequireAbility`); keys can be
    minted restricted (e.g. read-only, collections-only). Only a *successful* (2xx)
    response consumes an `Idempotency-Key` — an errored attempt releases it so a
    retry works and never double-processes. FX conversions post **two** balanced
    single-currency journals joined by `fx_clearing` (a journal can't span currencies).

9. **Inbound-partner webhooks are trusted-but-verified; the payer never is.** A
   crypto deposit (chain watcher) and a bank deposit (BaaS partner) only settle
   after an HMAC-SHA256 check over `"{ts}.{body}"` with a replay window — and are
   idempotent on the provider's payment reference so a re-delivery never double-
   credits. All pay-ins settle through the SAME reconciler + ledger (`crypto_deposit`
   from `crypto_float`, `bank_deposit` from `bank_float`, both crediting the merchant
   net of fee — like a collection). Money is integer minor units; USDT/USDC carry
   **6 decimals** (1_000_000 = 1.00) — read `config('psp.currencies.minor_units')`.

## Architecture (where things live)

```
app/
  Support/Money.php               integer-minor-unit money value object
  Support/TransactionPayload.php  one serialiser for API + webhooks
  Enums/                          TransactionType/Status, LedgerDirection, AccountType, ApiKeyMode
  Services/Ledger/                LedgerService, AccountResolver, JournalLeg   (double-entry)
  Services/Transactions/          Collection/Payout/CryptoDeposit services, BalanceService, TransactionReconciler
  Services/Fx/                    FxService (quote + convert), rate providers (config/fake) + manager
  Services/Webhooks/              WebhookDispatcher (signed, retrying)
  Providers/MobileMoney/
    Contracts/                    MobileMoneyProvider + DTOs (MoneyRequest, ProviderResult, ProviderStatus)
    Mtn/MtnMomoProvider.php       real MTN MoMo driver (token cache, requesttopay, transfer, status)
    Fake/FakeProvider.php         in-memory driver for tests/local
    ProviderManager.php           resolves a driver from config (singleton)
  Providers/Crypto/
    Contracts/                    CryptoProvider + DTOs (CryptoDepositRequest, CryptoAddress)
    Watcher/WatcherCryptoProvider.php  assigns addresses; relies on the watcher webhook
    Fake/FakeCryptoProvider.php   simulates on-chain funds for tests
    DepositEvaluation.php         deposit row -> ProviderResult (one "is it done?" truth)
    CryptoProviderManager.php     resolves the crypto driver (singleton)
  Providers/Banking/
    Contracts/                    VirtualAccountProvider + DTOs
    Baas/BaasVirtualAccountProvider.php  issues virtual USD/GBP/EUR account coordinates
    Fake/FakeBankingProvider.php  test double
    BankingProviderManager.php    resolves the banking driver (singleton)
  Http/Middleware/                AuthenticateApiKey, EnforceIdempotency, RequireAbility, SecurityHeaders
  Http/Controllers/Api/V1/        Collection, Payout, CryptoDeposit, VirtualAccount, BankPayout, Fx, Transaction, Balance
  Http/Controllers/Webhooks/      MtnMomo, CryptoWatcher, Banking callback controllers
config/psp.php                    currencies, fees, providers, networks, crypto, webhooks
routes/api.php                    the /v1 surface
```

## The ledger model (memorise this)

Accounts have a type with a normal balance:
`asset`/`expense` → debit-normal; `liability`/`revenue` → credit-normal.

- **Merchant balance** = a **liability** (`merchant_payable`) — money we owe them
  (one account per currency/asset, so a merchant can hold GHS *and* USDT).
- **MoMo float** = an **asset** (`momo_float`); **crypto float** = an **asset**
  (`crypto_float`) — stablecoins in our on-chain wallets.
- **Fees** = **revenue** (`fee_revenue`).

Settlement journals (must balance):

- **Collection success:** debit `momo_float` (gross) · credit `merchant_payable`
  (net) · credit `fee_revenue` (fee).
- **Crypto deposit confirmed:** debit `crypto_float` (gross) · credit
  `merchant_payable` (net) · credit `fee_revenue` (fee). Same shape as a collection.
- **Bank deposit (virtual account):** debit `bank_float` (gross) · credit
  `merchant_payable` (net) · credit `fee_revenue` (fee). Same shape, different float.
- **Bank payout (outbound):** debit `merchant_payable` (amount+fee) · credit
  `bank_float` (amount) · credit `fee_revenue` (fee). Settles on confirmation.
- **Spending is guarded by row-locked reservations, not a check-then-write.** Every
  debit (payout / bank payout / FX-out) calls `BalanceService::reserve()` inside a DB
  transaction, which `SELECT ... FOR UPDATE`s the `(merchant, currency)` reservation
  row, re-checks `available = settled − reserved` under the lock, and raises the hold
  — so concurrent spenders serialise and cannot oversell. The hold is released when
  the debit settles (funds moved) or fails (funds returned). **Prod runs Postgres**
  so the lock is real; SQLite (tests) serialises writes anyway.
- **Payout success:** debit `merchant_payable` (amount+fee) · credit `momo_float`
  (amount) · credit `fee_revenue` (fee).
- **FX conversion (two journals):** *source* — debit `merchant_payable(from)` ·
  credit `fx_clearing(from)`; *dest* — debit `fx_clearing(to)` (gross) · credit
  `merchant_payable(to)` (net) · credit `fx_revenue(to)` (spread). Each journal
  balances within its own currency; `fx_clearing` carries the platform FX position.

Payouts guard against overspend using **settled balance − in-flight payouts**
(`PayoutService::availableBalance`).

## Conventions

- Enums (backed) for every status/type; cast on the model.
- Services hold business logic; controllers stay thin; validation in FormRequests.
- API errors are JSON envelopes: `{ "error": { "type", "message", ... } }`
  (shaped in `bootstrap/app.php`).
- Tests: PHPUnit 11, `RefreshDatabase`, `ProviderManager::fake()` — never hit a
  real provider in a test.

## Definition of done for any money-path change

- [ ] New/changed movement goes through `LedgerService` and stays balanced.
- [ ] Idempotent on retry and on callback/poll races.
- [ ] Feature test with `FakeProvider` covers success **and** failure.
- [ ] `php artisan test` green; no `Money` float leaks; no cross-merchant access.
