<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BalanceReservation extends Model
{
    use HasUuids;

    protected $fillable = ['merchant_id', 'currency', 'reserved_minor'];

    protected function casts(): array
    {
        return ['reserved_minor' => 'integer'];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
