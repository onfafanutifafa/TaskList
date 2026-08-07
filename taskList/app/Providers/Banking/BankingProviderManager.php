<?php

namespace App\Providers\Banking;

use App\Providers\Banking\Baas\BaasVirtualAccountProvider;
use App\Providers\Banking\Contracts\VirtualAccountProvider;
use App\Providers\Banking\Fake\FakeBankingProvider;
use RuntimeException;

/** Resolves the active banking provider. Singleton so a test fake sticks. */
class BankingProviderManager
{
    private const DRIVERS = [
        'baas' => BaasVirtualAccountProvider::class,
    ];

    private ?VirtualAccountProvider $override = null;

    public function driver(): VirtualAccountProvider
    {
        if ($this->override) {
            return $this->override;
        }

        $key = config('psp.banking.provider', 'baas');
        $class = self::DRIVERS[$key] ?? throw new RuntimeException("Unknown banking provider [{$key}].");

        return new $class(config('psp.banking'));
    }

    public function fake(?FakeBankingProvider $fake = null): FakeBankingProvider
    {
        return $this->override = $fake ?? new FakeBankingProvider;
    }
}
