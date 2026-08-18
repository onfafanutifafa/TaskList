<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Services\Fraud\MasenuClient;
use App\Services\Fraud\RiskDecision;
use App\Providers\MobileMoney\ProviderManager;
use App\Services\Ledger\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FraudScreeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ProviderManager::class)->fake();
    }

    private function auth(): array
    {
        [$merchant, $secret] = Merchant::factory()->withApiKey();

        return [$merchant, ['Authorization' => "Bearer {$secret}"]];
    }

    private function collectionBody(array $o = []): array
    {
        return array_merge([
            'amount' => 1050, 'currency' => 'GHS', 'phone' => '233240000000',
            'network' => 'mtn', 'reference' => 'f-1',
        ], $o);
    }

    public function test_a_blocked_payer_is_declined_and_no_transaction_is_created(): void
    {
        [$merchant, $headers] = $this->auth();
        app(MasenuClient::class)->force(RiskDecision::block('known fraud ring', 96));

        $this->postJson('/v1/collections', $this->collectionBody(), $headers)
            ->assertStatus(422)
            ->assertJsonPath('error.type', 'fraud_blocked')
            ->assertJsonPath('error.risk.action', 'block');

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_an_allowed_payer_goes_through(): void
    {
        [, $headers] = $this->auth();
        app(MasenuClient::class)->force(RiskDecision::allow());

        $this->postJson('/v1/collections', $this->collectionBody(), $headers)->assertStatus(201);
    }

    public function test_disabled_by_default_lets_everything_through(): void
    {
        [, $headers] = $this->auth();
        // No force() and MASENU_ENABLED=false (phpunit): assess() allows.

        $this->postJson('/v1/collections', $this->collectionBody(), $headers)->assertStatus(201);
    }

    public function test_a_blocked_recipient_stops_a_payout_before_reserving(): void
    {
        [$merchant, $headers] = $this->auth();
        // give the merchant a balance so only the fraud check can stop the payout
        $accounts = app(\App\Services\Ledger\AccountResolver::class);
        app(LedgerService::class)->post([
            \App\Services\Ledger\JournalLeg::debit($accounts->momoFloat('GHS'), \App\Support\Money::of(100000, 'GHS')),
            \App\Services\Ledger\JournalLeg::credit($accounts->merchantPayable($merchant, 'GHS'), \App\Support\Money::of(100000, 'GHS')),
        ]);
        app(MasenuClient::class)->force(RiskDecision::block('mule account', 90));

        $this->postJson('/v1/payouts', [
            'amount' => 5000, 'currency' => 'GHS', 'phone' => '233240000001', 'network' => 'mtn', 'reference' => 'p-f',
        ], $headers)->assertStatus(422)->assertJsonPath('error.type', 'fraud_blocked');

        // Nothing reserved, balance intact.
        $this->assertSame(100000, app(LedgerService::class)->merchantBalance($merchant, 'GHS')->minor);
    }

    public function test_it_maps_a_real_masenu_response_and_hashes_the_entity(): void
    {
        Config::set('psp.fraud.enabled', true);
        Config::set('psp.fraud.base_url', 'https://masenu.test');
        Config::set('psp.fraud.pepper', 'pep');
        Http::fake(['masenu.test/*' => Http::response([
            'risk_score' => 91, 'risk_band' => 'critical', 'recommended_action' => 'block',
        ], 200)]);

        $decision = app(MasenuClient::class)->assess('233240000000', ['channel' => 'collection']);

        $this->assertTrue($decision->isBlocked());
        Http::assertSent(function ($request) {
            // raw MSISDN never sent; a hash is
            return $request->url() === 'https://masenu.test/v1/lookups'
                && $request['value_hashed'] === hash_hmac('sha256', '233240000000', 'pep')
                && ! str_contains(json_encode($request->data()), '233240000000');
        });
    }
}
