<?php

namespace App\Console\Commands;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Services\Transactions\CryptoDepositService;
use Illuminate\Console\Command;

/**
 * Safety net for crypto deposits whose watcher webhook was missed or delayed.
 * Re-evaluates each open deposit and settles or expires it. Schedule it.
 */
class PollCryptoDeposits extends Command
{
    protected $signature = 'crypto:poll-deposits {--limit=200}';

    protected $description = 'Re-evaluate open crypto deposits and settle/expire resolved ones';

    public function handle(CryptoDepositService $deposits): int
    {
        $open = Transaction::with('cryptoDeposit')
            ->where('type', TransactionType::CryptoDeposit->value)
            ->whereIn('status', [TransactionStatus::Pending->value, TransactionStatus::Processing->value])
            ->orderBy('created_at')
            ->limit((int) $this->option('limit'))
            ->get();

        $settled = 0;

        foreach ($open as $transaction) {
            if ($deposits->poll($transaction)->status->isTerminal()) {
                $settled++;
            }
        }

        $this->info("Polled {$open->count()} open crypto deposit(s); {$settled} reached a terminal state.");

        return self::SUCCESS;
    }
}
