<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Services\Ledger\AccountResolver;
use App\Services\Ledger\JournalLeg;
use App\Services\Ledger\LedgerService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class LedgerTest extends TestCase
{
    use RefreshDatabase;

    private function ledger(): LedgerService
    {
        return app(LedgerService::class);
    }

    private function accounts(): AccountResolver
    {
        return app(AccountResolver::class);
    }

    public function test_a_balanced_journal_posts_and_moves_the_balance(): void
    {
        $merchant = Merchant::factory()->create();
        $float = $this->accounts()->momoFloat('GHS');
        $payable = $this->accounts()->merchantPayable($merchant, 'GHS');

        $this->ledger()->post([
            JournalLeg::debit($float, Money::of(1000, 'GHS')),
            JournalLeg::credit($payable, Money::of(1000, 'GHS')),
        ]);

        $this->assertSame(1000, $this->ledger()->accountBalance($float)->minor);
        $this->assertSame(1000, $this->ledger()->merchantBalance($merchant, 'GHS')->minor);
    }

    public function test_an_unbalanced_journal_is_rejected(): void
    {
        $merchant = Merchant::factory()->create();

        $this->expectException(RuntimeException::class);

        $this->ledger()->post([
            JournalLeg::debit($this->accounts()->momoFloat('GHS'), Money::of(1000, 'GHS')),
            JournalLeg::credit($this->accounts()->merchantPayable($merchant, 'GHS'), Money::of(999, 'GHS')),
        ]);

        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_mixed_currency_journal_is_rejected(): void
    {
        $merchant = Merchant::factory()->create();

        $this->expectException(RuntimeException::class);

        $this->ledger()->post([
            JournalLeg::debit($this->accounts()->momoFloat('GHS'), Money::of(1000, 'GHS')),
            JournalLeg::credit($this->accounts()->merchantPayable($merchant, 'KES'), Money::of(1000, 'KES')),
        ]);
    }
}
