<?php

namespace Tests\Feature;

use App\Enums\ApiKeyMode;
use App\Models\ApiKey;
use App\Models\Merchant;
use App\Providers\MobileMoney\ProviderManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ProviderManager::class)->fake();
    }

    /** A scoped key can be issued with a restricted ability set. */
    private function scopedKey(array $abilities): array
    {
        $merchant = Merchant::factory()->create();
        [, $secret] = ApiKey::issue($merchant, ApiKeyMode::Test, 'scoped', $abilities);

        return [$merchant, ['Authorization' => "Bearer {$secret}"]];
    }

    private function collectionPayload(): array
    {
        return [
            'amount' => 1050, 'currency' => 'GHS', 'phone' => '233240000000',
            'network' => 'mtn', 'reference' => 'sec-1',
        ];
    }

    public function test_a_read_only_key_cannot_move_money(): void
    {
        [, $headers] = $this->scopedKey(['balances:read', 'transactions:read']);

        $this->postJson('/v1/collections', $this->collectionPayload(), $headers)
            ->assertStatus(403)
            ->assertJsonPath('error.type', 'api_error');

        // ...but it can still read.
        $this->getJson('/v1/balance', $headers)->assertStatus(200);
    }

    public function test_a_collections_key_cannot_create_payouts(): void
    {
        [, $headers] = $this->scopedKey(['collections:write']);

        $this->postJson('/v1/collections', $this->collectionPayload(), $headers)->assertStatus(201);
        $this->postJson('/v1/payouts', [
            'amount' => 100, 'currency' => 'GHS', 'phone' => '233240000000', 'network' => 'mtn', 'reference' => 'p-1',
        ], $headers)->assertStatus(403);
    }

    public function test_a_full_access_key_works_everywhere(): void
    {
        // null abilities == full access
        [, $headers] = $this->scopedKey([]);
        $merchant = Merchant::factory()->create();
        [, $secret] = ApiKey::issue($merchant, ApiKeyMode::Test, 'full', null);
        $full = ['Authorization' => "Bearer {$secret}"];

        $this->postJson('/v1/collections', $this->collectionPayload(), $full)->assertStatus(201);
    }

    public function test_security_headers_are_present(): void
    {
        [, $headers] = $this->scopedKey(['balances:read']);

        $this->getJson('/v1/balance', $headers)
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY');
    }
}
