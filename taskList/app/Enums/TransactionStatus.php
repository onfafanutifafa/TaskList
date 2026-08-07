<?php

namespace App\Enums;

enum TransactionStatus: string
{
    case Pending = 'pending';       // created, not yet sent to the provider
    case Processing = 'processing'; // provider accepted; awaiting payer action / settlement
    case Succeeded = 'succeeded';   // funds confirmed moved
    case Failed = 'failed';         // declined, timed out, or reversed

    /** A status the money never moved from — safe to leave the ledger untouched. */
    public function isOpen(): bool
    {
        return in_array($this, [self::Pending, self::Processing], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed], true);
    }
}
