<?php

namespace App\Services\Ledger;

use App\Enums\AccountType;
use App\Models\LedgerAccount;
use App\Models\Merchant;

/**
 * Resolves (creating on first use) the ledger accounts the PSP posts against.
 * System accounts have no merchant; each merchant has one payable account per currency.
 */
class AccountResolver
{
    /** Liability: money the PSP is holding on behalf of this merchant. */
    public function merchantPayable(Merchant $merchant, string $currency): LedgerAccount
    {
        return LedgerAccount::firstOrCreate(
            ['merchant_id' => $merchant->id, 'kind' => 'merchant_payable', 'currency' => $currency],
            ['type' => AccountType::Liability, 'name' => "Payable — {$merchant->name} ({$currency})"],
        );
    }

    /** Asset: cash sitting in the mobile-money provider float/clearing account. */
    public function momoFloat(string $currency): LedgerAccount
    {
        return $this->system('momo_float', AccountType::Asset, "MoMo Float ({$currency})", $currency);
    }

    /** Asset: stablecoins held in the platform's on-chain receiving wallets. */
    public function cryptoFloat(string $asset): LedgerAccount
    {
        return $this->system('crypto_float', AccountType::Asset, "Crypto Float ({$asset})", $asset);
    }

    /** Revenue: fees the PSP earns. */
    public function feeRevenue(string $currency): LedgerAccount
    {
        return $this->system('fee_revenue', AccountType::Revenue, "Fee Revenue ({$currency})", $currency);
    }

    /** Expense: provider costs / write-offs. */
    public function providerExpense(string $currency): LedgerAccount
    {
        return $this->system('provider_expense', AccountType::Expense, "Provider Expense ({$currency})", $currency);
    }

    private function system(string $kind, AccountType $type, string $name, string $currency): LedgerAccount
    {
        return LedgerAccount::firstOrCreate(
            ['merchant_id' => null, 'kind' => $kind, 'currency' => $currency],
            ['type' => $type, 'name' => $name],
        );
    }
}
