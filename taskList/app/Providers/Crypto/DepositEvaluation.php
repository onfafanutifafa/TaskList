<?php

namespace App\Providers\Crypto;

use App\Models\CryptoDeposit;
use App\Providers\MobileMoney\Contracts\ProviderResult;
use App\Providers\MobileMoney\Contracts\ProviderStatus;

/**
 * Turns the observed on-chain state of a deposit into a normalised result the
 * reconciler can settle. Single source of truth for "is this deposit done?",
 * used by the watcher webhook and by polling alike.
 */
final class DepositEvaluation
{
    public static function of(CryptoDeposit $deposit): ProviderResult
    {
        if ($deposit->isConfirmed()) {
            return new ProviderResult(
                accepted: true,
                status: ProviderStatus::Successful,
                providerReference: $deposit->tx_hash,
            );
        }

        // Nothing arrived before the window closed -> give up on this intent.
        if ($deposit->isExpired() && $deposit->amount_received_minor === 0) {
            return ProviderResult::failed('expired', 'Deposit window expired with no funds received.');
        }

        return ProviderResult::accepted(ProviderStatus::Pending);
    }
}
