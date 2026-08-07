<?php

namespace App\Console\Commands;

use App\Models\WebhookDelivery;
use App\Services\Webhooks\WebhookDispatcher;
use Illuminate\Console\Command;

class FlushWebhooks extends Command
{
    protected $signature = 'webhooks:flush {--limit=200}';

    protected $description = 'Retry pending merchant webhook deliveries that are due';

    public function handle(WebhookDispatcher $dispatcher): int
    {
        $due = WebhookDelivery::where('status', 'pending')
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->limit((int) $this->option('limit'))
            ->get();

        $delivered = 0;

        foreach ($due as $delivery) {
            if ($dispatcher->attempt($delivery)) {
                $delivered++;
            }
        }

        $this->info("Attempted {$due->count()} webhook(s); {$delivered} delivered.");

        return self::SUCCESS;
    }
}
