# CLAUDE.md — Node PSP

Instructions for any AI agent (Claude Code, Cursor, Copilot, …) working in this
repository. Read this before making changes.

## What this project is

**Node** is a mobile-money **payment service provider (PSP)** for Africa: an
API-first platform that lets merchants **collect** (pull) and **pay out** (push)
money over mobile-money rails, with a **double-entry ledger** as the source of
truth for every cedi/shilling that moves.

- **Stack:** PHP 8.4, **Laravel 12**, SQLite in dev/CI (Postgres in prod).
- **First rail:** **MTN MoMo** (Collections + Disbursements), wired end-to-end
  against the sandbox. Others (M-Pesa, Airtel) slot in behind the same interface.
- **Surface:** JSON API under `routes/api.php` (`/v1/*`). No merchant UI yet.

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

## Architecture (where things live)

```
app/
  Support/Money.php               integer-minor-unit money value object
  Support/TransactionPayload.php  one serialiser for API + webhooks
  Enums/                          TransactionType/Status, LedgerDirection, AccountType, ApiKeyMode
  Services/Ledger/                LedgerService, AccountResolver, JournalLeg   (double-entry)
  Services/Transactions/          CollectionService, PayoutService, TransactionReconciler
  Services/Webhooks/              WebhookDispatcher (signed, retrying)
  Providers/MobileMoney/
    Contracts/                    MobileMoneyProvider + DTOs (MoneyRequest, ProviderResult, ProviderStatus)
    Mtn/MtnMomoProvider.php       real MTN MoMo driver (token cache, requesttopay, transfer, status)
    Fake/FakeProvider.php         in-memory driver for tests/local
    ProviderManager.php           resolves a driver from config (singleton)
  Http/Middleware/                AuthenticateApiKey, EnforceIdempotency
  Http/Controllers/Api/V1/        Collection, Payout, Transaction, Balance
  Http/Controllers/Webhooks/      MtnMomoCallbackController
config/psp.php                    currencies, fees, providers, networks, webhooks
routes/api.php                    the /v1 surface
```

## The ledger model (memorise this)

Accounts have a type with a normal balance:
`asset`/`expense` → debit-normal; `liability`/`revenue` → credit-normal.

- **Merchant balance** = a **liability** (`merchant_payable`) — money we owe them.
- **MoMo float** = an **asset** (`momo_float`) — cash in the provider account.
- **Fees** = **revenue** (`fee_revenue`).

Settlement journals (must balance):

- **Collection success:** debit `momo_float` (gross) · credit `merchant_payable`
  (net) · credit `fee_revenue` (fee).
- **Payout success:** debit `merchant_payable` (amount+fee) · credit `momo_float`
  (amount) · credit `fee_revenue` (fee).

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
