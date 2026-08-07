<?php

namespace App\Enums;

enum TransactionType: string
{
    case Collection = 'collection';         // pay-in: pull money from a payer's wallet
    case Payout = 'payout';                 // disbursement: push money to a recipient's wallet
    case CryptoDeposit = 'crypto_deposit';  // on-ramp: stablecoin received on-chain

    /** Pay-ins that increase the merchant's balance when they settle. */
    public function isCredit(): bool
    {
        return $this === self::Collection || $this === self::CryptoDeposit;
    }
}
