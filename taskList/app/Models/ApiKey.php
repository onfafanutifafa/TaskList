<?php

namespace App\Models;

use App\Enums\ApiKeyMode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ApiKey extends Model
{
    use HasUuids;

    protected $fillable = [
        'merchant_id', 'name', 'mode', 'display', 'last_four', 'key_hash', 'last_used_at',
    ];

    protected $hidden = ['key_hash'];

    protected function casts(): array
    {
        return [
            'mode' => ApiKeyMode::class,
            'last_used_at' => 'datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * Mint a new secret key for a merchant. Returns [ApiKey $model, string $plaintext].
     * The plaintext is shown to the merchant exactly once and never stored.
     */
    public static function issue(Merchant $merchant, ApiKeyMode $mode, ?string $name = null): array
    {
        $secret = $mode->prefix().Str::random(40);

        $model = static::create([
            'merchant_id' => $merchant->id,
            'name' => $name,
            'mode' => $mode,
            'display' => substr($secret, 0, strlen($mode->prefix()) + 4).'…',
            'last_four' => substr($secret, -4),
            'key_hash' => self::hash($secret),
        ]);

        return [$model, $secret];
    }

    public static function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }
}
