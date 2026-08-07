<?php

use App\Http\Controllers\Api\V1\BalanceController;
use App\Http\Controllers\Api\V1\CollectionController;
use App\Http\Controllers\Api\V1\PayoutController;
use App\Http\Controllers\Api\V1\TransactionController;
use App\Http\Controllers\Webhooks\MtnMomoCallbackController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Merchant API (v1)
|--------------------------------------------------------------------------
| Authenticated with a secret API key: `Authorization: Bearer sk_test_...`.
| Mutating calls accept an `Idempotency-Key` header (required for money moves).
*/
Route::prefix('v1')->middleware('api.key')->group(function () {

    Route::get('balance', [BalanceController::class, 'show']);

    Route::get('transactions', [TransactionController::class, 'index']);
    Route::get('transactions/{transaction}', [TransactionController::class, 'show']);

    Route::middleware('idempotency')->group(function () {
        Route::post('collections', [CollectionController::class, 'store']);
        Route::post('payouts', [PayoutController::class, 'store']);
    });

    Route::get('collections/{transaction}', [CollectionController::class, 'show']);
    Route::get('payouts/{transaction}', [PayoutController::class, 'show']);
});

/*
|--------------------------------------------------------------------------
| Provider callbacks (inbound, unauthenticated but verified per-provider)
|--------------------------------------------------------------------------
| MTN MoMo PUTs/POSTs the final transaction status to these URLs. They are
| public but every payload is verified and matched to a known reference.
*/
Route::match(['post', 'put'], 'webhooks/mtn-momo/collection/{reference}', [MtnMomoCallbackController::class, 'collection'])
    ->name('webhooks.mtn.collection');
Route::match(['post', 'put'], 'webhooks/mtn-momo/disbursement/{reference}', [MtnMomoCallbackController::class, 'disbursement'])
    ->name('webhooks.mtn.disbursement');
