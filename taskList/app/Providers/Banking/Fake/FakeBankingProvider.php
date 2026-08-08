<?php

namespace App\Providers\Banking\Fake;

use App\Providers\Banking\Contracts\BankingProvider;
use App\Providers\Banking\Contracts\BankPayoutRequest;
use App\Providers\Banking\Contracts\VirtualAccountDetails;
use App\Providers\Banking\Contracts\VirtualAccountRequest;
use App\Providers\MobileMoney\Contracts\ProviderResult;
use App\Providers\MobileMoney\Contracts\ProviderStatus;

/** In-memory banking provider for tests. */
class FakeBankingProvider implements BankingProvider
{
    /** @var list<array{method:string,currency?:string,reference?:string}> */
    public array $calls = [];

    public bool $payoutInitiationSucceeds = true;

    public ProviderStatus $payoutResolvesTo = ProviderStatus::Successful;

    public function name(): string
    {
        return 'fake_bank';
    }

    public function payout(BankPayoutRequest $request): ProviderResult
    {
        $this->calls[] = ['method' => 'payout', 'reference' => $request->reference];

        return $this->payoutInitiationSucceeds
            ? ProviderResult::accepted(ProviderStatus::Pending)
            : ProviderResult::failed('rejected', 'Bank rejected the payout (fake).');
    }

    public function payoutStatus(string $reference): ProviderResult
    {
        $this->calls[] = ['method' => 'payoutStatus', 'reference' => $reference];

        return new ProviderResult(
            accepted: true,
            status: $this->payoutResolvesTo,
            providerReference: 'FAKEPO-'.substr($reference, 0, 8),
            failureCode: $this->payoutResolvesTo === ProviderStatus::Failed ? 'declined' : null,
            failureReason: $this->payoutResolvesTo === ProviderStatus::Failed ? 'Payout declined (fake).' : null,
        );
    }

    public function createAccount(VirtualAccountRequest $request): VirtualAccountDetails
    {
        $this->calls[] = ['method' => 'createAccount', 'currency' => $request->currency];

        return new VirtualAccountDetails(
            accountName: $request->accountName,
            bankName: 'Fake Bank',
            rail: 'test',
            accountNumber: '000'.substr(sha1($request->merchantId.$request->currency), 0, 7),
            providerReference: 'va_fake_'.substr(sha1($request->merchantId.$request->currency), 0, 12),
        );
    }
}
