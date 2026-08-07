<?php

namespace App\Providers\Banking\Contracts;

final class VirtualAccountRequest
{
    public function __construct(
        public readonly string $merchantId,
        public readonly string $accountName,   // holder name shown on the account
        public readonly string $currency,      // USD | GBP | EUR
    ) {}
}
