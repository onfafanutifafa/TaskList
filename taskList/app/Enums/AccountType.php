<?php

namespace App\Enums;

enum AccountType: string
{
    case Asset = 'asset';         // what the PSP holds (provider float / clearing)
    case Liability = 'liability'; // what the PSP owes (merchant payable balances)
    case Revenue = 'revenue';     // fees earned
    case Expense = 'expense';     // provider costs, write-offs

    /** Direction that increases this account. */
    public function normalBalance(): LedgerDirection
    {
        return match ($this) {
            self::Asset, self::Expense => LedgerDirection::Debit,
            self::Liability, self::Revenue => LedgerDirection::Credit,
        };
    }
}
