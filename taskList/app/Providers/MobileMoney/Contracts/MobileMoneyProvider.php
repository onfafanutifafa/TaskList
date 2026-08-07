<?php

namespace App\Providers\MobileMoney\Contracts;

/**
 * A mobile-money rail. Implementations wrap one provider's API (MTN MoMo today;
 * M-Pesa / Airtel later) behind these four calls. Nothing above this interface
 * knows which provider it is talking to.
 */
interface MobileMoneyProvider
{
    public function name(): string;

    /** Initiate a pull from the payer's wallet (request-to-pay). */
    public function collect(MoneyRequest $request): ProviderResult;

    /** Initiate a push to the recipient's wallet (disbursement). */
    public function payout(MoneyRequest $request): ProviderResult;

    /** Look up the current status of a previously-initiated collection. */
    public function collectionStatus(string $reference): ProviderResult;

    /** Look up the current status of a previously-initiated payout. */
    public function payoutStatus(string $reference): ProviderResult;
}
