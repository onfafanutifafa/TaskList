<?php

namespace App\Services\Ledger;

use App\Enums\LedgerDirection;
use App\Models\LedgerAccount;
use App\Support\Money;

final class JournalLeg
{
    public function __construct(
        public readonly LedgerAccount $account,
        public readonly LedgerDirection $direction,
        public readonly Money $amount,
    ) {}

    public static function debit(LedgerAccount $account, Money $amount): self
    {
        return new self($account, LedgerDirection::Debit, $amount);
    }

    public static function credit(LedgerAccount $account, Money $amount): self
    {
        return new self($account, LedgerDirection::Credit, $amount);
    }
}
