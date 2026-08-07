<?php

namespace App\Providers\Crypto\Contracts;

use Carbon\CarbonInterface;

/** The destination a payer sends funds to for a specific deposit. */
final class CryptoAddress
{
    public function __construct(
        public readonly string $address,
        public readonly int $requiredConfirmations,
        public readonly ?string $memo = null,
        public readonly ?CarbonInterface $expiresAt = null,
    ) {}
}
