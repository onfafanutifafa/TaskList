<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CryptoDeposit extends Model
{
    use HasUuids;

    protected $fillable = [
        'transaction_id', 'asset', 'chain', 'address', 'memo',
        'amount_expected_minor', 'amount_received_minor',
        'confirmations', 'required_confirmations', 'tx_hash', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_expected_minor' => 'integer',
            'amount_received_minor' => 'integer',
            'confirmations' => 'integer',
            'required_confirmations' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function expectedAmount(): Money
    {
        return new Money($this->amount_expected_minor, $this->asset);
    }

    public function receivedAmount(): Money
    {
        return new Money($this->amount_received_minor, $this->asset);
    }

    /** Enough confirmations AND at least the expected amount received. */
    public function isConfirmed(): bool
    {
        return $this->confirmations >= $this->required_confirmations
            && $this->amount_received_minor >= $this->amount_expected_minor
            && $this->amount_expected_minor > 0;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
