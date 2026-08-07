<?php

namespace App\Providers\MobileMoney\Contracts;

final class ProviderResult
{
    /**
     * @param  bool  $accepted  the provider took the request (or the status call succeeded)
     * @param  array<string,mixed>  $raw  the raw provider payload, for audit
     */
    public function __construct(
        public readonly bool $accepted,
        public readonly ProviderStatus $status,
        public readonly ?string $providerReference = null,
        public readonly ?string $failureCode = null,
        public readonly ?string $failureReason = null,
        public readonly array $raw = [],
    ) {}

    public static function accepted(ProviderStatus $status = ProviderStatus::Pending, array $raw = []): self
    {
        return new self(true, $status, raw: $raw);
    }

    public static function failed(string $code, string $reason, array $raw = []): self
    {
        return new self(false, ProviderStatus::Failed, failureCode: $code, failureReason: $reason, raw: $raw);
    }
}
