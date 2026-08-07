# INSTRUCTIONS.md — developer setup

For humans (and agents) getting Node PSP running locally.

## Prerequisites

- **PHP 8.4** with `pdo_sqlite`, `bcmath`, `curl` (all standard).
- **Composer 2.x**.
- An **MTN MoMo developer account** (free) for real sandbox calls:
  https://momodeveloper.mtn.com — optional; tests use a fake provider.

## First-time setup

```bash
cd taskList
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan db:seed          # creates a demo merchant + prints a test API key ONCE
```

Copy the `sk_test_...` key the seeder prints — you'll need it below.

Run it:

```bash
php artisan serve            # http://127.0.0.1:8000
```

## Wiring MTN MoMo sandbox (optional, for real calls)

1. Sign in at https://momodeveloper.mtn.com, subscribe to **Collection** and
   **Disbursement**, and copy each product's **Primary Key**.
2. Put them in `.env`:
   ```
   MTN_MOMO_COLLECTION_SUBSCRIPTION_KEY=...
   MTN_MOMO_DISBURSEMENT_SUBSCRIPTION_KEY=...
   ```
3. Mint the sandbox API user + key for each product and paste the output back
   into `.env`:
   ```bash
   php artisan momo:provision-sandbox
   ```
4. Sandbox settles in **EUR** and only accepts MTN's test MSISDNs — see MTN's
   docs. `MTN_MOMO_ENVIRONMENT=sandbox` is already set.

Without this, use the fake provider (default in tests) — the full lifecycle still
works end-to-end, it just doesn't call MTN.

## Try the API (curl)

```bash
KEY=sk_test_xxx      # the seeded/created key

# 1. Start a collection (amount is MINOR units: 1050 = GHS 10.50)
curl -s http://127.0.0.1:8000/v1/collections \
  -H "Authorization: Bearer $KEY" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: $(uuidgen)" \
  -d '{"amount":1050,"currency":"GHS","phone":"233240000000","network":"mtn","reference":"order-1001","narration":"Order 1001"}'

# 2. Poll/settle open transactions (also runs every minute on the scheduler)
php artisan psp:poll-pending

# 3. Check the merchant's balance (settled + available)
curl -s http://127.0.0.1:8000/v1/balance -H "Authorization: Bearer $KEY"

# 4. Pay out from that balance
curl -s http://127.0.0.1:8000/v1/payouts \
  -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" \
  -H "Idempotency-Key: $(uuidgen)" \
  -d '{"amount":500,"currency":"GHS","phone":"233240000000","network":"mtn","reference":"payout-1"}'
```

> With the fake provider, step 2 settles the collection to `succeeded`; with MTN
> sandbox, the payer must approve the prompt first (or use MTN's test states).

## Onboard another merchant

```bash
php artisan psp:create-merchant "Acme Ltd" ops@acme.test --currency=GHS --webhook=https://acme.test/hooks
```

## Testing

```bash
php artisan test           # PHPUnit 11, in-memory sqlite, fake provider
```

## Scheduler & background work (prod)

Run Laravel's scheduler so reconciliation + webhook retries fire:

```bash
* * * * * cd /path/to/taskList && php artisan schedule:run >> /dev/null 2>&1
```

## Environment cheatsheet

| Var | Meaning |
|---|---|
| `PSP_FEE_BPS` | platform fee on collections, basis points (150 = 1.5%) |
| `PSP_DEFAULT_CURRENCY` | default currency (GHS) |
| `MTN_MOMO_ENVIRONMENT` | `sandbox` or `production` (`X-Target-Environment`) |
| `MTN_MOMO_CURRENCY` | currency sent to MTN (`EUR` in sandbox) |
| `MTN_MOMO_CALLBACK_URL` | base URL MTN calls back; leave blank to poll only |

Never commit `.env`. See [CLAUDE.md](CLAUDE.md) for the rules that keep the ledger
correct.
