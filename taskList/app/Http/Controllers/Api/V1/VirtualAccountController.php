<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateVirtualAccountRequest;
use App\Http\Resources\VirtualAccountResource;
use App\Models\VirtualAccount;
use App\Services\Transactions\VirtualAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class VirtualAccountController extends Controller
{
    public function __construct(private readonly VirtualAccountService $accounts) {}

    public function store(CreateVirtualAccountRequest $request): JsonResponse
    {
        $account = $this->accounts->open($this->merchant($request), $request->input('currency'));

        return (new VirtualAccountResource($account))->response()->setStatusCode(201);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        return VirtualAccountResource::collection(
            $this->merchant($request)->virtualAccounts()->latest()->get()
        );
    }

    public function show(Request $request, VirtualAccount $virtualAccount): VirtualAccountResource
    {
        abort_unless($virtualAccount->merchant_id === $this->merchant($request)->id, 404, 'Virtual account not found.');

        return new VirtualAccountResource($virtualAccount);
    }
}
