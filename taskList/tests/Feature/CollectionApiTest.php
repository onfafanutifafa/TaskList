<?php

namespace Tests\Feature;

use App\Enums\TransactionStatus;
use App\Models\Merchant;
use App\Providers\MobileMoney\Contracts\ProviderStatus;
use App\Providers\MobileMoney\Fake\FakeProvider;
use App\Providers\MobileMoney\ProviderManager;
use App\Services\Ledger\LedgerService;
use App\Services\Transactions\TransactionReconciler;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CollectionApiTest extends TestCase
{
    use RefreshDatabase;

    private FakeProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = app(ProviderManager::class)->fake();
    }

    private function auth(): array
    {
        [$merchant, $secret] = Merchant::factory()->withApiKey();

        return [$merchant, ['Authorization' => "Bearer {$secret}"]];
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'amount' => 1050,
            'currency' => 'GHS',
            'phone' => '233240000000',
            'network' => 'mtn',
            'reference' => 'order-1001',
            'narration' => 'Order 1001',
        ], $overrides);
    }

    public function test_it_requires_an_api_key(): void
    {
        $this->postJson('/v1/collections', $this->payload())->assertStatus(401);
    }

    public function test_it_initiates_a_collection(): void
    {
        [$merchant, $headers] = $this->auth();

        $response = $this->postJson('/v1/collections', $this->payload(), $headers);

        $response->assertStatus(201)
            ->assertJsonPath('data.type', 'collection')
            ->assertJsonPath('data.status', 'processing')
            ->assertJsonPath('data.amount', 1050)
            ->assertJsonPath('data.fee', 16);

        $this->assertDatabaseHas('transactions', [
            'merchant_id' => $merchant->id,
            'reference' => 'order-1001',
            'status' => TransactionStatus::Processing->value,
        ]);
        $this->assertSame('collect', $this->provider->calls[0]['method']);
    }

    public function test_a_successful_collection_credits_the_merchant_net_of_fee(): void
    {
        [$merchant, $headers] = $this->auth();
        $this->provider->resolvesTo = ProviderStatus::Successful;

        $id = $this->postJson('/v1/collections', $this->payload(), $headers)->json('data.id');

        app(TransactionReconciler::class)->poll(Transaction::find($id));

        $this->assertSame(TransactionStatus::Succeeded, Transaction::find($id)->status);
        // gross 1050, fee 16 -> merchant nets 1034
        $this->assertSame(1034, app(LedgerService::class)->merchantBalance($merchant, 'GHS')->minor);

        // Ledger must balance: debits == credits across the journal.
        $debits = (int) \App\Models\LedgerEntry::where('direction', 'debit')->sum('amount_minor');
        $credits = (int) \App\Models\LedgerEntry::where('direction', 'credit')->sum('amount_minor');
        $this->assertSame($debits, $credits);
        $this->assertSame(1050, $debits);
    }

    public function test_a_failed_collection_moves_no_money(): void
    {
        [$merchant, $headers] = $this->auth();
        $this->provider->resolvesTo = ProviderStatus::Failed;

        $id = $this->postJson('/v1/collections', $this->payload(), $headers)->json('data.id');
        app(TransactionReconciler::class)->poll(Transaction::find($id));

        $this->assertSame(TransactionStatus::Failed, Transaction::find($id)->status);
        $this->assertSame(0, app(LedgerService::class)->merchantBalance($merchant, 'GHS')->minor);
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_it_validates_input(): void
    {
        [, $headers] = $this->auth();

        $this->postJson('/v1/collections', $this->payload(['amount' => 0]), $headers)
            ->assertStatus(422)->assertJsonPath('error.type', 'validation_error');

        $this->postJson('/v1/collections', $this->payload(['network' => 'nope']), $headers)
            ->assertStatus(422);
    }

    public function test_idempotency_key_replays_the_same_response(): void
    {
        [, $headers] = $this->auth();
        $headers['Idempotency-Key'] = 'idem-123';

        $first = $this->postJson('/v1/collections', $this->payload(), $headers);
        $second = $this->postJson('/v1/collections', $this->payload(), $headers);

        $first->assertStatus(201);
        $second->assertStatus(201);
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_a_failed_request_releases_the_idempotency_key_for_retry(): void
    {
        [, $headers] = $this->auth();
        $headers['Idempotency-Key'] = 'retry-1';

        // First attempt fails validation (thrown mid-request) -> lock must release.
        $this->postJson('/v1/collections', $this->payload(['amount' => 0]), $headers)->assertStatus(422);

        // Same key, now a valid body -> must succeed, not return a stuck 409.
        $this->postJson('/v1/collections', $this->payload(), $headers)->assertStatus(201);
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_reused_idempotency_key_with_different_body_is_rejected(): void
    {
        [, $headers] = $this->auth();
        $headers['Idempotency-Key'] = 'idem-xyz';

        $this->postJson('/v1/collections', $this->payload(), $headers)->assertStatus(201);
        $this->postJson('/v1/collections', $this->payload(['amount' => 2000]), $headers)
            ->assertStatus(422)->assertJsonPath('error.type', 'idempotency_error');
    }
}
