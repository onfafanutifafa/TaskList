<?php

namespace App\Enums;

enum LedgerDirection: string
{
    case Debit = 'debit';
    case Credit = 'credit';

    public function opposite(): self
    {
        return $this === self::Debit ? self::Credit : self::Debit;
    }

    /** Signed sign for this direction against an account whose normal balance is $normal. */
    public function signFor(self $normal): int
    {
        return $this === $normal ? 1 : -1;
    }
}
