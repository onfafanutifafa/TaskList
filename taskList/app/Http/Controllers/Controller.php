<?php

namespace App\Http\Controllers;

use App\Models\Merchant;
use App\Models\Transaction;
use Illuminate\Http\Request;

abstract class Controller
{
    /** The merchant authenticated by the api.key middleware. */
    protected function merchant(Request $request): Merchant
    {
        return $request->attributes->get('merchant');
    }

    /** 404 (never 403 — don't leak existence) if the transaction isn't this merchant's. */
    protected function ownedOrFail(Transaction $transaction, Request $request): Transaction
    {
        abort_unless($transaction->merchant_id === $this->merchant($request)->id, 404, 'Transaction not found.');

        return $transaction;
    }
}
