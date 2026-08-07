<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TransactionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateCryptoDepositRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Transaction;
use App\Services\Transactions\CryptoDepositService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CryptoDepositController extends Controller
{
    public function __construct(private readonly CryptoDepositService $deposits) {}

    public function store(CreateCryptoDepositRequest $request): JsonResponse
    {
        $transaction = $this->deposits->initiate(
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
        abort_unless($transaction->type === TransactionType::CryptoDeposit, 404, 'Transaction not found.');

        return new TransactionResource($transaction->load('cryptoDeposit'));
    }
}
