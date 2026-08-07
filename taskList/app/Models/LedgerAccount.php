<?php

namespace App\Models;

use App\Enums\AccountType;
use App\Enums\LedgerDirection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LedgerAccount extends Model
{
    use HasUuids;

    protected $fillable = ['merchant_id', 'type', 'kind', 'currency', 'name'];

    protected function casts(): array
    {
        return ['type' => AccountType::class];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class, 'account_id');
    }

    public function normalBalance(): LedgerDirection
    {
        return $this->type->normalBalance();
    }
}
