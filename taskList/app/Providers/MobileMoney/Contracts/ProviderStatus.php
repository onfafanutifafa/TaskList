<?php

namespace App\Providers\MobileMoney\Contracts;

use App\Enums\TransactionStatus;

/** Normalised money-movement status, provider-agnostic. */
enum ProviderStatus: string
{
    case Pending = 'pending';
    case Successful = 'successful';
    case Failed = 'failed';

    public function toTransactionStatus(): TransactionStatus
    {
        return match ($this) {
            self::Pending => TransactionStatus::Processing,
            self::Successful => TransactionStatus::Succeeded,
            self::Failed => TransactionStatus::Failed,
        };
    }
}
