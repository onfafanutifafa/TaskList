<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Http\Controllers\Controller;
use App\Http\Resources\TransactionResource;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class TransactionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'type' => ['sometimes', Rule::enum(TransactionType::class)],
            'status' => ['sometimes', Rule::enum(TransactionStatus::class)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $this->merchant($request)->transactions()->latest();

        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return TransactionResource::collection(
            $query->paginate($filters['per_page'] ?? 25)->withQueryString()
        );
    }

    public function show(Request $request, Transaction $transaction): TransactionResource
    {
        return new TransactionResource($this->ownedOrFail($transaction, $request));
    }
}
