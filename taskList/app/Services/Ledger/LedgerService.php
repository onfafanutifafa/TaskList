<?php

namespace App\Services\Ledger;

use App\Enums\LedgerDirection;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\Merchant;
use App\Models\Transaction;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class LedgerService
{
    public function __construct(private readonly AccountResolver $accounts) {}

    /**
     * Post a set of legs as one balanced journal. Debits MUST equal credits and
     * all legs must share one currency, or the whole thing throws before writing.
     *
     * @param  JournalLeg[]  $legs
     * @return string  the journal id
     */
    public function post(array $legs, ?Transaction $transaction = null, ?string $narration = null): string
    {
        if (count($legs) < 2) {
            throw new RuntimeException('A journal needs at least two legs.');
        }

        $currency = $legs[0]->amount->currency;
        $debits = 0;
        $credits = 0;

        foreach ($legs as $leg) {
            if ($leg->amount->currency !== $currency) {
                throw new RuntimeException('All legs of a journal must share one currency.');
            }
            if ($leg->amount->minor <= 0) {
                throw new RuntimeException('Leg amounts must be positive.');
            }
            $leg->direction === LedgerDirection::Debit
                ? $debits += $leg->amount->minor
                : $credits += $leg->amount->minor;
        }

        if ($debits !== $credits) {
            throw new RuntimeException("Unbalanced journal: debits {$debits} != credits {$credits}.");
        }

        $journalId = (string) Str::uuid();

        DB::transaction(function () use ($legs, $journalId, $transaction, $narration) {
            foreach ($legs as $leg) {
                LedgerEntry::create([
                    'journal_id' => $journalId,
                    'account_id' => $leg->account->id,
                    'transaction_id' => $transaction?->id,
                    'direction' => $leg->direction,
                    'amount_minor' => $leg->amount->minor,
                    'currency' => $leg->amount->currency,
                    'narration' => $narration,
                ]);
            }
        });

        return $journalId;
    }

    public function accountBalance(LedgerAccount $account): Money
    {
        $debits = (int) $account->entries()->where('direction', LedgerDirection::Debit->value)->sum('amount_minor');
        $credits = (int) $account->entries()->where('direction', LedgerDirection::Credit->value)->sum('amount_minor');

        $signed = $account->normalBalance() === LedgerDirection::Debit
            ? $debits - $credits
            : $credits - $debits;

        return new Money($signed, $account->currency);
    }

    /** The settled balance the PSP owes this merchant in $currency. */
    public function merchantBalance(Merchant $merchant, string $currency): Money
    {
        return $this->accountBalance($this->accounts->merchantPayable($merchant, $currency));
    }

    /**
     * Settle a succeeded collection: cash lands in float, the merchant is credited
     * net of fee, and the fee is booked as revenue. Idempotent per transaction.
     */
    public function recordCollectionSettlement(Transaction $transaction): void
    {
        $this->recordCreditSettlement(
            $transaction,
            $this->accounts->momoFloat($transaction->currency),
            "Collection {$transaction->reference}",
        );
    }

    /**
     * Settle a confirmed crypto deposit: stablecoins land in the crypto float,
     * the merchant is credited net of fee. Same shape as a collection, different
     * float account. Idempotent per transaction.
     */
    public function recordDepositSettlement(Transaction $transaction): void
    {
        $this->recordCreditSettlement(
            $transaction,
            $this->accounts->cryptoFloat($transaction->currency),
            "Crypto deposit {$transaction->reference}",
        );
    }

    /**
     * Settle an incoming payment to a virtual bank account: fiat lands in the bank
     * float, the merchant is credited net of fee. Idempotent per transaction.
     */
    public function recordBankDepositSettlement(Transaction $transaction): void
    {
        $this->recordCreditSettlement(
            $transaction,
            $this->accounts->bankFloat($transaction->currency),
            "Bank deposit {$transaction->reference}",
        );
    }

    /** Shared pay-in posting: debit a float asset, credit the merchant net, book the fee. */
    private function recordCreditSettlement(Transaction $transaction, LedgerAccount $float, string $narration): void
    {
        if ($this->alreadyPosted($transaction)) {
            return;
        }

        $gross = $transaction->amount();
        $fee = $transaction->fee();
        $net = $gross->subtract($fee);

        $legs = [
            JournalLeg::debit($float, $gross),
            JournalLeg::credit($this->accounts->merchantPayable($transaction->merchant, $net->currency), $net),
        ];

        if ($fee->isPositive()) {
            $legs[] = JournalLeg::credit($this->accounts->feeRevenue($fee->currency), $fee);
        }

        $this->post($legs, $transaction, $narration);
    }

    /**
     * Settle a succeeded payout: the merchant's payable is drawn down by amount+fee,
     * cash leaves the float, and any fee is booked as revenue. Idempotent per transaction.
     */
    public function recordPayoutSettlement(Transaction $transaction): void
    {
        if ($this->alreadyPosted($transaction)) {
            return;
        }

        $amount = $transaction->amount();
        $fee = $transaction->fee();
        $debitFromMerchant = $amount->add($fee);

        $legs = [
            JournalLeg::debit($this->accounts->merchantPayable($transaction->merchant, $debitFromMerchant->currency), $debitFromMerchant),
            JournalLeg::credit($this->accounts->momoFloat($amount->currency), $amount),
        ];

        if ($fee->isPositive()) {
            $legs[] = JournalLeg::credit($this->accounts->feeRevenue($fee->currency), $fee);
        }

        $this->post($legs, $transaction, "Payout {$transaction->reference}");
    }

    private function alreadyPosted(Transaction $transaction): bool
    {
        return $transaction->ledgerEntries()->exists();
    }
}
