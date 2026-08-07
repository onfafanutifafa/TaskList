<?php

namespace App\Services\Fx;

use App\Support\Money;

final class FxQuote
{
    public function __construct(
        public readonly Money $from,     // amount debited from the source wallet
        public readonly Money $gross,    // converted amount before spread
        public readonly Money $spread,   // FX revenue (in the destination currency)
        public readonly Money $net,      // amount credited to the destination wallet
        public readonly string $rate,    // units of destination per 1 source
        public readonly int $spreadBps,
    ) {}
}
