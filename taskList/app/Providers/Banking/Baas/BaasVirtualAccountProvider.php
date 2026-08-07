<?php

namespace App\Providers\Banking\Baas;

use App\Exceptions\ProviderException;
use App\Providers\Banking\Contracts\VirtualAccountDetails;
use App\Providers\Banking\Contracts\VirtualAccountProvider;
use App\Providers\Banking\Contracts\VirtualAccountRequest;

/**
 * Stand-in for a banking-as-a-service partner (e.g. an issuing bank API). It
 * derives stable, plausible account coordinates from the merchant + currency; a
 * production build replaces this with the partner's account-issuance API call.
 * Rail + bank name come from config.
 */
class BaasVirtualAccountProvider implements VirtualAccountProvider
{
    /** @param array<string,mixed> $config the `psp.banking` block */
    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return 'baas';
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
