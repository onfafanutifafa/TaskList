<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Services\Fx\FakeRateProvider;
use App\Services\Fx\RateProviderManager;
use App\Services\Ledger\AccountResolver;
use App\Services\Ledger\JournalLeg;
use App\Services\Ledger\LedgerService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class FxConversionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 1 USDT = 15.00 GHS, 1% spread.
        app(RateProviderManager::class)->fake(new FakeRateProvider(['USDT:GHS' => '15.00']));
        Config::set('psp.fx.spread_bps', 100);
    }

    private function auth(): array
    {
        [$merchant, $secret] = Merchant::factory()->withApiKey();

        return [$merchant, ['Authorization' => "Bearer {$secret}"]];
    }

    /** Fund a merchant's USDT wallet directly via a balanced journal. */
    private function fundUsdt(Merchant $merchant, int $minor): void
    {
        $accounts = app(AccountResolver::class);
        app(LedgerService::class)->post([
            JournalLeg::debit($accounts->cryptoFloat('USDT'), Money::of($minor, 'USDT')),
            JournalLeg::credit($accounts->merchantPayable($merchant, 'USDT'), Money::of($minor, 'USDT')),
        ]);
    }

    public function test_quote_applies_rate_and_spread(): void
    {
        [, $headers] = $this->auth();

        // 10 USDT (10_000_000 minor) * 15 = 150 GHS gross (15_000 minor); 1% spread = 150; net = 14_850.
        $this->postJson('/v1/fx/quote', ['amount' => 10000000, 'from' => 'USDT', 'to' => 'GHS'], $headers)
            ->assertStatus(200)
            ->assertJsonPath('data.gross_amount', 15000)
            ->assertJsonPath('data.spread', 150)
            ->assertJsonPath('data.to_amount', 14850);
    }

    public function test_conversion_moves_balances_and_keeps_the_ledger_balanced(): void
    {
        [$merchant, $headers] = $this->auth();
        $this->fundUsdt($merchant, 10000000); // 10 USDT

        $this->postJson('/v1/fx/conversions', ['amount' => 10000000, 'from' => 'USDT', 'to' => 'GHS'], $headers)
            ->assertStatus(201)
            ->assertJsonPath('data.from_amount', 10000000)
            ->assertJsonPath('data.to_amount', 14850);

        $ledger = app(LedgerService::class);
        $this->assertSame(0, $ledger->merchantBalance($merchant, 'USDT')->minor);
        $this->assertSame(14850, $ledger->merchantBalance($merchant, 'GHS')->minor);

        // Every single-currency journal balances; check GHS side sums to zero net.
        $ghsDebit = (int) \App\Models\LedgerEntry::where('currency', 'GHS')->where('direction', 'debit')->sum('amount_minor');
        $ghsCredit = (int) \App\Models\LedgerEntry::where('currency', 'GHS')->where('direction', 'credit')->sum('amount_minor');
        $this->assertSame($ghsDebit, $ghsCredit);
        $usdtDebit = (int) \App\Models\LedgerEntry::where('currency', 'USDT')->where('direction', 'debit')->sum('amount_minor');
        $usdtCredit = (int) \App\Models\LedgerEntry::where('currency', 'USDT')->where('direction', 'credit')->sum('amount_minor');
        $this->assertSame($usdtDebit, $usdtCredit);
    }

    public function test_conversion_rejected_when_source_balance_is_insufficient(): void
    {
        [$merchant, $headers] = $this->auth();
        $this->fundUsdt($merchant, 1000000); // only 1 USDT

        $this->postJson('/v1/fx/conversions', ['amount' => 10000000, 'from' => 'USDT', 'to' => 'GHS'], $headers)
            ->assertStatus(422);

        $this->assertSame(1000000, app(LedgerService::class)->merchantBalance($merchant, 'USDT')->minor);
        $this->assertSame(0, app(LedgerService::class)->merchantBalance($merchant, 'GHS')->minor);
    }

    public function test_fx_revenue_captures_the_spread(): void
    {
        [$merchant, $headers] = $this->auth();
        $this->fundUsdt($merchant, 10000000);

        $this->postJson('/v1/fx/conversions', ['amount' => 10000000, 'from' => 'USDT', 'to' => 'GHS'], $headers)
            ->assertStatus(201);

        $fxRevenue = app(LedgerService::class)->accountBalance(app(AccountResolver::class)->fxRevenue('GHS'));
        $this->assertSame(150, $fxRevenue->minor);
    }
}
