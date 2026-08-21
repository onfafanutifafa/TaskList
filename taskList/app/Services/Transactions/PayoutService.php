<?php

namespace App\Services\Transactions;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Merchant;
use App\Models\Transaction;
use App\Providers\MobileMoney\Contracts\MoneyRequest;
use App\Providers\MobileMoney\ProviderManager;
use App\Services\Fraud\MasenuClient;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PayoutService
{
    public function __construct(
        private readonly ProviderManager $providers,
        private readonly BalanceService $balances,
        private readonly TransactionReconciler $reconciler,
        private readonly MasenuClient $masenu,
    ) {}

    /**
     * Push money to a recipient's mobile-money wallet, drawn from the merchant's
     * settled balance. Guards against overspend including still-in-flight payouts.
     *
     * @param  array{amount:int,currency:string,phone:string,network:string,reference:string,narration?:string}  $data
     */
    public function initiate(Merchant $merchant, array $data, ?string $idempotencyKey = null): Transaction
    {
        $currency = strtoupper($data['currency']);
        $amount = new Money($data['amount'], $currency);

        // Cross-network fraud screen the recipient before any money moves.
        // Throws FraudBlockedException (422) when the Masenu network says block.
        $this->masenu->assertAllowed($data['phone']);

        if ($merchant->transactions()->where('reference', $data['reference'])->exists()) {
            throw new ConflictHttpException("A transaction with reference [{$data['reference']}] already exists.");
        }

        $transaction = DB::transaction(function () use ($merchant, $data, $currency, $amount, $idempotencyKey) {
            // Row-locked hold: atomically checks and reserves, or throws 422.
            $this->balances->reserve($merchant, $amount);

            return $merchant->transactions()->create([
                'type' => TransactionType::Payout,
                'status' => TransactionStatus::Pending,
                'amount_minor' => $amount->minor,
                'fee_minor' => 0,
                'currency' => $currency,
                'provider' => $this->providers->keyForNetwork($data['network']),
                'network' => $data['network'],
                'msisdn' => $data['phone'],
                'reference' => $data['reference'],
                'narration' => $data['narration'] ?? null,
                'idempotency_key' => $idempotencyKey,
            ]);
        });

        $result = $this->providers->forNetwork($data['network'])->payout(new MoneyRequest(
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

    /** Settled balance minus payouts that are initiated but not yet terminal. */
    public function availableBalance(Merchant $merchant, string $currency): Money
    {
        return $this->balances->available($merchant, $currency);
    }
}
