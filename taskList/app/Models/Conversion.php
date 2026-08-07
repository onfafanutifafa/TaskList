<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Conversion extends Model
{
    use HasUuids;

    protected $fillable = [
        'merchant_id', 'from_currency', 'to_currency',
        'from_amount_minor', 'to_amount_minor', 'spread_minor',
        'rate', 'spread_bps', 'reference', 'status', 'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'from_amount_minor' => 'integer',
            'to_amount_minor' => 'integer',
            'spread_minor' => 'integer',
            'spread_bps' => 'integer',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
