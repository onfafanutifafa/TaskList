<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ProviderCallback extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'provider', 'product', 'reference', 'payload',
        'signature_valid', 'processed_at', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'signature_valid' => 'boolean',
            'processed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
