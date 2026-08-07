<?php

namespace App\Providers\Crypto\Watcher;

use App\Exceptions\ProviderException;
use App\Models\CryptoDeposit;
use App\Providers\Crypto\Contracts\CryptoAddress;
use App\Providers\Crypto\Contracts\CryptoDepositRequest;
use App\Providers\Crypto\Contracts\CryptoProvider;
use App\Providers\Crypto\DepositEvaluation;
use App\Providers\MobileMoney\Contracts\ProviderResult;

/**
 * Assigns deposit addresses from configured receiving wallets and relies on an
 * off-box chain watcher to report payments via the signed webhook. `poll` re-
 * evaluates the stored state (a production provider would instead query a chain
 * explorer here). Reference is used as the memo/tag so payments to a shared
 * address can be attributed to the right deposit.
 */
class WatcherCryptoProvider implements CryptoProvider
{
    /** @param array<string,mixed> $config the `psp.crypto` block */
    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return 'watcher';
    }

    public function createDeposit(CryptoDepositRequest $request): CryptoAddress
    {
        $chainConfig = $this->config['assets'][$request->asset][$request->chain] ?? null;

        if (! $chainConfig || empty($chainConfig['address'])) {
            throw new ProviderException(
                "No receiving address configured for {$request->asset} on {$request->chain}."
            );
        }

        return new CryptoAddress(
            address: $chainConfig['address'],
            requiredConfirmations: (int) ($chainConfig['confirmations'] ?? 12),
            memo: $request->reference,
            expiresAt: now()->addMinutes((int) ($this->config['deposit_ttl_minutes'] ?? 60)),
        );
    }

    public function poll(CryptoDeposit $deposit): ProviderResult
    {
        return DepositEvaluation::of($deposit);
    }
}
