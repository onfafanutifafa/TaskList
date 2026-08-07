<?php

namespace App\Providers\MobileMoney\Contracts;

use App\Support\Money;

/**
 * A request to move money to/from an MSISDN. Used for both collections (pull)
 * and payouts (push) — the direction is decided by which provider method is called.
 * `reference` is our transaction id; providers that accept a client-supplied id
 * (like MTN's X-Reference-Id) use it directly, which makes retries idempotent.
 */
final class MoneyRequest
{
    public function __construct(
        public readonly string $reference,
        public readonly string $msisdn,
        public readonly Money $amount,
        public readonly string $externalId,      // merchant-supplied reference
        public readonly ?string $narration = null,
    ) {}
}
