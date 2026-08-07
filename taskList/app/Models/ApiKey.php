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
        'merchant_id', 'name', 'mode', 'abilities', 'display', 'last_four', 'key_hash', 'last_used_at',
    ];

    protected $hidden = ['key_hash'];

    protected function casts(): array
    {
        return [
            'mode' => ApiKeyMode::class,
            'abilities' => 'array',
            'last_used_at' => 'datetime',
        ];
    }

    /** NULL abilities or a "*" entry means full access. */
    public function hasAbility(string $ability): bool
    {
        $abilities = $this->abilities;

        return $abilities === null
            || in_array('*', $abilities, true)
            || in_array($ability, $abilities, true);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * Mint a new secret key for a merchant. Returns [ApiKey $model, string $plaintext].
     * The plaintext is shown to the merchant exactly once and never stored.
     */
    /**
     * @param  array<int,string>|null  $abilities  null = full access; else a scope allow-list
     */
    public static function issue(Merchant $merchant, ApiKeyMode $mode, ?string $name = null, ?array $abilities = null): array
    {
        $secret = $mode->prefix().Str::random(40);

        $model = static::create([
            'merchant_id' => $merchant->id,
            'name' => $name,
            'mode' => $mode,
            'abilities' => $abilities,
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
