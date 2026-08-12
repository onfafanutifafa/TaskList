<?php

namespace App\Console\Commands;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Jobs\ReconcileTransaction;
use App\Models\Transaction;
use Illuminate\Console\Command;

/**
 * Safety net for outbound bank payouts: enqueues a reconcile job for every still-
 * open payout so a worker polls the banking partner and settles resolved ones.
 */
class PollBankPayouts extends Command
{
    protected $signature = 'bank:poll-payouts {--limit=500}';

    protected $description = 'Enqueue reconcile jobs for open bank payouts';

    public function handle(): int
    {
        $open = Transaction::where('type', TransactionType::BankPayout->value)
            ->whereIn('status', [TransactionStatus::Pending->value, TransactionStatus::Processing->value])
            ->orderBy('created_at')->limit((int) $this->option('limit'))->pluck('id');

        $open->each(fn (string $id) => ReconcileTransaction::dispatch($id));

        $this->info("Enqueued {$open->count()} bank-payout reconcile job(s).");

        return self::SUCCESS;
    }
}
