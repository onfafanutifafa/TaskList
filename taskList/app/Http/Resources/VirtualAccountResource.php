<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\VirtualAccount */
class VirtualAccountResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'currency' => $this->currency,
            'status' => $this->status,
            'rail' => $this->rail,
            'account_name' => $this->account_name,
            'bank_name' => $this->bank_name,
            'account_number' => $this->account_number,
            'routing_number' => $this->routing_number,
            'sort_code' => $this->sort_code,
            'iban' => $this->iban,
            'swift_bic' => $this->swift_bic,
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
