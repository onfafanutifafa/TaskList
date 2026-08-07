<?php

namespace App\Services\Fx\Contracts;

interface ExchangeRateProvider
{
    /** Units of $to per 1 $from, as a decimal string (e.g. "15.20"). */
    public function rate(string $from, string $to): string;
}
