<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use App\Models\Merchant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes money-moving POSTs safe to retry. When the client sends an
 * `Idempotency-Key` header:
 *   - first time  -> a locked record is created, the request runs, and its
 *                    response is stored and replayed on any later retry;
 *   - retry (same body)   -> the stored response is replayed, nothing re-runs;
 *   - retry (different body) -> 422, because the key was reused for a new request;
 *   - still in flight        -> 409.
 * With no header the request proceeds normally (dedup is opt-in but recommended).
 */
class EnforceIdempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        if (! $key) {
            return $next($request);
        }

        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('merchant');
        $requestHash = hash('sha256', $request->getMethod().$request->path().$request->getContent());

        $existing = IdempotencyKey::where('merchant_id', $merchant->id)->where('key', $key)->first();

        if ($existing) {
            if ($existing->request_hash !== $requestHash) {
                return response()->json([
                    'error' => [
                        'type' => 'idempotency_error',
                        'message' => 'This Idempotency-Key was already used with a different request body.',
                    ],
                ], 422);
            }

            if ($existing->status === 'completed') {
                return response($existing->response_body ?? '', $existing->response_code ?? 200)
                    ->header('Content-Type', 'application/json')
                    ->header('Idempotent-Replayed', 'true');
            }

            return response()->json([
                'error' => ['type' => 'idempotency_error', 'message' => 'A request with this key is still being processed.'],
            ], 409);
        }

        $record = IdempotencyKey::create([
            'merchant_id' => $merchant->id,
            'key' => $key,
            'method' => $request->getMethod(),
            'path' => $request->path(),
            'request_hash' => $requestHash,
            'status' => 'locked',
        ]);

        $request->attributes->set('idempotency_key', $key);

        /** @var HttpResponse $response */
        $response = $next($request);

        // Persist successful and client-error responses so retries are stable;
        // leave 5xx unrecorded so a genuine retry can try again.
        if ($response->getStatusCode() < 500) {
            $record->update([
                'status' => 'completed',
                'response_code' => $response->getStatusCode(),
                'response_body' => $response->getContent(),
            ]);
        } else {
            $record->delete();
        }

        return $response;
    }
}
