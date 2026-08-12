<?php

namespace Tests\Feature;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Jobs\DeliverWebhook;
use App\Jobs\ReconcileTransaction;
use App\Models\Merchant;
use App\Models\Transaction;
use App\Providers\MobileMoney\ProviderManager;
use App\Services\Webhooks\WebhookDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class QueueDispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_mtn_callback_enqueues_reconcile_instead_of_polling_inline(): void
    {
        Queue::fake();
        $merchant = Merchant::factory()->create();
        $txn = $merchant->transactions()->create([
            'type' => TransactionType::Collection, 'status' => TransactionStatus::Processing,
            'amount_minor' => 1000, 'fee_minor' => 0, 'currency' => 'GHS',
            'provider' => 'mtn_momo', 'network' => 'mtn', 'msisdn' => '233240000000', 'reference' => 'r1',
        ]);

        $this->postJson("/webhooks/mtn-momo/collection/{$txn->id}", ['status' => 'SUCCESSFUL'])
            ->assertStatus(200);

        Queue::assertPushed(ReconcileTransaction::class, fn ($job) => $job->transactionId === $txn->id);
    }

    public function test_a_terminal_transaction_callback_enqueues_nothing(): void
    {
        Queue::fake();
        $merchant = Merchant::factory()->create();
        $txn = $merchant->transactions()->create([
            'type' => TransactionType::Collection, 'status' => TransactionStatus::Succeeded,
            'amount_minor' => 1000, 'fee_minor' => 0, 'currency' => 'GHS',
            'provider' => 'mtn_momo', 'network' => 'mtn', 'msisdn' => '233240000000', 'reference' => 'r2',
        ]);

        $this->postJson("/webhooks/mtn-momo/collection/{$txn->id}", ['status' => 'SUCCESSFUL'])->assertStatus(200);

        Queue::assertNothingPushed();
    }

    public function test_a_status_change_enqueues_a_webhook_delivery(): void
    {
        Queue::fake();
        $merchant = Merchant::factory()->create(['webhook_url' => 'https://merchant.test/hook']);
        $txn = $merchant->transactions()->create([
            'type' => TransactionType::Collection, 'status' => TransactionStatus::Processing,
            'amount_minor' => 1000, 'fee_minor' => 0, 'currency' => 'GHS',
            'provider' => 'mtn_momo', 'network' => 'mtn', 'msisdn' => '233240000000', 'reference' => 'r3',
        ]);

        app(WebhookDispatcher::class)->dispatch($txn, 'transaction.succeeded');

        $this->assertDatabaseHas('webhook_deliveries', ['transaction_id' => $txn->id, 'event' => 'transaction.succeeded']);
        Queue::assertPushed(DeliverWebhook::class);
    }

    public function test_deliver_webhook_job_signs_and_posts_the_payload(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        $merchant = Merchant::factory()->create([
            'webhook_url' => 'https://merchant.test/hook', 'webhook_secret' => 'whsec_test',
        ]);
        $txn = $merchant->transactions()->create([
            'type' => TransactionType::Collection, 'status' => TransactionStatus::Succeeded,
            'amount_minor' => 1000, 'fee_minor' => 0, 'currency' => 'GHS',
            'provider' => 'mtn_momo', 'network' => 'mtn', 'msisdn' => '233240000000', 'reference' => 'r4',
        ]);

        // Sync queue: the dispatched job runs inline and performs the POST.
        app(WebhookDispatcher::class)->dispatch($txn, 'transaction.succeeded');

        Http::assertSent(fn ($request) => $request->url() === 'https://merchant.test/hook'
            && str_contains($request->header('X-Node-Signature')[0], 'v1='));
        $this->assertDatabaseHas('webhook_deliveries', ['transaction_id' => $txn->id, 'status' => 'delivered']);
    }

    public function test_poll_command_enqueues_reconcile_jobs_for_open_transactions(): void
    {
        Queue::fake();
        app(ProviderManager::class)->fake();
        $merchant = Merchant::factory()->create();
        $merchant->transactions()->create([
            'type' => TransactionType::Payout, 'status' => TransactionStatus::Processing,
            'amount_minor' => 1000, 'fee_minor' => 0, 'currency' => 'GHS',
            'provider' => 'mtn_momo', 'network' => 'mtn', 'msisdn' => '233240000000', 'reference' => 'r5',
        ]);

        $this->artisan('psp:poll-pending')->assertSuccessful();

        Queue::assertPushed(ReconcileTransaction::class, 1);
    }
}
