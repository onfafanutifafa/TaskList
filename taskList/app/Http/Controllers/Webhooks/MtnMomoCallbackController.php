<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\ProviderCallback;
use App\Models\Transaction;
use App\Services\Transactions\TransactionReconciler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Inbound MTN MoMo status callbacks. The body is NOT trusted to move money:
 * receiving one only triggers an authoritative status poll (GET) against MTN.
 * This makes a spoofed callback harmless — worst case it costs one status call.
 */
class MtnMomoCallbackController extends Controller
{
    public function __construct(private readonly TransactionReconciler $reconciler) {}

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

        if ($transaction) {
            $this->reconciler->poll($transaction);
        }

        // Always 200 so the provider doesn't hammer us with retries.
        return response()->json(['received' => true]);
    }
}
