<?php

namespace Tests\Feature;

use App\Enums\TransactionStatus;
use App\Models\Merchant;
use App\Models\Transaction;
use App\Providers\Banking\BankingProviderManager;
use App\Services\Ledger\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class VirtualAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(BankingProviderManager::class)->fake();
    }

    private function auth(): array
    {
        [$merchant, $secret] = Merchant::factory()->withApiKey();

        return [$merchant, ['Authorization' => "Bearer {$secret}"]];
    }

    public function test_a_merchant_can_open_a_usd_virtual_account(): void
    {
        [$merchant, $headers] = $this->auth();

        $this->postJson('/v1/virtual-accounts', ['currency' => 'USD'], $headers)
            ->assertStatus(201)
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonStructure(['data' => ['id', 'account_number', 'bank_name']]);

        $this->assertDatabaseHas('virtual_accounts', ['merchant_id' => $merchant->id, 'currency' => 'USD']);
    }

    public function test_opening_the_same_currency_twice_returns_the_same_account(): void
    {
        [, $headers] = $this->auth();

        $first = $this->postJson('/v1/virtual-accounts', ['currency' => 'GBP'], $headers)->json('data.id');
        $second = $this->postJson('/v1/virtual-accounts', ['currency' => 'GBP'], $headers)->json('data.id');

        $this->assertSame($first, $second);
        $this->assertDatabaseCount('virtual_accounts', 1);
    }

    public function test_an_incoming_payment_credits_the_merchant_net_of_fee(): void
    {
        Config::set('psp.banking.webhook_secret', 'bank-secret');
        [$merchant, $headers] = $this->auth();

        $accountId = $this->postJson('/v1/virtual-accounts', ['currency' => 'USD'], $headers)->json('data.id');

        $body = json_encode([
            'amount_minor' => 100000,   // $1,000.00
            'currency' => 'USD',
            'payment_reference' => 'wire-abc',
            'sender_name' => 'Acme Corp',
        ]);
        $ts = now()->timestamp;
        $sig = hash_hmac('sha256', "{$ts}.{$body}", 'bank-secret');

        $this->call('POST', "/webhooks/banking/{$accountId}", [], [], [],
            ['HTTP_X-Banking-Signature' => "t={$ts},v1={$sig}", 'CONTENT_TYPE' => 'application/json'], $body)
            ->assertStatus(200)
            ->assertJsonPath('matched', true)
            ->assertJsonPath('status', 'succeeded');

        // $1,000.00 gross, 1.5% fee = $15 -> merchant nets $985.00 (98_500 minor)
        $this->assertSame(98500, app(LedgerService::class)->merchantBalance($merchant, 'USD')->minor);
    }

    public function test_duplicate_payment_webhook_does_not_double_credit(): void
    {
        Config::set('psp.banking.webhook_secret', 'bank-secret');
        [$merchant, $headers] = $this->auth();
        $accountId = $this->postJson('/v1/virtual-accounts', ['currency' => 'USD'], $headers)->json('data.id');

        $post = function () use ($accountId) {
            $body = json_encode(['amount_minor' => 100000, 'currency' => 'USD', 'payment_reference' => 'wire-dup']);
            $ts = now()->timestamp;
            $sig = hash_hmac('sha256', "{$ts}.{$body}", 'bank-secret');

            return $this->call('POST', "/webhooks/banking/{$accountId}", [], [], [],
                ['HTTP_X-Banking-Signature' => "t={$ts},v1={$sig}", 'CONTENT_TYPE' => 'application/json'], $body);
        };

        $post()->assertStatus(200);
        $post()->assertStatus(200);

        $this->assertSame(1, Transaction::where('reference', 'wire-dup')->count());
        $this->assertSame(98500, app(LedgerService::class)->merchantBalance($merchant, 'USD')->minor);
    }

    public function test_banking_webhook_rejects_a_bad_signature(): void
    {
        Config::set('psp.banking.webhook_secret', 'bank-secret');
        [$merchant, $headers] = $this->auth();
        $accountId = $this->postJson('/v1/virtual-accounts', ['currency' => 'USD'], $headers)->json('data.id');

        $body = json_encode(['amount_minor' => 100000, 'currency' => 'USD', 'payment_reference' => 'wire-x']);
        $ts = now()->timestamp;

        $this->call('POST', "/webhooks/banking/{$accountId}", [], [], [],
            ['HTTP_X-Banking-Signature' => "t={$ts},v1=deadbeef", 'CONTENT_TYPE' => 'application/json'], $body)
            ->assertStatus(401);

        $this->assertSame(0, app(LedgerService::class)->merchantBalance($merchant, 'USD')->minor);
    }
}
