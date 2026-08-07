<?php

namespace App\Support;

use App\Models\Transaction;

/** Single source of truth for how a transaction is serialised to merchants. */
final class TransactionPayload
{
    /** @return array<string,mixed> */
    public static function make(Transaction $transaction): array
    {
        return [
            'id' => $transaction->id,
            'type' => $transaction->type->value,
            'status' => $transaction->status->value,
            'amount' => $transaction->amount_minor,          // minor units
            'fee' => $transaction->fee_minor,
            'amount_display' => $transaction->amount()->toMajorString(),
            'currency' => $transaction->currency,
            'network' => $transaction->network,
            'phone' => $transaction->msisdn,
            'reference' => $transaction->reference,
            'provider_reference' => $transaction->provider_reference,
            'narration' => $transaction->narration,
            'failure_code' => $transaction->failure_code,
            'failure_reason' => $transaction->failure_reason,
            'created_at' => optional($transaction->created_at)->toIso8601String(),
            'succeeded_at' => optional($transaction->succeeded_at)->toIso8601String(),
            'failed_at' => optional($transaction->failed_at)->toIso8601String(),
        ];
    }
}
