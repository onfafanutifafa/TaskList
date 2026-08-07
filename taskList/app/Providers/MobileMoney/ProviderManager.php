<?php

namespace App\Providers\MobileMoney;

use App\Providers\MobileMoney\Contracts\MobileMoneyProvider;
use App\Providers\MobileMoney\Fake\FakeProvider;
use RuntimeException;

/**
 * Resolves a concrete {@see MobileMoneyProvider} from config. Registered as a
 * singleton so a test-time fake, once installed, is returned everywhere.
 */
class ProviderManager
{
    /** @var array<string,MobileMoneyProvider> */
    private array $overrides = [];

    public function driver(?string $key = null): MobileMoneyProvider
    {
        $key ??= config('psp.default_provider');

        if (isset($this->overrides[$key])) {
            return $this->overrides[$key];
        }

        $config = config("psp.providers.{$key}")
            ?? throw new RuntimeException("Unknown payment provider [{$key}].");

        $class = $config['driver'];

        return new $class($config);
    }

    /** Resolve the provider that serves a merchant-facing network selector (e.g. "mtn"). */
    public function forNetwork(string $network): MobileMoneyProvider
    {
        $key = config("psp.networks.{$network}")
            ?? throw new RuntimeException("Unsupported network [{$network}].");

        return $this->driver($key);
    }

    public function keyForNetwork(string $network): string
    {
        return config("psp.networks.{$network}")
            ?? throw new RuntimeException("Unsupported network [{$network}].");
    }

    /** Force every provider key to resolve to a fake. Returns it so tests can assert. */
    public function fake(?FakeProvider $fake = null): FakeProvider
    {
        $fake ??= new FakeProvider;

        foreach (array_keys(config('psp.providers')) as $key) {
            $this->overrides[$key] = $fake;
        }

        return $fake;
    }
}
