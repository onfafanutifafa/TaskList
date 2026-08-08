<?php

namespace App\Providers\Banking\Baas;

use App\Exceptions\ProviderException;
use App\Providers\Banking\Contracts\BankingProvider;
use App\Providers\Banking\Contracts\BankPayoutRequest;
use App\Providers\Banking\Contracts\VirtualAccountDetails;
use App\Providers\Banking\Contracts\VirtualAccountRequest;
use App\Providers\MobileMoney\Contracts\ProviderResult;
use App\Providers\MobileMoney\Contracts\ProviderStatus;

/**
 * Stand-in for a banking-as-a-service partner (e.g. an issuing bank API). It
 * issues virtual account coordinates and accepts outbound wires; a production
 * build replaces both with the partner's real API. Rail + bank name from config.
 */
class BaasVirtualAccountProvider implements BankingProvider
{
    /** @param array<string,mixed> $config the `psp.banking` block */
    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return 'baas';
    }

    public function payout(BankPayoutRequest $request): ProviderResult
    {
        // A real driver submits the wire here and returns the partner's payout id.
        return ProviderResult::accepted(ProviderStatus::Pending, ['submitted' => true]);
    }

    public function payoutStatus(string $reference): ProviderResult
    {
        // Stub: a real driver queries the partner. Treat as settled once submitted.
        return new ProviderResult(
            accepted: true,
            status: ProviderStatus::Successful,
            providerReference: 'baaspo_'.substr(sha1($reference), 0, 20),
        );
    }

    public function createAccount(VirtualAccountRequest $request): VirtualAccountDetails
    {
        $currency = strtoupper($request->currency);
        $rail = $this->config['rails'][$currency] ?? throw new ProviderException("No banking rail configured for {$currency}.");
        $seed = $request->merchantId.$currency;

        return match ($currency) {
            'USD' => new VirtualAccountDetails(
                accountName: $request->accountName,
                bankName: $rail['bank_name'],
                rail: $rail['rail'],
                accountNumber: $this->digits($seed.'acct', 10),
                routingNumber: $this->digits($seed.'aba', 9),
                swiftBic: 'NODEUS33',
                providerReference: 'va_'.substr(sha1($seed), 0, 20),
            ),
            'GBP' => new VirtualAccountDetails(
                accountName: $request->accountName,
                bankName: $rail['bank_name'],
                rail: $rail['rail'],
                accountNumber: $this->digits($seed.'acct', 8),
                sortCode: $this->digits($seed.'sort', 6),
                swiftBic: 'NODEGB22',
                providerReference: 'va_'.substr(sha1($seed), 0, 20),
            ),
            'EUR' => new VirtualAccountDetails(
                accountName: $request->accountName,
                bankName: $rail['bank_name'],
                rail: $rail['rail'],
                iban: 'DE'.$this->digits($seed.'iban', 20),
                swiftBic: 'NODEDEFF',
                providerReference: 'va_'.substr(sha1($seed), 0, 20),
            ),
            default => throw new ProviderException("Unsupported virtual-account currency [{$currency}]."),
        };
    }

    /** Deterministic decimal string of length $len derived from $seed. */
    private function digits(string $seed, int $len): string
    {
        $hex = hash('sha256', $seed);
        $out = '';
        for ($i = 0; strlen($out) < $len; $i++) {
            $out .= (string) (hexdec($hex[$i % strlen($hex)]) % 10);
        }

        return substr($out, 0, $len);
    }
}
