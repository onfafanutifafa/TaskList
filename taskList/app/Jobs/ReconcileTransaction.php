<?php

namespace App\Jobs;

use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Services\Transactions\BankPayoutService;
use App\Services\Transactions\CryptoDepositService;
use App\Services\Transactions\TransactionReconciler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Reconciles one transaction off the request path: asks the right provider for
 * its current status and settles it if resolved. Routed by type to the owning
 * service. Idempotent (settlement guards terminal state), so retries are safe.
 * A blocking provider GET no longer sits inside the webhook/HTTP request.
 */
class ReconcileTransaction implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public readonly string $transactionId)
    {
        $this->onQueue('settlement');
    }

    /** @return array<int,object> */
    public function middleware(): array
    {
        // One in-flight reconcile per transaction at a time.
        return [(new WithoutOverlapping($this->transactionId))->releaseAfter(30)->expireAfter(180)];
    }

    /** Exponential-ish back-off between provider retries (seconds). */
    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }

    public function handle(
        TransactionReconciler $reconciler,
        CryptoDepositService $crypto,
        BankPayoutService $bankPayouts,
    ): void {
        $transaction = Transaction::find($this->transactionId);

        if (! $transaction || $transaction->status->isTerminal()) {
            return;
        }

        match ($transaction->type) {
            TransactionType::CryptoDeposit => $crypto->poll($transaction),
            TransactionType::BankPayout => $bankPayouts->poll($transaction),
            // BankDeposit settles synchronously on its webhook; nothing to poll.
            TransactionType::BankDeposit => null,
            default => $reconciler->poll($transaction), // Collection, Payout
        };
    }
}
