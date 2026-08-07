<?php

namespace App\Providers\Crypto;

use App\Providers\Crypto\Contracts\CryptoProvider;
use App\Providers\Crypto\Fake\FakeCryptoProvider;
use App\Providers\Crypto\Watcher\WatcherCryptoProvider;
use RuntimeException;

/** Resolves the active crypto provider. Singleton so a test fake sticks. */
class CryptoProviderManager
{
    private const DRIVERS = [
        'watcher' => WatcherCryptoProvider::class,
    ];

    private ?CryptoProvider $override = null;

    public function driver(): CryptoProvider
    {
        if ($this->override) {
            return $this->override;
        }

        $key = config('psp.crypto.default_provider', 'watcher');
        $class = self::DRIVERS[$key] ?? throw new RuntimeException("Unknown crypto provider [{$key}].");

        return new $class(config('psp.crypto'));
    }

    public function fake(?FakeCryptoProvider $fake = null): FakeCryptoProvider
    {
        return $this->override = $fake ?? new FakeCryptoProvider;
    }
}
