<?php

namespace App\Providers\MobileMoney\Mtn;

use App\Exceptions\ProviderException;
use App\Providers\MobileMoney\Contracts\MobileMoneyProvider;
use App\Providers\MobileMoney\Contracts\MoneyRequest;
use App\Providers\MobileMoney\Contracts\ProviderResult;
use App\Providers\MobileMoney\Contracts\ProviderStatus;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * MTN MoMo Open API driver — Collections (request-to-pay) and Disbursements
 * (transfer). Reference: https://momodeveloper.mtn.com
 *
 * The transaction's own id is sent as MTN's `X-Reference-Id`, so re-sending the
 * same request is naturally idempotent on MTN's side. Status is authoritative
 * via GET; the callback (see webhooks) is only a nudge to poll sooner.
 */
class MtnMomoProvider implements MobileMoneyProvider
{
    /** @param array<string,mixed> $config the `psp.providers.mtn_momo` block */
    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return 'mtn_momo';
    }

    public function collect(MoneyRequest $request): ProviderResult
    {
        $response = $this->client('collection')
            ->withHeaders($this->initiationHeaders('collection', $request->reference))
            ->post('/collection/v1_0/requesttopay', [
                'amount' => $request->amount->toMajorString(),
                'currency' => $this->currency(),
                'externalId' => $request->externalId,
                'payer' => ['partyIdType' => 'MSISDN', 'partyId' => $request->msisdn],
                'payerMessage' => $request->narration ?? 'Payment',
                'payeeNote' => $request->narration ?? 'Payment',
            ]);

        return $response->status() === 202
            ? ProviderResult::accepted(ProviderStatus::Pending, ['http' => 202])
            : ProviderResult::failed('initiation_failed', $this->errorMessage($response), $response->json() ?? []);
    }

    public function payout(MoneyRequest $request): ProviderResult
    {
        $response = $this->client('disbursement')
            ->withHeaders($this->initiationHeaders('disbursement', $request->reference))
            ->post('/disbursement/v1_0/transfer', [
                'amount' => $request->amount->toMajorString(),
                'currency' => $this->currency(),
                'externalId' => $request->externalId,
                'payee' => ['partyIdType' => 'MSISDN', 'partyId' => $request->msisdn],
                'payerMessage' => $request->narration ?? 'Payout',
                'payeeNote' => $request->narration ?? 'Payout',
            ]);

        return $response->status() === 202
            ? ProviderResult::accepted(ProviderStatus::Pending, ['http' => 202])
            : ProviderResult::failed('initiation_failed', $this->errorMessage($response), $response->json() ?? []);
    }

    public function collectionStatus(string $reference): ProviderResult
    {
        return $this->status('collection', "/collection/v1_0/requesttopay/{$reference}");
    }

    public function payoutStatus(string $reference): ProviderResult
    {
        return $this->status('disbursement', "/disbursement/v1_0/transfer/{$reference}");
    }

    private function status(string $product, string $path): ProviderResult
    {
        $response = $this->client($product)
            ->withHeaders(['Ocp-Apim-Subscription-Key' => $this->subscriptionKey($product)])
            ->get($path);

        if (! $response->successful()) {
            return ProviderResult::failed('status_unavailable', $this->errorMessage($response), $response->json() ?? []);
        }

        $body = $response->json();
        $status = match (strtoupper((string) ($body['status'] ?? ''))) {
            'SUCCESSFUL' => ProviderStatus::Successful,
            'FAILED' => ProviderStatus::Failed,
            default => ProviderStatus::Pending,
        };

        return new ProviderResult(
            accepted: true,
            status: $status,
            providerReference: $body['financialTransactionId'] ?? null,
            failureCode: $status === ProviderStatus::Failed ? ($body['reason'] ?? 'failed') : null,
            failureReason: $status === ProviderStatus::Failed ? (string) ($body['reason'] ?? 'Transaction failed') : null,
            raw: $body ?? [],
        );
    }

    // --- HTTP plumbing -----------------------------------------------------

    private function client(string $product): PendingRequest
    {
        return Http::baseUrl(rtrim($this->config['base_url'], '/'))
            ->acceptJson()
            ->timeout(30)
            ->withToken($this->token($product));
    }

    /** @return array<string,string> */
    private function initiationHeaders(string $product, string $reference): array
    {
        $headers = [
            'X-Reference-Id' => $reference,
            'X-Target-Environment' => $this->config['environment'],
            'Ocp-Apim-Subscription-Key' => $this->subscriptionKey($product),
        ];

        if (! empty($this->config['callback_url'])) {
            $headers['X-Callback-Url'] = rtrim($this->config['callback_url'], '/').'/'.$product.'/'.$reference;
        }

        return $headers;
    }

    /** OAuth-style bearer token, cached until shortly before it expires. */
    private function token(string $product): string
    {
        return Cache::remember("mtn_momo:{$product}:token", now()->addMinutes(50), function () use ($product) {
            $cfg = $this->productConfig($product);

            $response = Http::baseUrl(rtrim($this->config['base_url'], '/'))
                ->withBasicAuth($cfg['api_user'] ?? '', $cfg['api_key'] ?? '')
                ->withHeaders(['Ocp-Apim-Subscription-Key' => $cfg['subscription_key'] ?? ''])
                ->timeout(30)
                ->post("/{$product}/token/");

            if (! $response->successful() || empty($response->json('access_token'))) {
                throw new ProviderException("MTN MoMo {$product} token request failed: ".$this->errorMessage($response));
            }

            return $response->json('access_token');
        });
    }

    private function currency(): string
    {
        return $this->config['currency'] ?? config('psp.currencies.default');
    }

    private function subscriptionKey(string $product): string
    {
        return (string) ($this->productConfig($product)['subscription_key'] ?? '');
    }

    /** @return array<string,mixed> */
    private function productConfig(string $product): array
    {
        return $this->config[$product] ?? throw new RuntimeException("Unknown MTN product [{$product}].");
    }

    private function errorMessage(\Illuminate\Http\Client\Response $response): string
    {
        return (string) ($response->json('message') ?? $response->json('error') ?? "HTTP {$response->status()}");
    }
}
