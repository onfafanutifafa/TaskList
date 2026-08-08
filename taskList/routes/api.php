<?php

use App\Http\Controllers\Api\V1\BalanceController;
use App\Http\Controllers\Api\V1\BankPayoutController;
use App\Http\Controllers\Api\V1\CollectionController;
use App\Http\Controllers\Api\V1\CryptoDepositController;
use App\Http\Controllers\Api\V1\FxController;
use App\Http\Controllers\Api\V1\PayoutController;
use App\Http\Controllers\Api\V1\TransactionController;
use App\Http\Controllers\Api\V1\VirtualAccountController;
use App\Http\Controllers\Webhooks\BankingCallbackController;
use App\Http\Controllers\Webhooks\CryptoWatcherCallbackController;
use App\Http\Controllers\Webhooks\MtnMomoCallbackController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Merchant API (v1)
|--------------------------------------------------------------------------
| Authenticated with a secret API key: `Authorization: Bearer sk_test_...`.
| Rate-limited per key. Routes declare the scope (ability) they require, so a
| least-privilege key can be restricted to exactly what it needs. Mutating
| money calls also accept an `Idempotency-Key` header.
*/
Route::prefix('v1')->middleware(['api.key', 'throttle:api'])->group(function () {

    Route::get('balance', [BalanceController::class, 'show'])->middleware('ability:balances:read');

    Route::get('transactions', [TransactionController::class, 'index'])->middleware('ability:transactions:read');
    Route::get('transactions/{transaction}', [TransactionController::class, 'show'])->middleware('ability:transactions:read');

    Route::post('fx/quote', [FxController::class, 'quote'])->middleware('ability:fx:read');

    Route::post('virtual-accounts', [VirtualAccountController::class, 'store'])->middleware('ability:virtual_accounts:write');
    Route::get('virtual-accounts', [VirtualAccountController::class, 'index'])->middleware('ability:virtual_accounts:read');
    Route::get('virtual-accounts/{virtualAccount}', [VirtualAccountController::class, 'show'])->middleware('ability:virtual_accounts:read');

    Route::middleware('idempotency')->group(function () {
        Route::post('collections', [CollectionController::class, 'store'])->middleware('ability:collections:write');
        Route::post('payouts', [PayoutController::class, 'store'])->middleware('ability:payouts:write');
        Route::post('crypto/deposits', [CryptoDepositController::class, 'store'])->middleware('ability:crypto:write');
        Route::post('fx/conversions', [FxController::class, 'convert'])->middleware('ability:fx:write');
        Route::post('bank-payouts', [BankPayoutController::class, 'store'])->middleware('ability:bank_payouts:write');
    });

    Route::get('collections/{transaction}', [CollectionController::class, 'show'])->middleware('ability:collections:read');
    Route::get('payouts/{transaction}', [PayoutController::class, 'show'])->middleware('ability:payouts:read');
    Route::get('crypto/deposits/{transaction}', [CryptoDepositController::class, 'show'])->middleware('ability:crypto:read');
    Route::get('bank-payouts/{transaction}', [BankPayoutController::class, 'show'])->middleware('ability:bank_payouts:read');
});

/*
|--------------------------------------------------------------------------
| Provider callbacks (inbound, verified per-provider, rate-limited by IP)
|--------------------------------------------------------------------------
| MTN callbacks are re-verified via an authoritative status GET. The crypto
| watcher callback is HMAC-signed and moves money only after verification.
*/
Route::middleware('throttle:webhooks')->group(function () {
    Route::match(['post', 'put'], 'webhooks/mtn-momo/collection/{reference}', [MtnMomoCallbackController::class, 'collection'])
        ->name('webhooks.mtn.collection');
    Route::match(['post', 'put'], 'webhooks/mtn-momo/disbursement/{reference}', [MtnMomoCallbackController::class, 'disbursement'])
        ->name('webhooks.mtn.disbursement');

    Route::post('webhooks/crypto/{reference}', CryptoWatcherCallbackController::class)
        ->name('webhooks.crypto');

    Route::post('webhooks/banking/{account}', BankingCallbackController::class)
        ->name('webhooks.banking');
});
