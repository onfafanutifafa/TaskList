<?php

namespace App\Providers\Crypto\Contracts;

use App\Models\CryptoDeposit;
use App\Providers\MobileMoney\Contracts\ProviderResult;

/**
 * A crypto on-ramp rail. `createDeposit` assigns a destination; `poll` reports
 * the current on-chain state of a deposit. Confirmation normally arrives via the
 * signed watcher webhook — `poll` is the safety net (and the seam a production
 * provider uses to query an explorer directly). Reuses {@see ProviderResult} so
 * settlement flows through the same reconciler as mobile money.
 */
interface CryptoProvider
{
    public function name(): string;

    public function createDeposit(CryptoDepositRequest $request): CryptoAddress;

    public function poll(CryptoDeposit $deposit): ProviderResult;
}
