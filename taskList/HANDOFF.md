# HANDOFF.md — Node PSP session log

Read this first in every session; update it before ending one. Newest entry on
top. Keep entries short: what changed, what's verified, what's next, what's blocked.

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
