<?php

namespace App\Console\Commands;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Jobs\ReconcileTransaction;
use App\Models\Transaction;
use Illuminate\Console\Command;

/**
 * Reconciliation safety net for mobile-money transactions. Callbacks can be
 * missed; this enqueues a reconcile job for every still-open collection/payout
 * so a worker settles the ones that have resolved. Schedule it every minute.
 */
class PollPendingTransactions extends Command
{
    protected $signature = 'psp:poll-pending {--limit=500 : max transactions to enqueue per run}';

    protected $description = 'Enqueue reconcile jobs for open mobile-money transactions';

    public function handle(): int
    {
        $open = Transaction::whereIn('status', [
            TransactionStatus::Pending->value,
            TransactionStatus::Processing->value,
        ])
            ->whereIn('type', [TransactionType::Collection->value, TransactionType::Payout->value])
            ->orderBy('created_at')->limit((int) $this->option('limit'))->pluck('id');

        $open->each(fn (string $id) => ReconcileTransaction::dispatch($id));

        $this->info("Enqueued {$open->count()} reconcile job(s).");

        return self::SUCCESS;
    }
}
