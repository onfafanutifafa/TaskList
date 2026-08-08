<?php

namespace App\Console\Commands;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Services\Transactions\BankPayoutService;
use Illuminate\Console\Command;

/**
 * Reconciliation safety net for outbound bank payouts: polls the banking partner
 * for every still-open payout and settles the ones that have resolved.
 */
class PollBankPayouts extends Command
{
    protected $signature = 'bank:poll-payouts {--limit=200}';

    protected $description = 'Poll open bank payouts against the banking partner and settle resolved ones';

    public function handle(BankPayoutService $payouts): int
    {
        $open = Transaction::where('type', TransactionType::BankPayout->value)
            ->whereIn('status', [TransactionStatus::Pending->value, TransactionStatus::Processing->value])
            ->orderBy('created_at')->limit((int) $this->option('limit'))->get();

        $settled = 0;

        foreach ($open as $transaction) {
            if ($payouts->poll($transaction)->status->isTerminal()) {
                $settled++;
            }
        }

        $this->info("Polled {$open->count()} open bank payout(s); {$settled} settled.");

        return self::SUCCESS;
    }
}
