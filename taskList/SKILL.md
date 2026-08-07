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

## 8. Reconciliation & ops (`app/Console/Commands/*`)

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
