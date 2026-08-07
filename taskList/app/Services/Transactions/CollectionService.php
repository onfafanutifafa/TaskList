<?php

namespace App\Services\Transactions;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Merchant;
use App\Models\Transaction;
use App\Providers\MobileMoney\Contracts\MoneyRequest;
use App\Providers\MobileMoney\ProviderManager;
use App\Support\Money;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class CollectionService
{
    public function __construct(
        private readonly ProviderManager $providers,
        private readonly TransactionReconciler $reconciler,
    ) {}

    /**
     * Pull money from a payer's mobile-money wallet.
     *
     * @param  array{amount:int,currency:string,phone:string,network:string,reference:string,narration?:string}  $data
     */
    public function initiate(Merchant $merchant, array $data, ?string $idempotencyKey = null): Transaction
    {
        $currency = strtoupper($data['currency']);
        $amount = new Money($data['amount'], $currency);
        $fee = $amount->feeAtBps(config('psp.fee_bps'));

        if ($merchant->transactions()->where('reference', $data['reference'])->exists()) {
            throw new ConflictHttpException("A transaction with reference [{$data['reference']}] already exists.");
        }

        $transaction = $merchant->transactions()->create([
            'type' => TransactionType::Collection,
            'status' => TransactionStatus::Pending,
            'amount_minor' => $amount->minor,
            'fee_minor' => $fee->minor,
            'currency' => $currency,
            'provider' => $this->providers->keyForNetwork($data['network']),
            'network' => $data['network'],
            'msisdn' => $data['phone'],
            'reference' => $data['reference'],
            'narration' => $data['narration'] ?? null,
            'idempotency_key' => $idempotencyKey,
        ]);

        $result = $this->providers->forNetwork($data['network'])->collect(new MoneyRequest(
            reference: $transaction->id,
            msisdn: $transaction->msisdn,
            amount: $amount,
            externalId: $transaction->reference,
            narration: $transaction->narration,
        ));

        if (! $result->accepted) {
            return $this->reconciler->apply($transaction, $result);
        }

        $transaction->update([
            'status' => TransactionStatus::Processing,
            'authorized_at' => now(),
        ]);

        return $transaction->refresh();
    }
}
