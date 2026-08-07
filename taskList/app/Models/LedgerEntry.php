<?php

namespace App\Models;

use App\Enums\LedgerDirection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LedgerEntry extends Model
{
    public $timestamps = false; // append-only; created_at set by the DB default

    protected $fillable = [
        'journal_id', 'account_id', 'transaction_id',
        'direction', 'amount_minor', 'currency', 'narration', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'direction' => LedgerDirection::class,
            'amount_minor' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'account_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
