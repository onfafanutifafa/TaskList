<?php

namespace App\Support;

use App\Models\Transaction;

/** Single source of truth for how a transaction is serialised to merchants. */
final class TransactionPayload
{
    /** @return array<string,mixed> */
    public static function make(Transaction $transaction): array
    {
        $payload = [
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

        if ($transaction->type === \App\Enums\TransactionType::BankPayout && isset($transaction->meta['beneficiary'])) {
            $payload['beneficiary'] = $transaction->meta['beneficiary'];
        }

        if ($transaction->relationLoaded('cryptoDeposit') && $transaction->cryptoDeposit) {
            $d = $transaction->cryptoDeposit;
            $payload['crypto'] = [
                'asset' => $d->asset,
                'chain' => $d->chain,
                'address' => $d->address,
                'memo' => $d->memo,
                'amount_expected' => $d->amount_expected_minor,
                'amount_received' => $d->amount_received_minor,
                'confirmations' => $d->confirmations,
                'required_confirmations' => $d->required_confirmations,
                'tx_hash' => $d->tx_hash,
                'expires_at' => optional($d->expires_at)->toIso8601String(),
            ];
        }

        return $payload;
    }
}
