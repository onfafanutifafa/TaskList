<?php

namespace App\Services\Transactions;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Merchant;
use App\Models\Transaction;
use App\Models\VirtualAccount;
use App\Providers\Banking\BankingProviderManager;
use App\Providers\Banking\Contracts\VirtualAccountRequest;
use App\Providers\MobileMoney\Contracts\ProviderResult;
use App\Providers\MobileMoney\Contracts\ProviderStatus;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class VirtualAccountService
{
    public function __construct(
        private readonly BankingProviderManager $providers,
        private readonly TransactionReconciler $reconciler,
    ) {}

    /**
     * Open (or return the existing) virtual receiving account for a currency.
     * One account per currency per merchant, so this is safe to call repeatedly.
     */
    public function open(Merchant $merchant, string $currency): VirtualAccount
    {
        $currency = strtoupper($currency);

        if ($existing = $merchant->virtualAccounts()->where('currency', $currency)->first()) {
            return $existing;
        }

        $provider = $this->providers->driver();
        $details = $provider->createAccount(new VirtualAccountRequest(
            merchantId: $merchant->id,
            accountName: $merchant->name,
            currency: $currency,
        ));

        return $merchant->virtualAccounts()->create([
            'currency' => $currency,
            'provider' => $provider->name(),
            'status' => 'active',
            'rail' => $details->rail,
            'account_name' => $details->accountName,
            'account_number' => $details->accountNumber,
            'bank_name' => $details->bankName,
            'routing_number' => $details->routingNumber,
            'sort_code' => $details->sortCode,
            'iban' => $details->iban,
            'swift_bic' => $details->swiftBic,
            'provider_reference' => $details->providerReference,
        ]);
    }

    /**
     * Credit an incoming payment reported by the banking partner. Idempotent on
     * the partner's payment reference, so a re-delivered webhook never double-credits.
     */
    public function recordIncomingPayment(VirtualAccount $account, int $amountMinor, string $paymentReference, ?string $senderName = null): Transaction
    {
        $merchant = $account->merchant;
        $currency = $account->currency;
        $amount = new Money($amountMinor, $currency);
        $fee = $amount->feeAtBps(config('psp.fee_bps'));

        return DB::transaction(function () use ($merchant, $account, $currency, $amount, $fee, $paymentReference, $senderName) {
            $existing = $merchant->transactions()
                ->where('type', TransactionType::BankDeposit->value)
                ->where('reference', $paymentReference)
                ->first();

            if ($existing) {
                return $existing; // duplicate webhook — already credited
            }

            $transaction = $merchant->transactions()->create([
                'type' => TransactionType::BankDeposit,
                'status' => TransactionStatus::Pending,
                'amount_minor' => $amount->minor,
                'fee_minor' => $fee->minor,
                'currency' => $currency,
                'provider' => $account->provider,
                'network' => $account->rail ?? 'bank',
                'msisdn' => null,
                'reference' => $paymentReference,
                'narration' => $senderName ? "From {$senderName}" : null,
                'meta' => ['virtual_account_id' => $account->id, 'sender_name' => $senderName],
            ]);

            return $this->reconciler->apply($transaction, new ProviderResult(
                accepted: true,
                status: ProviderStatus::Successful,
                providerReference: $paymentReference,
            ));
        });
    }
}
