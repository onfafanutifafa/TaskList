<?php

namespace App\Providers\MobileMoney\Fake;

use App\Providers\MobileMoney\Contracts\MobileMoneyProvider;
use App\Providers\MobileMoney\Contracts\MoneyRequest;
use App\Providers\MobileMoney\Contracts\ProviderResult;
use App\Providers\MobileMoney\Contracts\ProviderStatus;

/**
 * In-memory provider for tests and local development — no network calls.
 * Configure how initiations and status lookups resolve, then assert on `$calls`.
 */
class FakeProvider implements MobileMoneyProvider
{
    /** @var list<array{method:string,reference:string,msisdn?:string,amount?:int}> */
    public array $calls = [];

    public bool $initiationSucceeds = true;

    public ProviderStatus $resolvesTo = ProviderStatus::Successful;

    public function name(): string
    {
        return 'fake';
    }

    public function collect(MoneyRequest $request): ProviderResult
    {
        return $this->initiate('collect', $request);
    }

    public function payout(MoneyRequest $request): ProviderResult
    {
        return $this->initiate('payout', $request);
    }

    public function collectionStatus(string $reference): ProviderResult
    {
        return $this->lookup('collectionStatus', $reference);
    }

    public function payoutStatus(string $reference): ProviderResult
    {
        return $this->lookup('payoutStatus', $reference);
    }

    private function initiate(string $method, MoneyRequest $request): ProviderResult
    {
        $this->calls[] = [
            'method' => $method,
            'reference' => $request->reference,
            'msisdn' => $request->msisdn,
            'amount' => $request->amount->minor,
        ];

        return $this->initiationSucceeds
            ? ProviderResult::accepted(ProviderStatus::Pending)
            : ProviderResult::failed('rejected', 'Provider rejected the request (fake).');
    }

    private function lookup(string $method, string $reference): ProviderResult
    {
        $this->calls[] = ['method' => $method, 'reference' => $reference];

        return new ProviderResult(
            accepted: true,
            status: $this->resolvesTo,
            providerReference: 'FAKE-'.substr($reference, 0, 8),
            failureCode: $this->resolvesTo === ProviderStatus::Failed ? 'declined' : null,
            failureReason: $this->resolvesTo === ProviderStatus::Failed ? 'Payer declined (fake).' : null,
        );
    }
}
