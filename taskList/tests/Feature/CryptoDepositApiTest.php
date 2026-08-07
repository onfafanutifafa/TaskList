<?php

namespace Tests\Feature;

use App\Enums\TransactionStatus;
use App\Models\Merchant;
use App\Models\Transaction;
use App\Providers\Crypto\CryptoProviderManager;
use App\Providers\Crypto\Fake\FakeCryptoProvider;
use App\Services\Ledger\LedgerService;
use App\Services\Transactions\CryptoDepositService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class CryptoDepositApiTest extends TestCase
{
    use RefreshDatabase;

    private FakeCryptoProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = app(CryptoProviderManager::class)->fake();
    }

    private function auth(): array
    {
        [$merchant, $secret] = Merchant::factory()->withApiKey();

        return [$merchant, ['Authorization' => "Bearer {$secret}"]];
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'amount' => 1000000,          // 1.00 USDT (6 decimals)
            'asset' => 'USDT',
            'chain' => 'tron',
            'reference' => 'dep-1',
        ], $overrides);
    }

    public function test_it_creates_a_deposit_intent_with_an_address(): void
    {
        [$merchant, $headers] = $this->auth();

        $this->postJson('/v1/crypto/deposits', $this->payload(), $headers)
            ->assertStatus(201)
            ->assertJsonPath('data.type', 'crypto_deposit')
            ->assertJsonPath('data.status', 'processing')
            ->assertJsonPath('data.currency', 'USDT')
            ->assertJsonPath('data.crypto.chain', 'tron')
            ->assertJsonPath('data.crypto.required_confirmations', 1)
            ->assertJsonPath('data.crypto.amount_expected', 1000000);

        $this->assertDatabaseHas('crypto_deposits', ['asset' => 'USDT', 'chain' => 'tron']);
    }

    public function test_it_rejects_an_unsupported_asset_chain_pair(): void
    {
        [, $headers] = $this->auth();

        $this->postJson('/v1/crypto/deposits', $this->payload(['asset' => 'USDT', 'chain' => 'base']), $headers)
            ->assertStatus(422);
    }

    public function test_a_confirmed_deposit_credits_the_merchant_net_of_fee(): void
    {
        [$merchant, $headers] = $this->auth();

        $id = $this->postJson('/v1/crypto/deposits', $this->payload(), $headers)->json('data.id');

        // Fake provider simulates the funds arriving fully confirmed on poll.
        app(CryptoDepositService::class)->poll(Transaction::find($id));

        $this->assertSame(TransactionStatus::Succeeded, Transaction::find($id)->status);
        // gross 1_000_000, fee 1.5% = 15_000 -> merchant nets 985_000 USDT (minor)
        $this->assertSame(985000, app(LedgerService::class)->merchantBalance($merchant, 'USDT')->minor);

        $debits = (int) \App\Models\LedgerEntry::where('direction', 'debit')->sum('amount_minor');
        $credits = (int) \App\Models\LedgerEntry::where('direction', 'credit')->sum('amount_minor');
        $this->assertSame($debits, $credits);
        $this->assertSame(1000000, $debits);
    }

    public function test_watcher_webhook_settles_with_a_valid_signature(): void
    {
        Config::set('psp.crypto.watcher_secret', 'shh-secret');
        [$merchant, $headers] = $this->auth();

        $id = $this->postJson('/v1/crypto/deposits', $this->payload(), $headers)->json('data.id');

        $body = json_encode([
            'amount_received_minor' => 1000000,
            'confirmations' => 1,
            'tx_hash' => '0xabc123',
        ]);
        $ts = now()->timestamp;
        $sig = hash_hmac('sha256', "{$ts}.{$body}", 'shh-secret');

        $this->call(
            'POST',
            "/webhooks/crypto/{$id}",
            [], [], [],
            ['HTTP_X-Watcher-Signature' => "t={$ts},v1={$sig}", 'CONTENT_TYPE' => 'application/json'],
            $body,
        )->assertStatus(200)->assertJsonPath('matched', true)->assertJsonPath('status', 'succeeded');

        $this->assertSame(985000, app(LedgerService::class)->merchantBalance($merchant, 'USDT')->minor);
    }

    public function test_watcher_webhook_rejects_a_bad_signature(): void
    {
        Config::set('psp.crypto.watcher_secret', 'shh-secret');
        [$merchant, $headers] = $this->auth();

        $id = $this->postJson('/v1/crypto/deposits', $this->payload(), $headers)->json('data.id');

        $body = json_encode(['amount_received_minor' => 1000000, 'confirmations' => 1, 'tx_hash' => '0xabc']);
        $ts = now()->timestamp;

        $this->call(
            'POST',
            "/webhooks/crypto/{$id}",
            [], [], [],
            ['HTTP_X-Watcher-Signature' => "t={$ts},v1=deadbeef", 'CONTENT_TYPE' => 'application/json'],
            $body,
        )->assertStatus(401);

        // No money moved.
        $this->assertSame(0, app(LedgerService::class)->merchantBalance($merchant, 'USDT')->minor);
        $this->assertSame(TransactionStatus::Processing, Transaction::find($id)->status);
    }
}
