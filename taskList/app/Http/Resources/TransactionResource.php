<?php

namespace App\Http\Resources;

use App\Support\TransactionPayload;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Transaction */
class TransactionResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return TransactionPayload::make($this->resource);
    }
}
