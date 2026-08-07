<?php

namespace App\Services\Fx;

use App\Services\Fx\Contracts\ExchangeRateProvider;

/** Deterministic rates for tests. */
class FakeRateProvider implements ExchangeRateProvider
{
    /** @param array<string,string> $rates keyed "FROM:TO" */
    public function __construct(public array $rates = ['USDT:GHS' => '15.00']) {}

    public function rate(string $from, string $to): string
    {
        $from = strtoupper($from);
        $to = strtoupper($to);

        if ($from === $to) {
            return '1';
        }

        return $this->rates["{$from}:{$to}"]
            ?? (isset($this->rates["{$to}:{$from}"]) ? bcdiv('1', $this->rates["{$to}:{$from}"], 12) : '0');
    }
}
