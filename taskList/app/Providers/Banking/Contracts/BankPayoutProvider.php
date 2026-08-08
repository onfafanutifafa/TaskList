<?php

namespace App\Providers\Banking\Contracts;

use App\Providers\MobileMoney\Contracts\ProviderResult;

/** Sending foreign currency out to an external bank account. */
interface BankPayoutProvider
{
    public function payout(BankPayoutRequest $request): ProviderResult;

    public function payoutStatus(string $reference): ProviderResult;
}
