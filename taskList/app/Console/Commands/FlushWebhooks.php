<?php

namespace App\Console\Commands;

use App\Jobs\DeliverWebhook;
use App\Models\WebhookDelivery;
use Illuminate\Console\Command;

class FlushWebhooks extends Command
{
    protected $signature = 'webhooks:flush {--limit=500}';

    protected $description = 'Re-enqueue pending merchant webhook deliveries that are due for retry';

    public function handle(): int
    {
        $due = WebhookDelivery::where('status', 'pending')
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->limit((int) $this->option('limit'))
            ->pluck('id');

        $due->each(fn (string $id) => DeliverWebhook::dispatch($id));

        $this->info("Re-enqueued {$due->count()} due webhook(s).");

        return self::SUCCESS;
    }
}
