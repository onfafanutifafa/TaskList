<?php

namespace App\Providers\Banking\Contracts;

use App\Support\Money;

/** A request to wire foreign currency out to an external bank beneficiary. */
final class BankPayoutRequest
{
    /**
     * @param  array<string,mixed>  $beneficiary  account_name, account_number,
     *                                            bank_name, routing_number|sort_code|iban, country
     */
    public function __construct(
        public readonly string $reference,   // our transaction id
        public readonly Money $amount,
        public readonly array $beneficiary,
        public readonly ?string $narration = null,
    ) {}
}
