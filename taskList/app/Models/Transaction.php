<?php

namespace App\Models;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Support\Money;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Transaction extends Model
{
    use HasUuids;

    protected $fillable = [
        'merchant_id', 'type', 'status', 'amount_minor', 'fee_minor', 'currency',
        'provider', 'network', 'msisdn', 'reference', 'provider_reference',
        'narration', 'failure_code', 'failure_reason', 'idempotency_key', 'meta',
        'authorized_at', 'succeeded_at', 'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'status' => TransactionStatus::class,
            'amount_minor' => 'integer',
            'fee_minor' => 'integer',
            'meta' => 'array',
            'authorized_at' => 'datetime',
            'succeeded_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function cryptoDeposit(): HasOne
    {
        return $this->hasOne(CryptoDeposit::class);
    }

    public function amount(): Money
    {
        return new Money($this->amount_minor, $this->currency);
    }

    public function fee(): Money
    {
        return new Money($this->fee_minor, $this->currency);
    }

    /** What the merchant nets on a collection (gross − fee). */
    public function netToMerchant(): Money
    {
        return $this->amount()->subtract($this->fee());
    }
}
