<?php

namespace App\Services\Transactions;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Merchant;
use App\Models\Transaction;
use App\Providers\Banking\BankingProviderManager;
use App\Providers\Banking\Contracts\BankPayoutRequest;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Sends foreign currency (USD/GBP/EUR) out of a merchant's balance to an external
 * bank beneficiary, via the banking partner. Mirrors the mobile-money payout: the
 * ledger is only drawn down on confirmed success, and the balance guard counts
 * still-in-flight payouts so a merchant cannot overspend.
 */
class BankPayoutService
{
    public function __construct(
        private readonly BankingProviderManager $providers,
        private readonly BalanceService $balances,
        private readonly TransactionReconciler $reconciler,
    ) {}

    /**
     * @param  array{amount:int,currency:string,reference:string,narration?:string,beneficiary:array<string,mixed>}  $data
     */
    public function initiate(Merchant $merchant, array $data, ?string $idempotencyKey = null): Transaction
    {
        $currency = strtoupper($data['currency']);
        $amount = new Money($data['amount'], $currency);

        if ($merchant->transactions()->where('reference', $data['reference'])->exists()) {
            throw new ConflictHttpException("A transaction with reference [{$data['reference']}] already exists.");
        }

        $transaction = DB::transaction(function () use ($merchant, $data, $currency, $amount, $idempotencyKey) {
            $available = $this->balances->available($merchant, $currency);

            if ($amount->isGreaterThan($available)) {
                throw new UnprocessableEntityHttpException(
                    "Insufficient {$currency} balance: need {$amount->toMajorString()}, ".
                    "available {$available->toMajorString()}."
                );
            }

            return $merchant->transactions()->create([
                'type' => TransactionType::BankPayout,
                'status' => TransactionStatus::Pending,
                'amount_minor' => $amount->minor,
                'fee_minor' => 0,
                'currency' => $currency,
                'provider' => $this->providers->driver()->name(),
                'network' => config("psp.banking.rails.{$currency}.rail", 'bank'),
                'msisdn' => null,
                'reference' => $data['reference'],
                'narration' => $data['narration'] ?? null,
                'idempotency_key' => $idempotencyKey,
                'meta' => ['beneficiary' => $data['beneficiary']],
            ]);
        });

        $result = $this->providers->driver()->payout(new BankPayoutRequest(
            reference: $transaction->id,
            amount: $amount,
            beneficiary: $data['beneficiary'],
            narration: $transaction->narration,
        ));

        if (! $result->accepted) {
            return $this->reconciler->apply($transaction, $result);
        }

        $transaction->update(['status' => TransactionStatus::Processing, 'authorized_at' => now()]);

        return $transaction->refresh();
    }

    /** Poll the partner for the payout's status and settle it if resolved. */
    public function poll(Transaction $transaction): Transaction
    {
        if ($transaction->status->isTerminal()) {
            return $transaction;
        }

        return $this->reconciler->apply(
            $transaction,
            $this->providers->driver()->payoutStatus($transaction->id),
        );
    }
}
