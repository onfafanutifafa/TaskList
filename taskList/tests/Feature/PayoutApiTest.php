<?php

namespace Tests\Feature;

use App\Enums\TransactionStatus;
use App\Models\Merchant;
use App\Models\Transaction;
use App\Providers\MobileMoney\Contracts\ProviderStatus;
use App\Providers\MobileMoney\Fake\FakeProvider;
use App\Providers\MobileMoney\ProviderManager;
use App\Services\Ledger\AccountResolver;
use App\Services\Ledger\JournalLeg;
use App\Services\Ledger\LedgerService;
use App\Services\Transactions\TransactionReconciler;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayoutApiTest extends TestCase
{
    use RefreshDatabase;

    private FakeProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = app(ProviderManager::class)->fake();
    }

    /** Give a merchant a settled balance by posting a collection-style journal. */
    private function fund(Merchant $merchant, int $minor): void
    {
        $accounts = app(AccountResolver::class);
        app(LedgerService::class)->post([
            JournalLeg::debit($accounts->momoFloat('GHS'), Money::of($minor, 'GHS')),
            JournalLeg::credit($accounts->merchantPayable($merchant, 'GHS'), Money::of($minor, 'GHS')),
        ]);
    }

    private function auth(): array
    {
        [$merchant, $secret] = Merchant::factory()->withApiKey();

        return [$merchant, ['Authorization' => "Bearer {$secret}"]];
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'amount' => 500,
            'currency' => 'GHS',
            'phone' => '233240000000',
            'network' => 'mtn',
            'reference' => 'payout-1',
        ], $overrides);
    }

    public function test_payout_is_rejected_when_balance_is_insufficient(): void
    {
        [, $headers] = $this->auth();

        $this->postJson('/v1/payouts', $this->payload(['amount' => 5000]), $headers)
            ->assertStatus(422);

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_payout_draws_down_the_merchant_balance_on_success(): void
    {
        [$merchant, $headers] = $this->auth();
        $this->fund($merchant, 2000);
        $this->provider->resolvesTo = ProviderStatus::Successful;

        $id = $this->postJson('/v1/payouts', $this->payload(['amount' => 500]), $headers)
            ->assertStatus(201)->json('data.id');

        app(TransactionReconciler::class)->poll(Transaction::find($id));

        $this->assertSame(TransactionStatus::Succeeded, Transaction::find($id)->status);
        $this->assertSame(1500, app(LedgerService::class)->merchantBalance($merchant, 'GHS')->minor);
    }

    public function test_in_flight_payout_reduces_available_balance(): void
    {
        [$merchant, $headers] = $this->auth();
        $this->fund($merchant, 1000);

        // First payout leaves it Processing (fake stays pending until polled).
        $this->provider->resolvesTo = ProviderStatus::Pending;
        $this->postJson('/v1/payouts', $this->payload(['amount' => 700, 'reference' => 'p1']), $headers)
            ->assertStatus(201);

        // Only 300 remains available, so a 500 payout must be refused.
        $this->postJson('/v1/payouts', $this->payload(['amount' => 500, 'reference' => 'p2']), $headers)
            ->assertStatus(422);
    }
}
