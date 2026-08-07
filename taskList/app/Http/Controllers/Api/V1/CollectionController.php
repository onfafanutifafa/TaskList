<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TransactionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateTransactionRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\Transactions\CollectionService;

class CollectionController extends Controller
{
    public function __construct(private readonly CollectionService $collections) {}

    public function store(CreateTransactionRequest $request): JsonResponse
    {
        $transaction = $this->collections->initiate(
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
        abort_unless($transaction->type === TransactionType::Collection, 404, 'Transaction not found.');

        return new TransactionResource($transaction);
    }
}
