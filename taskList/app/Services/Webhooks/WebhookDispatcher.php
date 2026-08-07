<?php

namespace App\Services\Webhooks;

use App\Models\Transaction;
use App\Models\WebhookDelivery;
use App\Support\TransactionPayload;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Delivers signed status webhooks to merchants. Every payload carries an
 * `X-Node-Signature: t=<ts>,v1=<hmac>` header so the merchant can verify
 * authenticity with their webhook secret (constant-time compare on their side).
 * A failed delivery is left `pending` with a back-off for `webhooks:flush`.
 */
class WebhookDispatcher
{
    public function dispatch(Transaction $transaction, string $event): ?WebhookDelivery
    {
        $merchant = $transaction->merchant;

        if (empty($merchant->webhook_url)) {
            return null; // merchant hasn't configured a webhook endpoint
        }

        $delivery = WebhookDelivery::create([
            'merchant_id' => $merchant->id,
            'transaction_id' => $transaction->id,
            'event' => $event,
            'payload' => [
                'id' => (string) Str::uuid(),
                'event' => $event,
                'created' => now()->toIso8601String(),
                'data' => TransactionPayload::make($transaction),
            ],
            'status' => 'pending',
        ]);

        $this->attempt($delivery);

        return $delivery;
    }

    /** Try to deliver one webhook, updating its state. Safe to call repeatedly. */
    public function attempt(WebhookDelivery $delivery): bool
    {
        $merchant = $delivery->merchant;
        $body = json_encode($delivery->payload, JSON_UNESCAPED_SLASHES);
        $timestamp = now()->timestamp;
        $signature = hash_hmac('sha256', "{$timestamp}.{$body}", (string) $merchant->webhook_secret);

        $delivery->increment('attempts');

        try {
            $response = Http::timeout(config('psp.webhooks.timeout'))
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-Node-Signature' => "t={$timestamp},v1={$signature}",
                    'X-Node-Event' => $delivery->event,
                ])
                ->withBody($body, 'application/json')
                ->post($merchant->webhook_url);

            if ($response->successful()) {
                $delivery->update([
                    'status' => 'delivered',
                    'last_response_code' => $response->status(),
                    'last_error' => null,
                    'delivered_at' => now(),
                    'next_attempt_at' => null,
                ]);

                return true;
            }

            $this->reschedule($delivery, "HTTP {$response->status()}", $response->status());

            return false;
        } catch (Throwable $e) {
            $this->reschedule($delivery, Str::limit($e->getMessage(), 250));

            return false;
        }
    }

    private function reschedule(WebhookDelivery $delivery, string $error, ?int $code = null): void
    {
        $exhausted = $delivery->attempts >= config('psp.webhooks.max_attempts');
        // Exponential back-off: 2^attempts minutes, capped implicitly by max_attempts.
        $delay = min(2 ** $delivery->attempts, 60 * 6);

        $delivery->update([
            'status' => $exhausted ? 'failed' : 'pending',
            'last_response_code' => $code,
            'last_error' => $error,
            'next_attempt_at' => $exhausted ? null : now()->addMinutes($delay),
        ]);
    }
}
