<?php

namespace App\Providers\Banking\Fake;

use App\Providers\Banking\Contracts\VirtualAccountDetails;
use App\Providers\Banking\Contracts\VirtualAccountProvider;
use App\Providers\Banking\Contracts\VirtualAccountRequest;

/** In-memory banking provider for tests. */
class FakeBankingProvider implements VirtualAccountProvider
{
    /** @var list<array{method:string,currency:string}> */
    public array $calls = [];

    public function name(): string
    {
        return 'fake_bank';
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
