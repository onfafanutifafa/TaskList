<?php

namespace App\Services\Transactions;

use App\Models\BalanceReservation;
use App\Models\Merchant;
use App\Services\Ledger\LedgerService;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * The spend authority. A merchant's spendable balance is the settled ledger
 * balance minus funds held for in-flight (not-yet-settled) debits. Holds are
 * placed with {@see reserve()}, which SELECT ... FOR UPDATEs the per-(merchant,
 * currency) reservation row so concurrent payouts/conversions serialise and can
 * never oversell the same balance. reserve()/release() MUST run inside a DB
 * transaction — the row lock is held until that transaction commits.
 */
class BalanceService
{
    public function __construct(private readonly LedgerService $ledger) {}

    /** Settled balance the PSP owes the merchant (from the ledger). */
    public function settled(Merchant $merchant, string $currency): Money
    {
        return $this->ledger->merchantBalance($merchant, $currency);
    }

    /** Funds currently held for in-flight debits. */
    public function reserved(Merchant $merchant, string $currency): Money
    {
        $minor = (int) BalanceReservation::where('merchant_id', $merchant->id)
            ->where('currency', $currency)
            ->value('reserved_minor');

        return new Money($minor, $currency);
    }

    /** Spendable balance: settled minus reserved. */
    public function available(Merchant $merchant, string $currency): Money
    {
        return $this->settled($merchant, $currency)->subtract($this->reserved($merchant, $currency));
    }

    /**
     * Atomically hold funds for a debit. Locks the reservation row, re-checks the
     * available balance under the lock, and raises the hold — or throws 422 if the
     * balance can't cover it. Two concurrent calls serialise on the row lock, so
     * they cannot both pass the check.
     */
    public function reserve(Merchant $merchant, Money $amount): void
    {
        $row = $this->lockRow($merchant, $amount->currency);

        $available = $this->settled($merchant, $amount->currency)
            ->subtract(new Money($row->reserved_minor, $amount->currency));

        if ($amount->isGreaterThan($available)) {
            throw new UnprocessableEntityHttpException(
                "Insufficient {$amount->currency} balance: need {$amount->toMajorString()}, ".
                "available {$available->toMajorString()}."
            );
        }

        $row->reserved_minor += $amount->minor;
        $row->save();
    }

    /** Release a previously-placed hold (on settlement or failure). Never goes negative. */
    public function release(Merchant $merchant, Money $amount): void
    {
        $row = $this->lockRow($merchant, $amount->currency);

        $row->reserved_minor = max(0, $row->reserved_minor - $amount->minor);
        $row->save();
    }

    /** Fetch the reservation row FOR UPDATE, creating it once if absent. */
    private function lockRow(Merchant $merchant, string $currency): BalanceReservation
    {
        $query = fn () => BalanceReservation::where('merchant_id', $merchant->id)
            ->where('currency', $currency)
            ->lockForUpdate()
            ->first();

        if ($row = $query()) {
            return $row;
        }

        try {
            BalanceReservation::create([
                'merchant_id' => $merchant->id,
                'currency' => $currency,
                'reserved_minor' => 0,
            ]);
        } catch (QueryException $e) {
            // Lost the create race to a concurrent request — the row now exists.
        }

        return $query();
    }
}
