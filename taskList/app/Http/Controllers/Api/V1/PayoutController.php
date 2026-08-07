<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TransactionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateTransactionRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Transaction;
use App\Services\Transactions\PayoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PayoutController extends Controller
{
    public function __construct(private readonly PayoutService $payouts) {}

    public function store(CreateTransactionRequest $request): JsonResponse
    {
        $transaction = $this->payouts->initiate(
            $this->merchant($request),
            $request->validated(),
            $request->attributes->get('idempotency_key'),
        );

        return (new TransactionResource($transaction))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, Transaction $transaction): TransactionResource
    {
        $this->ownedOrFail($transaction, $request);
        abort_unless($transaction->type === TransactionType::Payout, 404, 'Transaction not found.');

        return new TransactionResource($transaction);
    }
}
