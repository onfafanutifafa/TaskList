<?php

namespace App\Services\Fx;

use App\Models\Conversion;
use App\Models\Merchant;
use App\Services\Ledger\AccountResolver;
use App\Services\Ledger\JournalLeg;
use App\Services\Ledger\LedgerService;
use App\Services\Transactions\BalanceService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Converts one merchant wallet balance into another at a quoted rate, taking a
 * spread as revenue. All rate math is bcmath on decimal strings; results are
 * integer minor units. A conversion posts TWO single-currency journals joined by
 * the FX clearing accounts (a journal can't span currencies).
 */
class FxService
{
    private const SCALE = 12;

    public function __construct(
        private readonly RateProviderManager $rates,
        private readonly LedgerService $ledger,
        private readonly AccountResolver $accounts,
        private readonly BalanceService $balances,
    ) {}

    public function quote(string $from, string $to, int $fromMinor): FxQuote
    {
        $from = strtoupper($from);
        $to = strtoupper($to);

        if ($from === $to) {
            throw new UnprocessableEntityHttpException('Source and destination currency must differ.');
        }

        $fromMoney = new Money($fromMinor, $from);        // validates supported + positivity via caller
        $rate = $this->rates->driver()->rate($from, $to);

        if (bccomp($rate, '0', self::SCALE) <= 0) {
            throw new UnprocessableEntityHttpException("No exchange rate available for {$from} -> {$to}.");
        }

        $gross = new Money($this->grossToMinor($fromMinor, $from, $to, $rate), $to);
        $spreadBps = (int) config('psp.fx.spread_bps');
        $spread = $gross->feeAtBps($spreadBps);
        $net = $gross->subtract($spread);

        return new FxQuote($fromMoney, $gross, $spread, $net, $rate, $spreadBps);
    }

    public function convert(Merchant $merchant, string $from, string $to, int $fromMinor, ?string $reference = null, ?string $idempotencyKey = null): Conversion
    {
        $from = strtoupper($from);
        $to = strtoupper($to);

        if ($fromMinor <= 0) {
            throw new UnprocessableEntityHttpException('Amount must be positive.');
        }

        if ($reference && $merchant->conversions()->where('reference', $reference)->exists()) {
            throw new ConflictHttpException("A conversion with reference [{$reference}] already exists.");
        }

        return DB::transaction(function () use ($merchant, $from, $to, $fromMinor, $reference, $idempotencyKey) {
            $quote = $this->quote($from, $to, $fromMinor);

            // Row-locked hold on the source balance: serialises against concurrent
            // spends and throws 422 if the balance can't cover it. The conversion
            // settles synchronously below, so the hold is released in the same txn.
            $this->balances->reserve($merchant, $quote->from);

            // Journal 1 (source currency): move funds out of the merchant into clearing.
            $this->ledger->post([
                JournalLeg::debit($this->accounts->merchantPayable($merchant, $from), $quote->from),
                JournalLeg::credit($this->accounts->fxClearing($from), $quote->from),
            ], null, "FX out {$from}->{$to}");

            $this->balances->release($merchant, $quote->from);

            // Journal 2 (destination currency): clearing funds the merchant net + spread revenue.
            $legs = [
                JournalLeg::debit($this->accounts->fxClearing($to), $quote->gross),
                JournalLeg::credit($this->accounts->merchantPayable($merchant, $to), $quote->net),
            ];
            if ($quote->spread->isPositive()) {
                $legs[] = JournalLeg::credit($this->accounts->fxRevenue($to), $quote->spread);
            }
            $this->ledger->post($legs, null, "FX in {$from}->{$to}");

            return $merchant->conversions()->create([
                'from_currency' => $from,
                'to_currency' => $to,
                'from_amount_minor' => $quote->from->minor,
                'to_amount_minor' => $quote->net->minor,
                'spread_minor' => $quote->spread->minor,
                'rate' => $quote->rate,
                'spread_bps' => $quote->spreadBps,
                'reference' => $reference,
                'status' => 'completed',
                'idempotency_key' => $idempotencyKey,
            ]);
        });
    }

    /** Gross destination minor units for a source amount, before spread. */
    private function grossToMinor(int $fromMinor, string $from, string $to, string $rate): int
    {
        $fromFactor = (string) config("psp.currencies.minor_units.{$from}");
        $toFactor = (string) config("psp.currencies.minor_units.{$to}");

        // (fromMinor * rate * toFactor) / fromFactor, rounded half-up to an integer.
        $value = bcmul((string) $fromMinor, $rate, self::SCALE);
        $value = bcmul($value, $toFactor, self::SCALE);
        $value = bcdiv($value, $fromFactor, self::SCALE);

        return (int) bcadd($value, '0.5', 0); // half-up for positive amounts
    }
}
