<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VirtualAccount extends Model
{
    use HasUuids;

    protected $fillable = [
        'merchant_id', 'currency', 'provider', 'status', 'rail',
        'account_name', 'account_number', 'bank_name',
        'routing_number', 'sort_code', 'iban', 'swift_bic', 'provider_reference',
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
