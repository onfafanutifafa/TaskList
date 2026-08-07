<?php

namespace App\Providers\Banking\Contracts;

/**
 * A banking-as-a-service partner that issues virtual receiving accounts. Incoming
 * payments to those accounts are reported back to us via the signed banking webhook
 * (the partner is trusted-but-verified, like the crypto watcher).
 */
interface VirtualAccountProvider
{
    public function name(): string;

    public function createAccount(VirtualAccountRequest $request): VirtualAccountDetails;
}
