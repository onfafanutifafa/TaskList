<?php

namespace App\Services\Transactions;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Providers\MobileMoney\Contracts\ProviderResult;
use App\Providers\MobileMoney\Contracts\ProviderStatus;
use App\Providers\MobileMoney\ProviderManager;
use App\Services\Ledger\LedgerService;
use App\Services\Webhooks\WebhookDispatcher;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

/**
 * Owns the one-way transition of a transaction to a terminal state. This is the
 * ONLY place money hits the ledger, and it is idempotent: applying a terminal
 * result to an already-terminal transaction is a no-op. Both the status poller
 * and the provider callback funnel through here, so a race between them is safe.
 */
class TransactionReconciler
{
    public function __construct(
        private readonly ProviderManager $providers,
        private readonly LedgerService $ledger,
        private readonly WebhookDispatcher $webhooks,
    ) {}

    /**
     * Ask the mobile-money provider for the current status and apply it.
     * Crypto deposits reconcile through CryptoDepositService, not here.
     */
    public function poll(Transaction $transaction): Transaction
    {
        if ($transaction->status->isTerminal() || $transaction->type === TransactionType::CryptoDeposit) {
            return $transaction;
        }

        $provider = $this->providers->driver($transaction->provider);

        $result = $transaction->type === TransactionType::Collection
            ? $provider->collectionStatus($transaction->id)
            : $provider->payoutStatus($transaction->id);

        return $this->apply($transaction, $result);
    }

    /** Apply a provider result to a transaction, settling the ledger on success. */
    public function apply(Transaction $transaction, ProviderResult $result): Transaction
    {
        if ($transaction->status->isTerminal()) {
            return $transaction; // already settled — never post twice
        }

        if ($result->status === ProviderStatus::Pending) {
            if ($transaction->status === TransactionStatus::Pending) {
                $transaction->update(['status' => TransactionStatus::Processing]);
            }

            return $transaction;
        }

        return DB::transaction(function () use ($transaction, $result) {
            if ($result->status === ProviderStatus::Successful) {
                match ($transaction->type) {
                    TransactionType::Collection => $this->ledger->recordCollectionSettlement($transaction),
                    TransactionType::CryptoDeposit => $this->ledger->recordDepositSettlement($transaction),
                    TransactionType::BankDeposit => $this->ledger->recordBankDepositSettlement($transaction),
                    TransactionType::Payout => $this->ledger->recordPayoutSettlement($transaction),
                };

                $transaction->update([
                    'status' => TransactionStatus::Succeeded,
                    'provider_reference' => $result->providerReference ?? $transaction->provider_reference,
                    'succeeded_at' => now(),
                ]);

                $this->webhooks->dispatch($transaction, 'transaction.succeeded');
            } else {
                $transaction->update([
                    'status' => TransactionStatus::Failed,
                    'failure_code' => $result->failureCode,
                    'failure_reason' => $result->failureReason,
                    'failed_at' => now(),
                ]);

                $this->webhooks->dispatch($transaction, 'transaction.failed');
            }

            return $transaction->refresh();
        });
    }
}
