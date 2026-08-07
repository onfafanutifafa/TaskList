<?php

namespace App\Services\Fx;

use App\Services\Fx\Contracts\ExchangeRateProvider;
use RuntimeException;

/** Resolves the active exchange-rate provider. Singleton so a test fake sticks. */
class RateProviderManager
{
    private const DRIVERS = [
        'config' => ConfigRateProvider::class,
    ];

    private ?ExchangeRateProvider $override = null;

    public function driver(): ExchangeRateProvider
    {
        if ($this->override) {
            return $this->override;
        }

        $key = config('psp.fx.provider', 'config');
        $class = self::DRIVERS[$key] ?? throw new RuntimeException("Unknown FX provider [{$key}].");

        return new $class;
    }

    public function fake(?ExchangeRateProvider $fake = null): ExchangeRateProvider
    {
        return $this->override = $fake ?? new FakeRateProvider;
    }
}
