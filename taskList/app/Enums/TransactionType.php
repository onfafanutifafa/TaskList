<?php

namespace App\Enums;

enum TransactionType: string
{
    case Collection = 'collection'; // pay-in: pull money from a payer's wallet
    case Payout = 'payout';         // disbursement: push money to a recipient's wallet
}
