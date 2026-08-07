<?php

namespace App\Services\Transactions;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\CryptoDeposit;
use App\Models\Merchant;
use App\Models\Transaction;
use App\Providers\Crypto\Contracts\CryptoDepositRequest;
use App\Providers\Crypto\CryptoProviderManager;
use App\Providers\Crypto\DepositEvaluation;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class CryptoDepositService
{
    public function __construct(
        private readonly CryptoProviderManager $providers,
        private readonly TransactionReconciler $reconciler,
    ) {}

    /**
     * Create a stablecoin deposit intent: reserves a destination address and a
     * transaction the watcher will later confirm. Amount is in the asset's minor
     * units (USDT/USDC have 6 decimals -> 1_000_000 = 1.00).
     *
     * @param  array{amount:int,asset:string,chain:string,reference:string,narration?:string}  $data
     */
    public function initiate(Merchant $merchant, array $data, ?string $idempotencyKey = null): Transaction
    {
        $asset = strtoupper($data['asset']);
        $amount = new Money($data['amount'], $asset);
        $fee = $amount->feeAtBps(config('psp.fee_bps'));
        $provider = $this->providers->driver();

        if ($merchant->transactions()->where('reference', $data['reference'])->exists()) {
            throw new ConflictHttpException("A transaction with reference [{$data['reference']}] already exists.");
        }

        return DB::transaction(function () use ($merchant, $data, $asset, $amount, $fee, $idempotencyKey, $provider) {
            $transaction = $merchant->transactions()->create([
                'type' => TransactionType::CryptoDeposit,
                'status' => TransactionStatus::Pending,
                'amount_minor' => $amount->minor,
                'fee_minor' => $fee->minor,
                'currency' => $asset,
                'provider' => $provider->name(),
                'network' => $data['chain'],
                'msisdn' => null,
                'reference' => $data['reference'],
                'narration' => $data['narration'] ?? null,
                'idempotency_key' => $idempotencyKey,
            ]);

            $address = $provider->createDeposit(new CryptoDepositRequest(
                reference: $transaction->id,
                asset: $asset,
                chain: $data['chain'],
                amount: $amount,
            ));

            CryptoDeposit::create([
                'transaction_id' => $transaction->id,
                'asset' => $asset,
                'chain' => $data['chain'],
                'address' => $address->address,
                'memo' => $address->memo,
                'amount_expected_minor' => $amount->minor,
                'required_confirmations' => $address->requiredConfirmations,
                'expires_at' => $address->expiresAt,
            ]);

            // Intent created and awaiting an on-chain payment.
            $transaction->update(['status' => TransactionStatus::Processing, 'authorized_at' => now()]);

            return $transaction->load('cryptoDeposit');
        });
    }

    /**
     * Apply an on-chain observation from the watcher, then settle if confirmed.
     *
     * @param  array{amount_received_minor?:int,confirmations?:int,tx_hash?:string}  $observation
     */
    public function applyWatcherUpdate(CryptoDeposit $deposit, array $observation): Transaction
    {
        $deposit->forceFill(array_filter([
            'amount_received_minor' => $observation['amount_received_minor'] ?? null,
            'confirmations' => $observation['confirmations'] ?? null,
            'tx_hash' => $observation['tx_hash'] ?? null,
        ], fn ($v) => $v !== null))->save();

        return $this->reconciler->apply($deposit->transaction, DepositEvaluation::of($deposit->refresh()));
    }

    /** Poll the provider for the current on-chain state and settle if resolved. */
    public function poll(Transaction $transaction): Transaction
    {
        if ($transaction->status->isTerminal()) {
            return $transaction;
        }

        $deposit = $transaction->cryptoDeposit;

        if (! $deposit) {
            return $transaction;
        }

        return $this->reconciler->apply($transaction, $this->providers->driver()->poll($deposit));
    }
}
