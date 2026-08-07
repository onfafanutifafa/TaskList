<?php

namespace App\Console\Commands;

use App\Enums\TransactionStatus;
use App\Models\Transaction;
use App\Services\Transactions\TransactionReconciler;
use Illuminate\Console\Command;

/**
 * Reconciliation safety net. Callbacks can be missed; this polls the provider
 * for every still-open transaction and settles the ones that have resolved.
 * Schedule it every minute (see routes/console.php).
 */
class PollPendingTransactions extends Command
{
    protected $signature = 'psp:poll-pending {--limit=200 : max transactions to poll per run}';

    protected $description = 'Poll open transactions against their provider and settle resolved ones';

    public function handle(TransactionReconciler $reconciler): int
    {
        $open = Transaction::whereIn('status', [
            TransactionStatus::Pending->value,
            TransactionStatus::Processing->value,
        ])->orderBy('created_at')->limit((int) $this->option('limit'))->get();

        $settled = 0;

        foreach ($open as $transaction) {
            $after = $reconciler->poll($transaction);

            if ($after->status->isTerminal()) {
                $settled++;
                $this->line("  {$transaction->id} → <info>{$after->status->value}</info>");
            }
        }

        $this->info("Polled {$open->count()} open transaction(s); {$settled} reached a terminal state.");

        return self::SUCCESS;
    }
}
