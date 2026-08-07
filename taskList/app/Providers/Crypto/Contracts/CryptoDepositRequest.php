<?php

namespace App\Providers\Crypto\Contracts;

use App\Support\Money;

final class CryptoDepositRequest
{
    public function __construct(
        public readonly string $reference,   // our transaction id (also the memo/tag)
        public readonly string $asset,       // USDT | USDC
        public readonly string $chain,       // tron | ethereum | base
        public readonly Money $amount,       // expected amount, in the asset's minor units
    ) {}
}
