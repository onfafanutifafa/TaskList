<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Ledger\LedgerService;
use App\Services\Transactions\PayoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BalanceController extends Controller
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly PayoutService $payouts,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $merchant = $this->merchant($request);

        // Every currency the merchant has ever transacted in, plus their default.
        $currencies = $merchant->ledgerAccounts()
            ->where('kind', 'merchant_payable')
            ->pluck('currency')
            ->push($merchant->default_currency)
            ->unique()
            ->values();

        $balances = $currencies->map(function (string $currency) use ($merchant) {
            $settled = $this->ledger->merchantBalance($merchant, $currency);
            $available = $this->payouts->availableBalance($merchant, $currency);

            return [
                'currency' => $currency,
                'settled' => $settled->minor,
                'available' => $available->minor,
                'settled_display' => $settled->toMajorString(),
                'available_display' => $available->toMajorString(),
            ];
        });

        return response()->json(['data' => $balances]);
    }
}
