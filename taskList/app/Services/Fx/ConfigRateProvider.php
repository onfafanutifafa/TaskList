<?php

namespace App\Services\Fx;

use App\Exceptions\ProviderException;
use App\Services\Fx\Contracts\ExchangeRateProvider;

/** Rates from config. Derives the inverse pair when only one direction is set. */
class ConfigRateProvider implements ExchangeRateProvider
{
    public function rate(string $from, string $to): string
    {
        $from = strtoupper($from);
        $to = strtoupper($to);

        if ($from === $to) {
            return '1';
        }

        $rates = config('psp.fx.rates', []);

        if (isset($rates["{$from}:{$to}"])) {
            return (string) $rates["{$from}:{$to}"];
        }

        if (isset($rates["{$to}:{$from}"])) {
            return bcdiv('1', (string) $rates["{$to}:{$from}"], 12);
        }

        throw new ProviderException("No exchange rate available for {$from} -> {$to}.");
    }
}
