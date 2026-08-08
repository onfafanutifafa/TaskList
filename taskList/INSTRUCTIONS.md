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

## Crypto deposits (stablecoin on-ramp)

Configure a receiving address + watcher secret in `.env`:

```
PSP_CRYPTO_WATCHER_SECRET=some-long-secret
CRYPTO_USDT_TRON_ADDRESS=TYourTronReceivingAddress
```

Create a deposit intent (amount in the asset's minor units — USDT has 6 decimals,
so `1000000` = 1.00 USDT):

```bash
curl -s http://127.0.0.1:8000/v1/crypto/deposits \
  -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" \
  -H "Idempotency-Key: $(uuidgen)" \
  -d '{"amount":1000000,"asset":"USDT","chain":"tron","reference":"dep-1"}'
# -> 201 with data.crypto.address (where the payer sends funds) + memo + expiry
```

The **chain watcher** (a node/indexer you run) confirms the on-chain payment and
POSTs to `/webhooks/crypto/{transaction_id}`, signing `"{ts}.{body}"` with
`PSP_CRYPTO_WATCHER_SECRET`:

```bash
TXID=<the deposit id>;  SECRET=some-long-secret
BODY='{"amount_received_minor":1000000,"confirmations":25,"tx_hash":"0xabc"}'
TS=$(date +%s)
SIG=$(printf '%s' "${TS}.${BODY}" | openssl dgst -sha256 -hmac "$SECRET" | sed 's/^.*= //')
curl -s -X POST http://127.0.0.1:8000/webhooks/crypto/$TXID \
  -H "Content-Type: application/json" -H "X-Watcher-Signature: t=${TS},v1=${SIG}" -d "$BODY"
# -> {"matched":true,"status":"succeeded"}; the merchant's USDT balance is credited net of fee
```

If a webhook is missed, `php artisan crypto:poll-deposits` reconciles it.

> The watcher is out of scope here (it needs a chain node/indexer). Node integrates
> it through this one signed webhook + the poll safety net. No FX to fiat in v1 —
> the merchant holds a USDT balance, visible via `GET /v1/balance`.

## Virtual USD/GBP/EUR receiving accounts

Open a virtual foreign-currency account; the response holds the coordinates a
sender abroad would pay into:

```bash
curl -s http://127.0.0.1:8000/v1/virtual-accounts \
  -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" \
  -d '{"currency":"USD"}'
# -> 201 { account_number, routing_number (USD) / sort_code (GBP) / iban (EUR), swift_bic, bank_name }
```

Incoming international payments are reported by the banking partner to
`/webhooks/banking/{account_id}`, signed with `PSP_BANKING_WEBHOOK_SECRET`:

```bash
ACCT=<account id>;  SECRET=<PSP_BANKING_WEBHOOK_SECRET>
BODY='{"amount_minor":100000,"currency":"USD","payment_reference":"wire-1","sender_name":"Acme"}'
TS=$(date +%s)
SIG=$(printf '%s' "${TS}.${BODY}" | openssl dgst -sha256 -hmac "$SECRET" | sed 's/^.*= //')
curl -s -X POST http://127.0.0.1:8000/webhooks/banking/$ACCT \
  -H "Content-Type: application/json" -H "X-Banking-Signature: t=${TS},v1=${SIG}" -d "$BODY"
# -> {"matched":true,"status":"succeeded"}; merchant USD balance credited net of fee
```

> The BaaS partner is out of scope here (real account issuance + inbound settlement
> need a licensed bank/BaaS). Node integrates it via account issuance + this one
> signed webhook, idempotent on `payment_reference`.

## Outbound foreign-currency payout

Wire USD/GBP/EUR out of a merchant's balance to an external bank beneficiary
(drawn from that currency wallet; settles once the partner confirms):

```bash
curl -s http://127.0.0.1:8000/v1/bank-payouts \
  -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" \
  -H "Idempotency-Key: $(uuidgen)" \
  -d '{"amount":50000,"currency":"USD","reference":"out-1",
       "beneficiary":{"account_name":"Jane Supplier","account_number":"12345678",
                      "bank_name":"Chase","routing_number":"021000021"}}'
# -> 201 { type: bank_payout, status: processing }; `bank:poll-payouts` settles it
```

This closes the round trip: **receive (virtual account / crypto) → FX → pay out
(MoMo locally, or bank wire in foreign currency).**

## FX conversion (grey.co-style corridor)

Convert one wallet balance into another. Quote first (no side effects), then execute:

```bash
# Quote 10 USDT -> GHS (amount in source minor units: 10 USDT = 10_000_000)
curl -s http://127.0.0.1:8000/v1/fx/quote \
  -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" \
  -d '{"amount":10000000,"from":"USDT","to":"GHS"}'

# Execute it
curl -s http://127.0.0.1:8000/v1/fx/conversions \
  -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" \
  -H "Idempotency-Key: $(uuidgen)" \
  -d '{"amount":10000000,"from":"USDT","to":"GHS","reference":"conv-1"}'
```

Full corridor: **crypto deposit (USDT) → `fx/conversions` (USDT→GHS) → `payouts` (MTN MoMo)**.
Rates live in `config/psp.php` (`psp.fx.rates`); `PSP_FX_SPREAD_BPS` is the markup.

## API-key scopes (least privilege)

Every `/v1` route requires a scope. A key with no scopes set has full access; a
restricted key is limited. Scopes: `collections:write/read`, `payouts:write/read`,
`crypto:write/read`, `virtual_accounts:write/read`, `bank_payouts:write/read`,
`fx:write/read`, `transactions:read`, `balances:read`.

```php
// tinker: issue a read-only key
[$k, $secret] = App\Models\ApiKey::issue($merchant, App\Enums\ApiKeyMode::Test, 'reporting', ['balances:read','transactions:read']);
```

Requests over the scope get `403`; per-key rate limit is 120 req/min.

## Running on Postgres (production parity)

SQLite is fine for dev/tests, but the balance reservations use row locking
(`SELECT ... FOR UPDATE`), which only does its job on Postgres. To run against it:

```bash
docker compose up -d          # starts postgres:16 on :5432 (see docker-compose.yml)
```

Then in `.env` switch the DB block to:

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=node_psp
DB_USERNAME=node
DB_PASSWORD=secret
```

```bash
php artisan migrate
```

Concurrency is real here: N simultaneous payouts/conversions on the same balance
serialise on the reservation row, and only those the balance can cover succeed —
the rest get `422`, and the balance never goes negative.

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
