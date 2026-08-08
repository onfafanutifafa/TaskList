<?php

namespace Tests\Feature;

use App\Models\BalanceReservation;
use App\Models\Merchant;
use App\Services\Ledger\AccountResolver;
use App\Services\Ledger\JournalLeg;
use App\Services\Ledger\LedgerService;
use App\Services\Transactions\BalanceService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Tests\TestCase;

class BalanceReservationTest extends TestCase
{
    use RefreshDatabase;

    private function merchantWithGhs(int $minor): Merchant
    {
        $merchant = Merchant::factory()->create();
        $accounts = app(AccountResolver::class);
        app(LedgerService::class)->post([
            JournalLeg::debit($accounts->momoFloat('GHS'), Money::of($minor, 'GHS')),
            JournalLeg::credit($accounts->merchantPayable($merchant, 'GHS'), Money::of($minor, 'GHS')),
        ]);

        return $merchant;
    }

    public function test_reserve_reduces_available_and_release_restores_it(): void
    {
        $merchant = $this->merchantWithGhs(100000); // GHS 1,000
        $balances = app(BalanceService::class);

        DB::transaction(fn () => $balances->reserve($merchant, Money::of(30000, 'GHS')));
        $this->assertSame(100000, $balances->settled($merchant, 'GHS')->minor);
        $this->assertSame(70000, $balances->available($merchant, 'GHS')->minor);

        DB::transaction(fn () => $balances->release($merchant, Money::of(30000, 'GHS')));
        $this->assertSame(100000, $balances->available($merchant, 'GHS')->minor);
    }

    public function test_reserving_more_than_available_throws_and_holds_nothing(): void
    {
        $merchant = $this->merchantWithGhs(50000); // GHS 500
        $balances = app(BalanceService::class);

        $this->expectException(UnprocessableEntityHttpException::class);

        try {
            DB::transaction(fn () => $balances->reserve($merchant, Money::of(60000, 'GHS')));
        } finally {
            $this->assertSame(0, (int) BalanceReservation::where('merchant_id', $merchant->id)->value('reserved_minor'));
            $this->assertSame(50000, $balances->available($merchant, 'GHS')->minor);
        }
    }

    public function test_sequential_reserves_cannot_exceed_the_balance(): void
    {
        // The guarantee two concurrent spenders rely on: the second reserve that
        // would breach the balance is rejected once the first hold is in place.
        $merchant = $this->merchantWithGhs(100000);
        $balances = app(BalanceService::class);

        DB::transaction(fn () => $balances->reserve($merchant, Money::of(70000, 'GHS')));

        $this->expectException(UnprocessableEntityHttpException::class);
        DB::transaction(fn () => $balances->reserve($merchant, Money::of(40000, 'GHS')));
    }

    public function test_a_failed_payout_releases_the_hold(): void
    {
        $merchant = $this->merchantWithGhs(100000);
        [, $secret] = $this->issueKey($merchant);
        app(\App\Providers\MobileMoney\ProviderManager::class)->fake()->initiationSucceeds = false;
        $balances = app(BalanceService::class);

        $this->postJson('/v1/payouts', [
            'amount' => 40000, 'currency' => 'GHS', 'phone' => '233240000000', 'network' => 'mtn', 'reference' => 'p-fail',
        ], ['Authorization' => "Bearer {$secret}"])->assertStatus(201)->assertJsonPath('data.status', 'failed');

        // Hold released -> full balance available again.
        $this->assertSame(100000, $balances->available($merchant, 'GHS')->minor);
        $this->assertSame(0, (int) BalanceReservation::where('merchant_id', $merchant->id)->value('reserved_minor'));
    }

    private function issueKey(Merchant $merchant): array
    {
        return [$merchant, \App\Models\ApiKey::issue($merchant, \App\Enums\ApiKeyMode::Test, 'test')[1]];
    }
}
