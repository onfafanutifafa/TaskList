# Node — mobile-money PSP for Africa

**Node** is an API-first **payment service provider** core: merchants **collect**
(pull) and **pay out** (push) money over mobile-money rails, plus **receive
stablecoin deposits**, backed by a **double-entry ledger** that is the source of
truth for every unit of money moved.

- **Stack:** PHP 8.4 · Laravel 12 · SQLite (dev) / Postgres (prod)
- **Mobile money:** MTN MoMo (Collections + Disbursements), sandbox-wired, behind a
  provider interface so M-Pesa / Airtel slot in later.
- **Crypto:** USDT/USDC deposits on TRON/EVM, confirmed by a chain watcher via a
  signed webhook; credits the merchant's balance in the asset.
- **Virtual accounts:** virtual USD/GBP/EUR receiving accounts (BaaS-issued);
  incoming payments credited via a signed webhook.
- **FX:** convert between wallet balances (e.g. USD → GHS) at a quoted rate — so
  the full corridor is *receive foreign currency → convert to GHS → pay out to MoMo*.
- **Security:** scoped API keys, per-key rate limiting, signed webhooks, security
  headers, HTTPS in prod.

> Node is the software layer. Moving **real** money also needs per-country
> licensing, PCI/KYC/AML, and live provider contracts — build/test on sandboxes.

## Quick start

```bash
cd taskList
composer install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite && php artisan migrate --seed   # prints a test API key
php artisan serve
php artisan test
```

Then follow the curl walkthrough in **[INSTRUCTIONS.md](INSTRUCTIONS.md)**.

## Docs

| File | What |
|---|---|
| [CLAUDE.md](CLAUDE.md) | Architecture + the hard rules that keep the ledger correct |
| [SKILL.md](SKILL.md) | Module-by-module capability map and traps |
| [INSTRUCTIONS.md](INSTRUCTIONS.md) | Local setup, MTN sandbox wiring, API walkthrough |
| [HANDOFF.md](HANDOFF.md) | Session log — read before working, update before ending |

## API surface (v1)

`Authorization: Bearer sk_test_…` · amounts in **minor units** (1050 = GHS 10.50)

| Method | Path | |
|---|---|---|
| POST | `/v1/collections` | initiate a mobile-money pull |
| POST | `/v1/payouts` | initiate a disbursement (balance-checked) |
| POST | `/v1/crypto/deposits` | create a stablecoin deposit intent (returns an address) |
| GET | `/v1/crypto/deposits/{id}` | fetch a deposit + on-chain status |
| POST | `/v1/virtual-accounts` | open a virtual USD/GBP/EUR receiving account |
| GET | `/v1/virtual-accounts` · `/{id}` | list / fetch accounts |
| POST | `/v1/fx/quote` | quote a conversion (rate + spread + net) |
| POST | `/v1/fx/conversions` | convert one wallet balance into another |
| POST | `/webhooks/banking/{account}` | signed BaaS incoming-payment notification |
| GET | `/v1/transactions` · `/v1/transactions/{id}` | list / fetch |
| GET | `/v1/balance` | settled + available balance per currency/asset |
| POST/PUT | `/webhooks/mtn-momo/{product}/{ref}` | MoMo callback (re-verified via poll) |
| POST | `/webhooks/crypto/{ref}` | signed chain-watcher deposit notification |

---

<details><summary>Built on Laravel</summary>

Laravel is a web application framework with expressive, elegant syntax. See the
[Laravel documentation](https://laravel.com/docs). This project is MIT-licensed
(see `LICENSE`); Laravel itself is also MIT-licensed.

</details>
