<?php

namespace App\Providers\Crypto\Fake;

use App\Models\CryptoDeposit;
use App\Providers\Crypto\Contracts\CryptoAddress;
use App\Providers\Crypto\Contracts\CryptoDepositRequest;
use App\Providers\Crypto\Contracts\CryptoProvider;
use App\Providers\Crypto\DepositEvaluation;
use App\Providers\MobileMoney\Contracts\ProviderResult;
use App\Providers\MobileMoney\Contracts\ProviderStatus;

/**
 * In-memory crypto provider for tests/local. `poll` can simulate the funds
 * arriving on-chain so the full deposit lifecycle runs without a real watcher.
 */
class FakeCryptoProvider implements CryptoProvider
{
    /** @var list<array{method:string,reference:string}> */
    public array $calls = [];

    public bool $fundsArriveOnPoll = true;

    public function name(): string
    {
        return 'fake_crypto';
    }

    public function createDeposit(CryptoDepositRequest $request): CryptoAddress
    {
        $this->calls[] = ['method' => 'createDeposit', 'reference' => $request->reference];

        return new CryptoAddress(
            address: 'FAKE-'.strtoupper($request->chain).'-'.substr(sha1($request->reference), 0, 24),
            requiredConfirmations: 1,
            memo: $request->reference,
            expiresAt: now()->addHour(),
        );
    }

    public function poll(CryptoDeposit $deposit): ProviderResult
    {
        $this->calls[] = ['method' => 'poll', 'reference' => $deposit->transaction_id];

        if ($this->fundsArriveOnPoll) {
            // Simulate the watcher having seen a fully-confirmed on-chain payment.
            $deposit->forceFill([
                'amount_received_minor' => $deposit->amount_expected_minor,
                'confirmations' => $deposit->required_confirmations,
                'tx_hash' => '0xFAKE'.substr(sha1($deposit->id), 0, 20),
            ])->save();

            return DepositEvaluation::of($deposit->refresh());
        }

        return ProviderResult::accepted(ProviderStatus::Pending);
    }
}
