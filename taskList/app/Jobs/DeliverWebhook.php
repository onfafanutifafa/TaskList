<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Services\Webhooks\WebhookDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Delivers one merchant webhook off the request path. Retry is handled by the
 * delivery's own DB back-off (see WebhookDispatcher::attempt + webhooks:flush),
 * so this job attempts once and never throws on a bad HTTP response.
 */
class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $deliveryId)
    {
        $this->onQueue('webhooks');
    }

    public function handle(WebhookDispatcher $dispatcher): void
    {
        $delivery = WebhookDelivery::find($this->deliveryId);

        if (! $delivery || $delivery->status === 'delivered') {
            return;
        }

        $dispatcher->attempt($delivery);
    }
}
