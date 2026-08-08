<?php

namespace Tests\Feature;

use App\Enums\TransactionStatus;
use App\Models\Merchant;
use App\Providers\Banking\BankingProviderManager;
use App\Providers\Banking\Fake\FakeBankingProvider;
use App\Providers\MobileMoney\Contracts\ProviderStatus;
use App\Services\Ledger\AccountResolver;
use App\Services\Ledger\JournalLeg;
use App\Services\Ledger\LedgerService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BankPayoutTest extends TestCase
{
    use RefreshDatabase;

    private FakeBankingProvider $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bank = app(BankingProviderManager::class)->fake();
    }

    private function auth(): array
    {
        [$merchant, $secret] = Merchant::factory()->withApiKey();

        return [$merchant, ['Authorization' => "Bearer {$secret}"]];
    }

    private function fundUsd(Merchant $merchant, int $minor): void
    {
        $accounts = app(AccountResolver::class);
        app(LedgerService::class)->post([
            JournalLeg::debit($accounts->bankFloat('USD'), Money::of($minor, 'USD')),
            JournalLeg::credit($accounts->merchantPayable($merchant, 'USD'), Money::of($minor, 'USD')),
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'amount' => 50000, // $500.00
            'currency' => 'USD',
            'reference' => 'bp-1',
            'beneficiary' => [
                'account_name' => 'Jane Supplier',
                'account_number' => '12345678',
                'bank_name' => 'Chase',
                'routing_number' => '021000021',
            ],
        ], $overrides);
    }

    public function test_bank_payout_is_rejected_when_balance_is_insufficient(): void
    {
        [, $headers] = $this->auth();

        $this->postJson('/v1/bank-payouts', $this->payload(), $headers)->assertStatus(422);
    }

    public function test_bank_payout_draws_down_the_balance_on_success(): void
    {
        [$merchant, $headers] = $this->auth();
        $this->fundUsd($merchant, 100000); // $1,000

        $id = $this->postJson('/v1/bank-payouts', $this->payload(), $headers)
            ->assertStatus(201)
            ->assertJsonPath('data.type', 'bank_payout')
            ->assertJsonPath('data.status', 'processing')
            ->assertJsonPath('data.beneficiary.account_name', 'Jane Supplier')
            ->json('data.id');

        // Poll settles it (fake resolves to successful).
        $this->artisan('bank:poll-payouts')->assertSuccessful();

        $this->assertSame(TransactionStatus::Succeeded, \App\Models\Transaction::find($id)->status);
        $this->assertSame(50000, app(LedgerService::class)->merchantBalance($merchant, 'USD')->minor);
    }

    public function test_inflight_bank_payout_reduces_available_balance(): void
    {
        [$merchant, $headers] = $this->auth();
        $this->fundUsd($merchant, 100000); // $1,000

        // First payout ($600) leaves $400 available.
        $this->postJson('/v1/bank-payouts', $this->payload(['amount' => 60000, 'reference' => 'bp-a']), $headers)
            ->assertStatus(201);

        // Second payout ($500) must be rejected while the first is still in flight.
        $this->postJson('/v1/bank-payouts', $this->payload(['amount' => 50000, 'reference' => 'bp-b']), $headers)
            ->assertStatus(422);
    }

    public function test_a_provider_rejection_moves_no_money(): void
    {
        [$merchant, $headers] = $this->auth();
        $this->fundUsd($merchant, 100000);
        $this->bank->payoutInitiationSucceeds = false;

        $this->postJson('/v1/bank-payouts', $this->payload(), $headers)
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'failed');

        $this->assertSame(100000, app(LedgerService::class)->merchantBalance($merchant, 'USD')->minor);
    }
}
