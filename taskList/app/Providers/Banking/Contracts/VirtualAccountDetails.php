<?php

namespace App\Providers\Banking\Contracts;

/** Account coordinates issued by the banking partner for a virtual account. */
final class VirtualAccountDetails
{
    public function __construct(
        public readonly string $accountName,
        public readonly string $bankName,
        public readonly string $rail,
        public readonly ?string $accountNumber = null,
        public readonly ?string $routingNumber = null,  // US ABA
        public readonly ?string $sortCode = null,       // UK
        public readonly ?string $iban = null,           // EU/UK
        public readonly ?string $swiftBic = null,
        public readonly ?string $providerReference = null,
    ) {}
}
