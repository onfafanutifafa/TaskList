<?php

namespace App\Services\Transactions;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Merchant;
use App\Services\Ledger\LedgerService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/** One definition of "spendable balance" shared by payouts and FX. */
class BalanceService
{
    public function __construct(private readonly LedgerService $ledger) {}

    public function settled(Merchant $merchant, string $currency): Money
    {
        return $this->ledger->merchantBalance($merchant, $currency);
    }

    /** Settled balance minus money already committed to in-flight payouts (any rail). */
    public function available(Merchant $merchant, string $currency): Money
    {
        $inflight = (int) $merchant->transactions()
            ->whereIn('type', [TransactionType::Payout->value, TransactionType::BankPayout->value])
            ->where('currency', $currency)
            ->whereIn('status', [TransactionStatus::Pending->value, TransactionStatus::Processing->value])
            ->sum(DB::raw('amount_minor + fee_minor'));

        return $this->settled($merchant, $currency)->subtract(new Money($inflight, $currency));
    }
}
