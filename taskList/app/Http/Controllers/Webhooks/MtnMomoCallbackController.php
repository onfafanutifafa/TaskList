<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ReconcileTransaction;
use App\Models\ProviderCallback;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Inbound MTN MoMo status callbacks. The body is NOT trusted to move money:
 * receiving one only enqueues an authoritative status poll (GET) against MTN,
 * which a worker runs. So a spoofed callback is harmless (worst case one status
 * call) AND the request returns immediately instead of blocking on MTN.
 */
class MtnMomoCallbackController extends Controller
{
    public function collection(Request $request, string $reference): JsonResponse
    {
        return $this->handle($request, $reference, 'collection');
    }

    public function disbursement(Request $request, string $reference): JsonResponse
    {
        return $this->handle($request, $reference, 'disbursement');
    }

    private function handle(Request $request, string $reference, string $product): JsonResponse
    {
        ProviderCallback::create([
            'provider' => 'mtn_momo',
            'product' => $product,
            'reference' => $reference,
            'payload' => $request->all(),
            'processed_at' => now(),
        ]);

        $transaction = Transaction::where('id', $reference)->where('provider', 'mtn_momo')->first();

        if ($transaction && ! $transaction->status->isTerminal()) {
            ReconcileTransaction::dispatch($transaction->id);
        }

        // Always 200 so the provider doesn't hammer us with retries.
        return response()->json(['received' => true]);
    }
}
