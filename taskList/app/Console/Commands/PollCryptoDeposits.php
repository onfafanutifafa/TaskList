<?php

namespace App\Console\Commands;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Jobs\ReconcileTransaction;
use App\Models\Transaction;
use Illuminate\Console\Command;

/**
 * Safety net for crypto deposits whose watcher webhook was missed or delayed.
 * Enqueues a reconcile job for each open deposit; a worker settles or expires it.
 */
class PollCryptoDeposits extends Command
{
    protected $signature = 'crypto:poll-deposits {--limit=500}';

    protected $description = 'Enqueue reconcile jobs for open crypto deposits';

    public function handle(): int
    {
        $open = Transaction::where('type', TransactionType::CryptoDeposit->value)
            ->whereIn('status', [TransactionStatus::Pending->value, TransactionStatus::Processing->value])
            ->orderBy('created_at')
            ->limit((int) $this->option('limit'))
            ->pluck('id');

        $open->each(fn (string $id) => ReconcileTransaction::dispatch($id));

        $this->info("Enqueued {$open->count()} crypto reconcile job(s).");

        return self::SUCCESS;
    }
}
